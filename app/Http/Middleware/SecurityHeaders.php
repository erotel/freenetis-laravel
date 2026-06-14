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
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
