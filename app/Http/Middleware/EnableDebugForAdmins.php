<?php

namespace App\Http\Middleware;

use App\Services\AclService;
use Closure;
use Illuminate\Http\Request;

/**
 * Per-request debug toggle gated by ACL right Debug_Controller#debug view_all.
 *
 * Why: APP_DEBUG=true v .env globálně leakne stack trace/ENV/SQL všem,
 * kdo trefí error stránku. Tady místo toho zapínáme debug jen pro
 * přihlášeného uživatele s ACL právem (default jen System administrators).
 *
 * Bezpečnost: gated session + ACL = útočník potřebuje admin credentials,
 * ne jen být na správné IP. Stejná hladina jako přístup do nastavení.
 */
class EnableDebugForAdmins
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check()
            && app(AclService::class)->hasAccess((int) auth()->id(), 'view_all', 'Debug_Controller', 'debug')
        ) {
            config(['app.debug' => true]);
        }

        return $next($request);
    }
}
