<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        // Field Mode: nepřihlášené hosty na /field/* posílej na mobilní login,
        // ne na desktopový /login.
        $middleware->redirectGuestsTo(fn($request) => $request->is('field') || $request->is('field/*')
            ? route('field.login')
            : route('login'));
        // Setup wizard guard — pokud existuje storage/app/setup.token a žádný admin
        // v DB, přesměruj všechny non-setup requesty na /setup. Po dokončení wizardu
        // se token soubor smaže a middleware je trvale no-op.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureSetupComplete::class,
            \App\Http\Middleware\EnableDebugForAdmins::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Důvěryhodné reverzní proxy (kvůli správné detekci HTTPS a klientské IP
        // za Apache/LB). Nastav TRUSTED_PROXIES v .env (CSV, nebo '*'); default
        // = žádná, tedy beze změny chování.
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if (!empty($trustedProxies)) {
            $middleware->trustProxies(
                at: in_array('*', $trustedProxies, true) ? '*' : $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }
        // Public sign endpoints are token-gated and serve cross-origin clients,
        // so they cannot rely on the session-bound CSRF token.
        $middleware->validateCsrfTokens(except: [
            'sign/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
