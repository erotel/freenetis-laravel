<?php

namespace App\Http\Controllers;

use App\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service správa dvoufázového přihlášení (MFA) — fáze A.
 * Uživatel si TOTP zapne, potvrdí kódem, dostane záložní kódy, může vypnout.
 */
class MfaController extends Controller
{
    private const SETUP_SECRET_KEY = 'mfa.setup_secret';

    public function __construct(private MfaService $mfa) {}

    /** Přehled stavu MFA v profilu. */
    public function status()
    {
        $userId  = (int) Auth::id();
        $enabled = $this->mfa->isEnabled($userId);

        return view('mfa.status', [
            'enabled'       => $enabled,
            'recoveryLeft'  => $enabled ? $this->mfa->unusedRecoveryCount($userId) : 0,
        ]);
    }

    /** Krok 1: zobrazit QR + secret k naskenování. */
    public function setup(Request $request)
    {
        $user = Auth::user();

        // Nový secret při každém vstupu do setupu (dokud není potvrzený).
        $secret = $this->mfa->generateSecret();
        $request->session()->put(self::SETUP_SECRET_KEY, $secret);

        $uri = $this->mfa->otpauthUri($user, $secret);

        return view('mfa.setup', [
            'secret' => $secret,
            'qrSvg'  => $this->mfa->qrSvg($uri),
        ]);
    }

    /** Krok 2: ověřit kód z appky → zapnout MFA a ukázat záložní kódy. */
    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $secret = (string) $request->session()->get(self::SETUP_SECRET_KEY, '');
        if ($secret === '') {
            return redirect()->route('mfa.setup')
                ->withErrors(['code' => 'Setup vypršel, zkuste to prosím znovu.']);
        }

        if (!$this->mfa->verifyTotp($secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'Kód nesouhlasí. Zkontrolujte čas v telefonu a zkuste znovu.']);
        }

        $userId = (int) Auth::id();
        $this->mfa->enable($userId, $secret);
        $codes = $this->mfa->generateRecoveryCodes($userId);

        $request->session()->forget(self::SETUP_SECRET_KEY);
        // Aktuální session je od teď „MFA splněno" (uživatel se právě prokázal).
        $request->session()->put('mfa_passed', true);

        logger()->info('auth.mfa.enabled', ['user_id' => $userId, 'ip' => $request->ip()]);

        // Kódy ukážeme JEDNOU — přes flash, ať nezůstanou v URL/historii.
        return view('mfa.recovery_codes', ['codes' => $codes, 'context' => 'enabled']);
    }

    /** Přegenerovat záložní kódy (vyžaduje heslo). */
    public function regenerateRecovery(Request $request)
    {
        $request->validate(['password' => 'required|string']);
        if (!$this->passwordOk($request->input('password'))) {
            return back()->withErrors(['password' => 'Nesprávné heslo.']);
        }

        $userId = (int) Auth::id();
        if (!$this->mfa->isEnabled($userId)) {
            return redirect()->route('mfa.status');
        }

        $codes = $this->mfa->generateRecoveryCodes($userId);
        logger()->info('auth.mfa.recovery_regenerated', ['user_id' => $userId, 'ip' => $request->ip()]);

        return view('mfa.recovery_codes', ['codes' => $codes, 'context' => 'regenerated']);
    }

    /** Vypnout MFA (vyžaduje heslo). */
    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|string']);
        if (!$this->passwordOk($request->input('password'))) {
            return back()->withErrors(['password' => 'Nesprávné heslo.']);
        }

        $userId = (int) Auth::id();
        $this->mfa->disable($userId);
        logger()->warning('auth.mfa.disabled', ['user_id' => $userId, 'ip' => $request->ip()]);

        return redirect()->route('mfa.status')->with('success', 'Dvoufázové přihlášení bylo vypnuto.');
    }

    /** Ověří heslo aktuálně přihlášeného uživatele. */
    private function passwordOk(string $password): bool
    {
        $user = Auth::user();
        return $user
            && Auth::getProvider()->validateCredentials($user, [
                'login'    => $user->login,
                'password' => $password,
            ]);
    }
}
