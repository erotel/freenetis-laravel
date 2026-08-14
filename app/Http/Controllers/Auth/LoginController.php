<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\LoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private LoginService $loginService) {}

    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Remember-me záměrně NEpodporujeme — User model nemá remember_token sloupec,
        // tj. cookie by byla vázaná na NULL token (forgeable, nerevokovatelná).
        //
        // Celý ověřovací flow (VS→login, rate-limit, zámek účtu, members.locked,
        // MFA gate) je ve sdílené LoginService — stejný pro /login i /field/login,
        // aby žádnou bránu nešlo obejít jiným endpointem.
        $result = $this->loginService->attempt($credentials, $request, 'web');

        switch ($result['status']) {
            case LoginService::RATE_LIMITED:
                return back()
                    ->withInput($request->only('login'))
                    ->withErrors(['login' => __("Příliš mnoho neúspěšných pokusů pro tento účet. Zkuste to znovu za :s s.", ['s' => $result['seconds']])]);

            case LoginService::LOCKED:
                return back()
                    ->withInput($request->only('login'))
                    ->withErrors(['login' => __('Účet je dočasně uzamčen kvůli opakovaným neúspěšným pokusům. Zkuste to znovu za :m min.', ['m' => $result['minutes']])]);

            case LoginService::INVALID:
                return back()
                    ->withInput($request->only('login'))
                    ->withErrors(['login' => __('Nesprávné přihlašovací jméno nebo heslo, nebo je účet zablokován.')]);

            case LoginService::MFA_REQUIRED:
                // NEpřihlašovat rovnou — poslat na challenge. Plné přihlášení
                // (i login_logs) proběhne až po ověření kódu.
                $this->loginService->beginMfaChallenge($result['user'], $request);
                return redirect()->route('mfa.challenge');
        }

        $this->loginService->completeLogin($result['user'], $request);
        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
