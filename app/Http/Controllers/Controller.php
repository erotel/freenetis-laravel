<?php

namespace App\Http\Controllers;

use App\Services\AclService;

abstract class Controller
{
    protected function aclCheck(string $action, string $section, string $value): bool
    {
        return app(AclService::class)->hasAccess(auth()->id() ?? 0, $action, $section, $value);
    }
}
