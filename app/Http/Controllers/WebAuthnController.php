<?php

namespace App\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Actions\AuthenticatePasskeyAction;
use App\Domains\Members\Actions\RegisterPasskeyAction;
use App\Domains\Members\Auth\WebAuthnService;
use App\Domains\Members\Exceptions\PasskeyRejected;
use App\Domains\Members\Repositories\PasskeyRepository;
use App\Domains\Members\Support\WebAuthnChallenges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * WebAuthn (biometrické přihlášení / passkeys) pro klasické i Field UI.
 * Passkey zaregistrovaný jednou funguje na /login i /field/login (rpId je
 * doména). Logika v app/Domains/Members; tady HTTP a session.
 */
class WebAuthnController extends Controller
{
    public function __construct(private WebAuthnService $svc, private PasskeyRepository $passkeys) {}

    private function uvRequired(): bool
    {
        return $this->svc->userVerification() === 'required';
    }

    private function rejected(PasskeyRejected $e): JsonResponse
    {
        return response()->json(['error' => $e->getMessage()], 422);
    }

    // ── Správa zařízení (přihlášený uživatel) ───────────────────────────────────

    public function index()
    {
        return view('webauthn.index', ['credentials' => $this->passkeys->forUser((int) Auth::id())]);
    }

    /**
     * Přidání passkey vyžaduje aktuální heslo — jinak by si kdokoli s cizí
     * (ukradenou) session přidal vlastní klíč a ten by přežil i reset hesla.
     */
    public function registerOptions(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_if(!$user, 401);

        if (!Hash::check((string) $request->input('password', ''), (string) $user->getAuthPassword())) {
            logger()->warning('auth.webauthn.register_bad_password', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return response()->json(['error' => 'Nesprávné heslo.'], 422);
        }

        $webAuthn = $this->svc->make();
        $args = $webAuthn->getCreateArgs(
            (string) $user->id,
            (string) $user->login,
            $user->full_name ?: (string) $user->login,
            60,                              // timeout s
            true,                            // requireResidentKey → discoverable (usernameless login)
            $this->uvRequired(),
            null,                            // platform i security key
            $this->passkeys->rawIds((int) $user->id)   // zákaz dvojí registrace
        );

        return response()->json(['publicKey' => $args->publicKey, 'state' => WebAuthnChallenges::stash($webAuthn->getChallenge()->getBinaryString(), WebAuthnChallenges::forRegistration((int) $user->id))]);
    }

    public function register(Request $request, RegisterPasskeyAction $register): JsonResponse
    {
        $user = Auth::user();
        abort_if(!$user, 401);

        $data = $request->validate([
            'device_name'       => ['nullable', 'string', 'max:100'],
            'state'             => ['required', 'string'],
            'clientDataJSON'    => ['required', 'string'],
            'attestationObject' => ['required', 'string'],
        ]);

        try {
            $message = $register->handle((int) $user->id, $data['state'], $data['clientDataJSON'], $data['attestationObject'], $data['device_name'] ?? null);
        } catch (PasskeyRejected $e) {
            return $this->rejected($e);
        }
        return response()->json($message === null ? ['ok' => true] : ['ok' => true, 'message' => $message]);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->passkeys->deleteOwn($id, Auth::id());
        if ($deleted) {
            AuditLogger::log('deleted', 'webauthn_credentials', $id, ['user_id' => Auth::id()], null);
        }
        return response()->json(['ok' => $deleted]);
    }

    // ── Login flow (host) ────────────────────────────────────────────────────────

    /**
     * Výzva k přihlášení je vždy bez loginu (discoverable passkeys — prohlížeč
     * nabídne uložené klíče). Parametr `login` se ignoruje: cílená výzva by
     * prozradila, zda účet existuje a má passkey (enumerace účtů).
     */
    public function loginOptions(Request $request): JsonResponse
    {
        $webAuthn = $this->svc->make();
        $args = $webAuthn->getGetArgs([], 60, true, true, true, true, true, $this->uvRequired());

        return response()->json(['publicKey' => $args->publicKey, 'state' => WebAuthnChallenges::stash($webAuthn->getChallenge()->getBinaryString(), 'login')]);
    }

    public function login(Request $request, AuthenticatePasskeyAction $authenticate): JsonResponse
    {
        $data = $request->validate([
            'id'                => ['required', 'string'], // base64 raw credential ID
            'state'             => ['required', 'string'],
            'clientDataJSON'    => ['required', 'string'],
            'authenticatorData' => ['required', 'string'],
            'signature'         => ['required', 'string'],
            'context'           => ['nullable', 'string'],
        ]);

        try {
            $user = $authenticate->handle($data['id'], $data['state'], $data['clientDataJSON'], $data['authenticatorData'], $data['signature'], $request->ip());
        } catch (PasskeyRejected $e) {
            return $this->rejected($e);
        }

        Auth::login($user);
        $request->session()->regenerate();
        // klíč/biometrie je sám o sobě silný faktor → splněné MFA
        $request->session()->put('mfa_passed', true);

        return response()->json([
            'ok'       => true,
            'redirect' => ($data['context'] ?? null) === 'field' ? route('field.search') : route('dashboard'),
            'login'    => $user->login,
        ]);
    }
}
