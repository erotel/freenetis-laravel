<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GponEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Setting::get('gpon_enabled', '0')) {
            return redirect()->route('settings.index', ['tab' => 'gpon'])
                ->with('error', 'Modul GPON není aktivován. Nejprve ho zapněte v nastavení.');
        }

        return $next($request);
    }
}
