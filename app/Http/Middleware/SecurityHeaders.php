<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Přidává základní bezpečnostní HTTP hlavičky na všechny odpovědi.
 *
 * CSP záměrně nenastavujeme — aplikace hojně používá inline <script>/<style>,
 * takže smysluplná Content-Security-Policy by vyžadovala nonce refaktoring
 * (samostatný úkol). HSTS posíláme jen po HTTPS, ať se neaplikuje na HTTP dev.
 */
class SecurityHeaders
{
    /**
     * Routy které smí být embed v admin iframe (typicky přes subdoménu).
     * Ochrana těchto stránek stojí na jednorázovém HMAC podpisovém tokenu
     * (kontrolovaném v PublicSignController::resolveContract), ne na X-Frame-Options.
     */
    private const FRAMEABLE_ROUTES = [
        'sign.show',
        'sign.preview',
        'sign.addon.show',
        'sign.addon.preview',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // X-Frame-Options: SAMEORIGIN je default; sign.* routy jsou výjimka —
        // musí jít vnořit do admin detailu smlouvy (typicky cross-subdomain).
        $routeName = $request->route()?->getName();
        if (!in_array($routeName, self::FRAMEABLE_ROUTES, true)) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
