<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parametry, které jsou vždy jeden text (tokeny odkazů, přihlášení, kódy),
 * poslané jako pole (`?t[]=x`, `login[]=x`) dřív shodily (string) přetypování
 * na 500 — podpis smlouvy, exporty pro brány, reset hesla, login. Odmítnout
 * hned na vstupu jako 400, ať se to neřeší v každém controlleru zvlášť.
 */
class RejectArrayParams
{
    public const SCALAR = ['t', 'token', 'request', 'login', 'password', 'otp', 'code', 'phone'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::SCALAR as $key) {
            if (is_array($request->query($key)) || is_array($request->post($key))) {
                return $request->expectsJson()
                    ? response()->json(['error' => 'Neplatný požadavek.'], 400)
                    : response('Neplatný požadavek.', 400);
            }
        }
        return $next($request);
    }
}
