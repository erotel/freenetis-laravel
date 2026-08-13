<?php

namespace App\Http\Middleware;

use App\Services\MfaEnforcementService;
use App\Services\MfaService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA fáze B (vynucení, NIS2/ZoKB). Když role uživatele vyžaduje dvoufázové
 * přihlášení:
 *  - a MFA NEMÁ zřízené → forced enrollment (přesměruje na setup, nic jiného
 *    nepustí, dokud si ho nezřídí),
 *  - a MFA má, ale tato session ho neprošla → vyžádá nové přihlášení.
 *
 * Když žádná skupina není označená jako „MFA povinné" (mfa_required_groups
 * prázdná), je to no-op → bezpečné mít zapnuté i před ostrým vynucením.
 */
class EnsureMfa
{
    /** Routy povolené i během forced enrollmentu (jinak by byl redirect loop). */
    private const ALLOWED = ['mfa.status', 'mfa.setup', 'mfa.confirm', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (!$user) {
            return $next($request); // nepřihlášený — řeší auth middleware
        }

        if (!app(MfaEnforcementService::class)->isRequiredForUser((int) $user->id)) {
            return $next($request); // role MFA nevyžaduje (nebo nikde vynuceno)
        }

        $routeName = $request->route()?->getName();

        // Nemá MFA → vynutit zřízení.
        if (!app(MfaService::class)->isEnabled((int) $user->id)) {
            if (in_array($routeName, self::ALLOWED, true)) {
                return $next($request);
            }
            return redirect()->route('mfa.setup')
                ->with('warning', 'Vaše role vyžaduje dvoufázové přihlášení (MFA). Nastavte si ho prosím.');
        }

        // Má MFA, ale tato session ho neprošla (edge case) → nové přihlášení.
        if (!$request->session()->get('mfa_passed')) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')
                ->withErrors(['login' => 'Vyžadováno dvoufázové ověření. Přihlaste se prosím znovu.']);
        }

        return $next($request);
    }
}
