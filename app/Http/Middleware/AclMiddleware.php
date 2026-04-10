<?php

namespace App\Http\Middleware;

use App\Services\AclService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AclMiddleware
{
    public function handle(Request $request, Closure $next, string $action, string $section, string $value): Response
    {
        abort_unless(
            app(AclService::class)->hasAccess(auth()->id() ?? 0, $action, $section, $value),
            403
        );

        return $next($request);
    }
}
