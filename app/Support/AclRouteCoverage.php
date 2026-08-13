<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Statická analýza pokrytí přihlášených rout autorizací (NIS2/ZoKB).
 *
 * U každé `auth` routy zjistí, jestli je chráněná deklarativním `acl:` middleware
 * nebo voláním aclCheck/abort/->can() v těle controller metody. Používá command
 * `acl:coverage` (report) i guard test (aby nová neošetřená routa neprošla).
 */
class AclRouteCoverage
{
    private const MARKERS = [
        'aclCheck', 'abort_unless', 'abort_if', 'abort(403',
        'abort(Response::HTTP_FORBIDDEN', '->can(', '$this->can(', 'authorize(',
    ];

    /**
     * @return array{byMiddleware:int, byController:int, gaps:array<int,array{methods:string,uri:string,action:string}>}
     */
    public function scan(): array
    {
        $byMiddleware = 0;
        $byController = 0;
        $gaps = [];

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
                $gaps[] = ['methods' => $methods, 'uri' => $uri, 'action' => 'closure:' . $uri];
                continue;
            }

            [$class, $method] = explode('@', $action);
            $src = $this->methodSource($class, $method);

            if ($src !== null && $this->hasMarker($src)) {
                $byController++;
                continue;
            }

            $gaps[] = ['methods' => $methods, 'uri' => $uri, 'action' => class_basename($class) . '@' . $method];
        }

        return ['byMiddleware' => $byMiddleware, 'byController' => $byController, 'gaps' => $gaps];
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
