<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\WebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use lbuchs\WebAuthn\Binary\ByteBuffer;

/**
 * WebAuthn (biometrické přihlášení / passkeys) pro klasické i Field UI.
 *
 * Credentials jsou sdílené napříč oběma logiy — passkey zaregistrovaný jednou
 * funguje na /login i /field/login (rpId je doména, ne cesta). Vlastní auth
 * (Auth::login) navazuje na existující 'web' guard, takže session je shodná
 * s heslovým přihlášením.
 */
class WebAuthnController extends Controller
{
    private const SESS_REG   = 'webauthn_register_challenge';
    private const SESS_LOGIN = 'webauthn_login_challenge';

    public function __construct(private WebAuthnService $svc) {}

    private function uvRequired(): bool
    {
        return $this->svc->userVerification() === 'required';
    }

    // ── Správa zařízení (přihlášený uživatel) ───────────────────────────────────

    public function index()
    {
        $credentials = WebauthnCredential::where('user_id', Auth::id())
            ->orderBy('created_at')
            ->get();

        return view('webauthn.index', ['credentials' => $credentials]);
    }

    // ── Registrace (uživatel je přihlášený) ─────────────────────────────────────

    public function registerOptions(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_if(!$user, 401);

        $webAuthn = $this->svc->make();

        // Vyřaď už registrované authenticatory (zákaz dvojí registrace).
        $exclude = WebauthnCredential::where('user_id', $user->id)
            ->pluck('credential_id')
            ->map(fn($b64) => base64_decode($b64))
            ->filter()
            ->values()
            ->all();

        $args = $webAuthn->getCreateArgs(
            (string) $user->id,
            (string) $user->login,
            $user->full_name ?: (string) $user->login,
            60,                 // timeout s
            false,              // requireResidentKey (server-side credential stačí)
            $this->uvRequired(),// requireUserVerification
            null,               // crossPlatformAttachment (platform i security key)
            $exclude
        );

        $request->session()->put(self::SESS_REG, base64_encode($webAuthn->getChallenge()->getBinaryString()));

        return response()->json($args);
    }

    public function register(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_if(!$user, 401);

        $data = $request->validate([
            'device_name'       => ['nullable', 'string', 'max:100'],
            'clientDataJSON'    => ['required', 'string'],
            'attestationObject' => ['required', 'string'],
        ]);

        $challengeB64 = $request->session()->pull(self::SESS_REG);
        if (!$challengeB64) {
            return response()->json(['error' => 'Vypršela platnost výzvy. Zkuste to znovu.'], 422);
        }

        try {
            $webAuthn = $this->svc->make();
            $result = $webAuthn->processCreate(
                base64_decode($data['clientDataJSON']),
                base64_decode($data['attestationObject']),
                new ByteBuffer(base64_decode($challengeB64)),
                $this->uvRequired(), // requireUserVerification
                true,                // requireUserPresent
                false                // failIfRootMismatch — attestation nevyžadujeme
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Registraci se nepodařilo ověřit: ' . $e->getMessage()], 422);
        }

        $credentialIdB64 = base64_encode(
            $result->credentialId instanceof ByteBuffer
                ? $result->credentialId->getBinaryString()
                : $result->credentialId
        );

        // Idempotence: stejný authenticator nepřidávej dvakrát.
        if (WebauthnCredential::where('credential_id', $credentialIdB64)->exists()) {
            return response()->json(['ok' => true, 'message' => 'Toto zařízení už je registrované.']);
        }

        WebauthnCredential::create([
            'user_id'       => $user->id,
            'credential_id' => $credentialIdB64,
            'public_key'    => $result->credentialPublicKey,
            'sign_count'    => (int) ($result->signatureCounter ?? 0),
            'device_name'   => trim($data['device_name'] ?? '') ?: 'Neznámé zařízení',
            'created_at'    => now(),
            'last_used_at'  => null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = WebauthnCredential::where('id', $id)
            ->where('user_id', Auth::id())
            ->delete();

        return response()->json(['ok' => (bool) $deleted]);
    }

    // ── Login flow (host) ────────────────────────────────────────────────────────

    public function check(Request $request): JsonResponse
    {
        $login = trim((string) $request->input('login', ''));
        if ($login === '') {
            return response()->json(['hasCredentials' => false]);
        }

        $user = User::where('login', $login)->first();
        $has  = $user && WebauthnCredential::where('user_id', $user->id)->exists();

        return response()->json(['hasCredentials' => (bool) $has]);
    }

    public function loginOptions(Request $request): JsonResponse
    {
        $login = trim((string) $request->input('login', ''));
        $user  = $login !== '' ? User::where('login', $login)->first() : null;

        $credIds = $user
            ? WebauthnCredential::where('user_id', $user->id)
                ->pluck('credential_id')
                ->map(fn($b64) => base64_decode($b64))
                ->filter()->values()->all()
            : [];

        if (empty($credIds)) {
            return response()->json(['error' => 'Pro tento účet není registrované žádné biometrické zařízení.'], 422);
        }

        $webAuthn = $this->svc->make();
        $args = $webAuthn->getGetArgs(
            $credIds,
            60,                  // timeout
            true, true, true, true, true, // allow USB/NFC/BLE/Hybrid/Internal
            $this->uvRequired()
        );

        $request->session()->put(self::SESS_LOGIN, base64_encode($webAuthn->getChallenge()->getBinaryString()));

        return response()->json($args);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'                => ['required', 'string'], // base64 raw credential ID
            'clientDataJSON'    => ['required', 'string'],
            'authenticatorData' => ['required', 'string'],
            'signature'         => ['required', 'string'],
            'context'           => ['nullable', 'string'],
        ]);

        $challengeB64 = $request->session()->pull(self::SESS_LOGIN);
        if (!$challengeB64) {
            return response()->json(['error' => 'Vypršela platnost výzvy. Zkuste to znovu.'], 422);
        }

        $credential = WebauthnCredential::where('credential_id', $data['id'])->first();
        if (!$credential) {
            return response()->json(['error' => 'Neznámé biometrické zařízení.'], 422);
        }

        try {
            $webAuthn = $this->svc->make();
            $webAuthn->processGet(
                base64_decode($data['clientDataJSON']),
                base64_decode($data['authenticatorData']),
                base64_decode($data['signature']),
                $credential->public_key,
                new ByteBuffer(base64_decode($challengeB64)),
                $credential->sign_count > 0 ? $credential->sign_count : null,
                $this->uvRequired(),
                true
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Ověření se nezdařilo: ' . $e->getMessage()], 422);
        }

        // Detekce klonovaného authenticatoru: nový čítač musí být > uloženého
        // (pokud authenticator čítač vůbec používá, tj. > 0).
        $newCount = (int) $webAuthn->getSignatureCounter();
        if ($credential->sign_count > 0 && $newCount > 0 && $newCount <= $credential->sign_count) {
            return response()->json(['error' => 'Bezpečnostní kontrola selhala (možná klonovaný klíč).'], 422);
        }

        $user = User::find($credential->user_id);
        if (!$user) {
            return response()->json(['error' => 'Uživatel neexistuje.'], 422);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $credential->update([
            'sign_count'   => $newCount,
            'last_used_at' => now(),
        ]);

        DB::table('login_logs')->insert([
            'user_id'    => $user->id,
            'time'       => now(),
            'IP_address' => $request->ip(),
        ]);

        $redirect = ($data['context'] ?? null) === 'field'
            ? route('field.search')
            : route('dashboard');

        return response()->json(['ok' => true, 'redirect' => $redirect]);
    }
}
