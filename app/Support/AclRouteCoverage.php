<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Statická analýza pokrytí přihlášených rout autorizací (NIS2/ZoKB).
 *
 * U každé `auth` routy zjistí, jestli je chráněná deklarativním `acl:` middleware,
 * voláním aclCheck/abort/->can() v těle controller metody, nebo Form Requestem
 * v parametrech metody, jehož authorize() kontroluje ACL (hasAccess/aclCheck). Používá command
 * `acl:coverage` (report) i guard test (aby nová neošetřená routa neprošla).
 */
class AclRouteCoverage
{
    /**
     * Routy bez ACL, které jsou VĚDOMĚ v pořádku (posouzeno 2026-08-13, revize
     * 2026-10-20): self-service (vlastní účet), ownership-scoped, nebo
     * necitlivá referenční data. Nová routa sem patří jen s odůvodněním.
     */
    public const ALLOWED = [
        'LoginController@logout'           => 'odhlášení sebe sama',
        'WebAuthnController@index'         => 'vlastní passkeys (user_id = Auth::id)',
        'WebAuthnController@destroy'       => 'maže jen vlastní klíč (ownership)',
        'MfaController@status'             => 'vlastní MFA',
        'MfaController@setup'              => 'vlastní MFA (ne při zapnutém)',
        'MfaController@confirm'            => 'vlastní MFA (ne při zapnutém)',
        'MfaController@regenerateRecovery' => 'vlastní MFA, vyžaduje heslo',
        'MfaController@disable'            => 'vlastní MFA, heslo + kód, ne u povinné role',
        'UserController@toggleDarkMode'    => 'vlastní UI preference',
        'SavedFilterController@index'      => 'vlastní uložené filtry (ownership)',
        'SavedFilterController@store'      => 'vlastní uložené filtry (ownership)',
        'SavedFilterController@destroy'    => 'vlastní uložené filtry (ownership)',
        'closure:/'                        => 'dashboard → vlastní karta člena',
        'closure:/ares/lookup/{ico}'       => 'veřejný rejstřík ARES (existuje i veřejná varianta)',
        'closure:/streets/by-town/{townId}' => 'číselník ulic pro dropdown (existuje i veřejná varianta)',
    ];

    private const MARKERS = [
        'aclCheck', 'abort_unless', 'abort_if', 'abort(403',
        'abort(Response::HTTP_FORBIDDEN', '->can(', '$this->can(', 'authorize(',
    ];

    /**
     * Mezery = routy bez autorizace mimo ALLOWED; posouzené výjimky zvlášť v `allowed`.
     *
     * @return array{byMiddleware:int, byController:int, allowed:array<int,array{methods:string,uri:string,action:string,reason:string}>, gaps:array<int,array{methods:string,uri:string,action:string}>}
     */
    public function scan(): array
    {
        $byMiddleware = 0;
        $byController = 0;
        $gaps = [];
        $allowed = [];

        foreach (Route::getRoutes() as $route) {
            $mw = $route->gatherMiddleware();
            if (!in_array('auth', $mw, true)) {
                continue; // jen přihlášené routy
            }

            foreach ($mw as $m) {
                if (is_string($m) && str_starts_with($m, 'acl:')) {
                    $byMiddleware++;
                    continue 2;
                }
            }

            $action  = $route->getActionName();
            $methods = implode('|', array_diff($route->methods(), ['HEAD']));
            $uri     = '/' . ltrim($route->uri(), '/');

            if ($action === 'Closure' || !str_contains($action, '@')) {
                $this->gap($gaps, $allowed, ['methods' => $methods, 'uri' => $uri, 'action' => 'closure:' . $uri]);
                continue;
            }

            [$class, $method] = explode('@', $action);
            $src = $this->methodSource($class, $method);

            if (($src !== null && $this->hasMarker($src)) || $this->authorizedByFormRequest($class, $method)) {
                $byController++;
                continue;
            }

            $this->gap($gaps, $allowed, ['methods' => $methods, 'uri' => $uri, 'action' => class_basename($class) . '@' . $method]);
        }

        return ['byMiddleware' => $byMiddleware, 'byController' => $byController, 'allowed' => $allowed, 'gaps' => $gaps];
    }

    private function gap(array &$gaps, array &$allowed, array $route): void
    {
        if (isset(self::ALLOWED[$route['action']])) {
            $allowed[] = $route + ['reason' => self::ALLOWED[$route['action']]];
        } else {
            $gaps[] = $route;
        }
    }

    /** Má metoda parametr typu FormRequest, jehož authorize() kontroluje ACL? */
    private function authorizedByFormRequest(string $class, string $method): bool
    {
        try {
            $params = (new \ReflectionMethod($class, $method))->getParameters();
        } catch (\Throwable) {
            return false;
        }
        foreach ($params as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()
                && is_subclass_of($type->getName(), \Illuminate\Foundation\Http\FormRequest::class)
                && self::formRequestChecksAcl($type->getName())) {
                return true;
            }
        }
        return false;
    }

    /**
     * authorize() je přepsané (ne základní FormRequest) a jeho tělo volá
     * hasAccess/aclCheck přímo, nebo přes `$this->x(...)` metodu téže třídy
     * (i z traitu), která je volá. `return true;` nestačí.
     */
    public static function formRequestChecksAcl(string $requestClass): bool
    {
        try {
            $authorize = new \ReflectionMethod($requestClass, 'authorize');
        } catch (\Throwable) {
            return false;
        }
        if ($authorize->getDeclaringClass()->getName() === \Illuminate\Foundation\Http\FormRequest::class) {
            return false;
        }

        $body = self::sourceOf($authorize);
        if (self::callsAcl($body)) {
            return true;
        }
        preg_match_all('/\$this->(\w+)\(/', $body, $calls);
        foreach (array_unique($calls[1]) as $helper) {
            try {
                if (self::callsAcl(self::sourceOf(new \ReflectionMethod($requestClass, $helper)))) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }
        return false;
    }

    private static function callsAcl(string $src): bool
    {
        return str_contains($src, 'hasAccess(') || str_contains($src, 'aclCheck(');
    }

    private static function sourceOf(\ReflectionMethod $ref): string
    {
        $lines = file($ref->getFileName());
        return implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }

    private function methodSource(string $class, string $method): ?string
    {
        try {
            $ref = new \ReflectionMethod($class, $method);
            $lines = file($ref->getFileName());
            return implode('', array_slice(
                $lines,
                $ref->getStartLine() - 1,
                $ref->getEndLine() - $ref->getStartLine() + 1
            ));
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasMarker(string $src): bool
    {
        foreach (self::MARKERS as $m) {
            if (str_contains($src, $m)) {
                return true;
            }
        }
        return false;
    }
}
