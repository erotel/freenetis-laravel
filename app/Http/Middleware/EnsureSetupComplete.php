<?php

namespace App\Http\Middleware;

use App\Services\SetupService;
use Closure;
use Illuminate\Http\Request;

/**
 * Pokud setup ještě neproběhl (existuje setup.token + DB nemá admina),
 * přesměruj všechny non-setup requesty na /setup.
 *
 * Aktivní jen mezi 02-configure-app.sh a dokončením web wizardu.
 */
class EnsureSetupComplete
{
    public function __construct(private SetupService $setup) {}

    public function handle(Request $request, Closure $next)
    {
        if (!$this->setup->isSetupNeeded()) {
            return $next($request);
        }

        // Setup je potřeba — povol jen /setup/* a static assety
        if ($request->is('setup', 'setup/*')) {
            return $next($request);
        }

        return redirect('/setup');
    }
}
