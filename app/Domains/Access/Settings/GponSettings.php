<?php

namespace App\Domains\Access\Settings;

use App\Models\Setting;

/** Nastavení → GPON: zapnutí registrace ONT (OLT viz GponOltRepository). */
class GponSettings
{
    public const KEYS = ['gpon_enabled'];

    public function values(): array
    {
        return ['gpon_enabled' => Setting::get('gpon_enabled', '')];
    }

    public function save(bool $enabled): void
    {
        Setting::set('gpon_enabled', $enabled ? 1 : 0);
    }
}
