<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Druhý faktor při přihlášení. Uživatel sem přijde po ověření hesla
 * (LoginController), ale NENÍ ještě přihlášen — v session je jen
 * `mfa.pending_user_id`. Teprve po ověření TOTP/záložního kódu se přihlásí.
 */
class MfaChallengeController extends Controller
{
    /** Kolik minut platí „pending" stav mezi heslem a 2. faktorem. */
    private const PENDING_TTL_MIN = 10;

    /** Max. pokusů o kód, než se to na chvíli zamkne. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private MfaService $mfa) {}

    public function show(Request $request)
    {
        if (!$this->pendingUserId($request)) {
            return redirect()->route('login')
                ->withErrors(['login' => 'Přihlášení vypršelo, zkuste to prosím znovu.']);
        }

        return view('auth.mfa_challenge');
    }

    public function verify(Request $request)
    {
        $userId = $this->pendingUserId($request);
        if (!$userId) {
            return redirect()->route('login')
                ->withErrors(['login' => 'Přihlášení vypršelo, zkuste to prosím znovu.']);
        }

        $request->validate([
            'code' => 'required|string',
            'mode' => 'nullable|in:totp,recovery',
        ]);

        $key = 'mfa:' . $userId;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors(['code' => __("Příliš mnoho pokusů. Zkuste to za :s s.", ['s' => $seconds])]);
        }

        $mode = $request->input('mode', 'totp');
        $ok   = false;

        if ($mode === 'recovery') {
            $ok = $this->mfa->verifyAndConsumeRecovery($userId, (string) $request->input('code'));
        } else {
            $mfa = $this->mfa->getConfirmed($userId);
            $ok  = $mfa && $this->mfa->verifyTotp($mfa->totp_secret, (string) $request->input('code'));
        }

        if (!$ok) {
            RateLimiter::hit($key, 300);
            logger()->warning('auth.mfa.failed', [
                'user_id' => $userId, 'mode' => $mode, 'ip' => $request->ip(),
            ]);
            return back()->withErrors(['code' => 'Kód nesouhlasí.']);
        }

        // Úspěch — dokončit přihlášení.
        RateLimiter::clear($key);
        $user = User::find($userId);
        if (!$user) {
            $this->clearPending($request);
            return redirect()->route('login')->withErrors(['login' => 'Účet nenalezen.']);
        }

        Auth::login($user);
        $this->clearPending($request);
        $request->session()->regenerate();
        $request->session()->put('mfa_passed', true);

        DB::table('login_logs')->insert([
            'user_id'    => $user->id,
            'time'       => now(),
            'IP_address' => $request->ip(),
        ]);

        if ($mode === 'recovery') {
            $left = $this->mfa->unusedRecoveryCount($userId);
            session()->flash('warning', "Přihlášeno záložním kódem. Zbývá {$left} záložních kódů.");
        }

        return redirect()->intended(route('dashboard'));
    }

    /** ID uživatele čekajícího na 2. faktor, nebo null (chybí / vypršelo). */
    private function pendingUserId(Request $request): ?int
    {
        $id = $request->session()->get('mfa.pending_user_id');
        $at = (int) $request->session()->get('mfa.pending_at', 0);

        if (!$id || $at === 0 || (now()->timestamp - $at) > self::PENDING_TTL_MIN * 60) {
            $this->clearPending($request);
            return null;
        }

        return (int) $id;
    }

    private function clearPending(Request $request): void
    {
        $request->session()->forget(['mfa.pending_user_id', 'mfa.pending_at']);
    }
}
