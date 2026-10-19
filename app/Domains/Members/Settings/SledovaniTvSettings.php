<?php

namespace App\Domains\Members\Settings;

use App\Models\Setting;
use App\Services\SledovaniTvService;
use Illuminate\Support\Facades\Log;

/** Nastavení → SledovaniTV: přístup k partnerskému API a ruční sync na členy. */
class SledovaniTvSettings
{
    public const KEYS = ['sledovanitv_enabled', 'sledovanitv_partner', 'sledovanitv_password'];

    public function __construct(private SledovaniTvService $tv)
    {
    }

    public function values(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = (string) Setting::get($key, '');
        }
        $out['last_sync']        = (string) Setting::get('sledovanitv_last_sync', '');
        $out['last_sync_status'] = (string) Setting::get('sledovanitv_last_sync_status', '');
        return $out;
    }

    /** Prázdné heslo = ponechat stávající. */
    public function save(bool $enabled, string $partner, string $password): void
    {
        Setting::set('sledovanitv_enabled', $enabled ? 1 : 0);
        Setting::set('sledovanitv_partner', trim($partner));
        if ($password !== '') {
            Setting::set('sledovanitv_password', $password);
        }
    }

    /**
     * @return array{total:int, matched:int, active:int, unmatched:int, no_partnerid:int}
     * @throws \Throwable po zalogování a zápisu stavu „CHYBA: …"
     */
    public function sync(): array
    {
        try {
            return $this->tv->syncToMembers();
        } catch (\Throwable $e) {
            Log::error('SledovaniTV manual sync failed: ' . $e->getMessage());
            Setting::set('sledovanitv_last_sync_status', 'CHYBA: ' . $e->getMessage());
            throw $e;
        }
    }
}
