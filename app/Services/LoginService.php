<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Jednotný přihlašovací flow sdílený VŠEMI heslovými cestami (/login,
 * /field/login). Cíl: aby bezpečnostní brány (rate-limit, trvalý zámek účtu,
 * members.locked přes provider, druhý faktor MFA) NEšlo obejít použitím jiného
 * přihlašovacího endpointu.
 *
 * attempt() provede celé ověření BEZ vytvoření session a vrátí stav; volající
 * podle stavu buď dokončí přihlášení (completeLogin), nebo pošle na MFA
 * challenge (beginMfaChallenge), nebo zobrazí chybu. Všechny cesty sdílejí
 * jeden rate-limit bucket (per-username), takže rozdělení pokusů na víc
 * endpointů útočníkovi nedá víc pokusů.
 */
class LoginService
{
    /** Per-username throttle (komplementuje IP throttle:login v routes). */
    public const LOGIN_MAX_ATTEMPTS = 10;
    public const LOGIN_DECAY_SECONDS = 300; // 5 min

    /** Výsledky attempt(). */
    public const OK = 'ok';
    public const MFA_REQUIRED = 'mfa_required';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';
    public const RATE_LIMITED = 'rate_limited';

    public function __construct(
        private LoginLockService $lock,
        private MfaService $mfa,
    ) {}

    /**
     * Přeloží variabilní symbol na login (jen číselný vstup). Když match nenajde,
     * vrací původní vstup (umožní i login se všemi-číselným loginem).
     */
    public function resolveLogin(string $rawInput): string
    {
        $rawInput = trim($rawInput);
        if ($rawInput === '' || !ctype_digit($rawInput)) {
            return $rawInput;
        }

        $resolved = DB::table('variable_symbols as vs')
            ->join('accounts as a', 'a.id', '=', 'vs.account_id')
            ->join('users as u', function ($j) {
                $j->on('u.member_id', '=', 'a.member_id')
                  ->where('u.type', '=', User::MAIN_USER);
            })
            ->where('vs.variable_symbol', $rawInput)
            ->value('u.login');

        return $resolved ?: $rawInput;
    }

    /**
     * Jeden sdílený rate-limit klíč napříč /login i /field/login — útočník tak
     * nedostane 2× pokusů rozdělením na dva endpointy. sha1 jen normalizace/délka.
     */
    private function rateKey(string $rawInput): string
    {
        return 'login_user:' . sha1(strtolower(trim($rawInput)));
    }

    /**
     * Celé ověření BEZ vytvoření session: rate-limit → zámek účtu → ověření hesla
     * (+ members.locked přes provider) → záznam selhání/zámku → detekce MFA.
     *
     * Vrací pole: ['status' => self::*, 'user' => ?User, 'seconds' => int, 'minutes' => int].
     *
     * @param array{login:string,password:string} $credentials
     * @param string $channel  jen pro logy ('web' | 'field')
     */
    public function attempt(array $credentials, Request $request, string $channel = 'web'): array
    {
        $rawInput = trim((string) ($credentials['login'] ?? ''));
        $credentials['login'] = $this->resolveLogin($rawInput);

        $key = $this->rateKey($rawInput);
        if (RateLimiter::tooManyAttempts($key, self::LOGIN_MAX_ATTEMPTS)) {
            return ['status' => self::RATE_LIMITED, 'seconds' => RateLimiter::availableIn($key)];
        }

        // Ověřit heslo (a zámek účtu) BEZ přihlášení — kvůli MFA musíme mezi
        // heslo a plnou session vsunout druhý faktor. Provider řeší legacy hashe
        // i members.locked (stejně jako Auth::attempt).
        $provider = Auth::getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        // Trvalý zámek účtu po N selháních — odmítnout i se správným heslem.
        if ($user && $this->lock->isLocked((int) $user->id)) {
            $until = $this->lock->lockedUntil((int) $user->id);
            $mins  = $until ? max(1, (int) ceil(now()->diffInSeconds($until) / 60)) : $this->lock->lockMinutes();
            logger()->warning('auth.account.locked_attempt', [
                'user_id' => $user->id, 'ip' => $request->ip(), 'channel' => $channel,
            ]);
            return ['status' => self::LOCKED, 'minutes' => $mins];
        }

        if (!$user || !$provider->validateCredentials($user, $credentials)) {
            // Zaznamenat selhání a případně zamknout účet (jen když uživatel existuje).
            if ($user && $this->lock->recordFailure((int) $user->id)) {
                logger()->warning('auth.account.locked', [
                    'user_id' => $user->id, 'ip' => $request->ip(), 'channel' => $channel,
                ]);
                AuditLogger::log('account_locked', 'users', (int) $user->id, null, [
                    'reason'  => 'failed_logins',
                    'minutes' => $this->lock->lockMinutes(),
                ]);
            }
            RateLimiter::hit($key, self::LOGIN_DECAY_SECONDS);
            logger()->warning('auth.login.failed', [
                'login'    => $credentials['login'],
                'raw'      => $rawInput,
                'resolved' => $rawInput !== $credentials['login'],
                'ip'       => $request->ip(),
                'channel'  => $channel,
                'ua'       => substr((string) $request->userAgent(), 0, 200),
            ]);
            return ['status' => self::INVALID];
        }

        $this->lock->clear((int) $user->id);
        RateLimiter::clear($key);

        // Má uživatel zapnutý druhý faktor? Pak NEpřihlašovat rovnou.
        if ($this->mfa->isEnabled((int) $user->id)) {
            return ['status' => self::MFA_REQUIRED, 'user' => $user];
        }

        return ['status' => self::OK, 'user' => $user];
    }

    /**
     * Zapíše do session „čeká na 2. faktor" (pro MfaChallengeController).
     * Volá se při stavu MFA_REQUIRED.
     */
    public function beginMfaChallenge(User $user, Request $request): void
    {
        $request->session()->put('mfa.pending_user_id', (int) $user->id);
        $request->session()->put('mfa.pending_at', now()->timestamp);
    }

    /**
     * Dokončí přihlášení: plná session (regenerate) + záznam do login_logs.
     * Sdílené pro obě heslové cesty (stav OK).
     */
    public function completeLogin(User $user, Request $request): void
    {
        Auth::login($user);
        $request->session()->regenerate();

        DB::table('login_logs')->insert([
            'user_id'    => $user->id,
            'time'       => now(),
            'IP_address' => $request->ip(),
        ]);
    }
}
