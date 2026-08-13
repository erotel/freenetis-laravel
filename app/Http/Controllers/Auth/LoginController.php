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
    /** Per-username limit (komplementuje IP throttle:10,1 v routes/web.php) */
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

        // Variabilní symbol jako alias pro login: pokud uživatel zadal jen číslice,
        // zkusíme to nejdřív přeložit přes variable_symbols → accounts.member_id →
        // MAIN_USER. Když najdeme match, credentials['login'] přepíšeme na skutečný
        // login. Když ne, ponecháme původní (umožní login se všemi-číselným loginem).
        $rawInput = trim((string) $credentials['login']);
        if ($rawInput !== '' && ctype_digit($rawInput)) {
            $resolved = DB::table('variable_symbols as vs')
                ->join('accounts as a', 'a.id', '=', 'vs.account_id')
                ->join('users as u', function ($j) {
                    $j->on('u.member_id', '=', 'a.member_id')
                      ->where('u.type', '=', \App\Models\User::MAIN_USER);
                })
                ->where('vs.variable_symbol', $rawInput)
                ->value('u.login');
            if ($resolved) {
                $credentials['login'] = $resolved;
            }
        }

        // Per-username throttle — chrání před distributed brute-force napříč IP.
        // Klíč drží originální vstup (VS nebo login), ať throttle platí pro obě cesty.
        // sha1 jen pro normalizaci a délku klíče; není to bezpečnostní hash.
        $loginKey = 'login_user:' . sha1(strtolower($rawInput));
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
        // Ověřit heslo (a zámek účtu) BEZ přihlášení — kvůli MFA musíme mezi
        // heslo a plnou session vsunout druhý faktor. Provider řeší i legacy
        // hashe a members.locked (stejně jako Auth::attempt).
        $provider = Auth::getProvider();
        $user = $provider->retrieveByCredentials($credentials);
        if (!$user || !$provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($loginKey, self::LOGIN_DECAY_SECONDS);
            logger()->warning('auth.login.failed', [
                'login'    => $credentials['login'],
                'raw'      => $rawInput,
                'resolved' => $rawInput !== $credentials['login'],
                'ip'       => $request->ip(),
                'ua'       => substr((string) $request->userAgent(), 0, 200),
            ]);
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => __('Nesprávné přihlašovací jméno nebo heslo, nebo je účet zablokován.')]);
        }

        RateLimiter::clear($loginKey);

        // Má uživatel zapnutý druhý faktor? Pak NEpřihlašovat rovnou — jen si
        // zapamatovat „čeká na 2. faktor" a poslat na challenge. Plné přihlášení
        // (i login_logs) proběhne až po ověření kódu v MfaChallengeController.
        if (app(\App\Services\MfaService::class)->isEnabled((int) $user->id)) {
            $request->session()->put('mfa.pending_user_id', (int) $user->id);
            $request->session()->put('mfa.pending_at', now()->timestamp);
            return redirect()->route('mfa.challenge');
        }

        Auth::login($user);
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
