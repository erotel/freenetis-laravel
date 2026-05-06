<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Per-username limit (komplementuje IP throttle:5,1 v routes/web.php) */
    private const LOGIN_MAX_ATTEMPTS = 10;
    private const LOGIN_DECAY_SECONDS = 300; // 5 min

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

        // Per-username throttle — chrání před distributed brute-force napříč IP.
        // sha1 jen pro normalizaci a délku klíče; není to bezpečnostní hash.
        $loginKey = 'login_user:' . sha1(strtolower(trim((string) $credentials['login'])));
        if (RateLimiter::tooManyAttempts($loginKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($loginKey);
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => __("Příliš mnoho neúspěšných pokusů pro tento účet. Zkuste to znovu za :s s.", ['s' => $seconds])]);
        }

        // Remember-me záměrně NEpodporujeme — User model nemá remember_token sloupec,
        // tj. cookie by byla vázaná na NULL token (forgeable, nerevokovatelná). Pokud by
        // remember-me bylo potřeba, doplň migraci pro users.remember_token a opravu
        // get/set/getRememberTokenName v App\Models\User.
        if (!Auth::attempt($credentials)) {
            RateLimiter::hit($loginKey, self::LOGIN_DECAY_SECONDS);
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => __('Nesprávné přihlašovací jméno nebo heslo, nebo je účet zablokován.')]);
        }

        RateLimiter::clear($loginKey);
        $request->session()->regenerate();

        // Write to FreenetIS login_logs table
        DB::table('login_logs')->insert([
            'user_id'    => Auth::id(),
            'time'       => now(),
            'IP_address' => $request->ip(),
        ]);

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
