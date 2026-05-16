<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'acl'          => \App\Http\Middleware\AclMiddleware::class,
            'gpon_enabled' => \App\Http\Middleware\GponEnabled::class,
        ]);
        // Setup wizard guard — pokud existuje storage/app/setup.token a žádný admin
        // v DB, přesměruj všechny non-setup requesty na /setup. Po dokončení wizardu
        // se token soubor smaže a middleware je trvale no-op.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureSetupComplete::class,
            \App\Http\Middleware\EnableDebugForAdmins::class,
        ]);
        // Public sign endpoints are token-gated and serve cross-origin clients,
        // so they cannot rely on the session-bound CSRF token.
        $middleware->validateCsrfTokens(except: [
            'sign/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
