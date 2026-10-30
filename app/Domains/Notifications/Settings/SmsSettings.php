<?php

namespace App\Domains\Notifications\Settings;

use App\Models\Setting;

/** Nastavení → SMS: zapnutí, odesílatel, ovladač a přístupy per ovladač. */
class SmsSettings
{
    // id → meta; has_hostname / has_test_mode jen KlikniaVolej
    public const DRIVERS = [
        3 => ['name' => 'KlikniaVolej.cz',       'has_hostname' => true,  'has_test_mode' => true],
        5 => ['name' => 'SmsManager JSON API v2', 'has_hostname' => false, 'has_test_mode' => false],
    ];

    public function values(): array
    {
        return [
            'sms_enabled'       => Setting::get('sms_enabled', '0'),
            'sms_sender_number' => Setting::get('sms_sender_number', ''),
            'sms_driver'        => Setting::get('sms_driver', ''),
        ];
    }

    public function driverValues(): array
    {
        $out = [];
        foreach (array_keys(self::DRIVERS) as $id) {
            $out[$id] = [
                'state'     => Setting::get('sms_driver_state' . $id, '1'),
                'hostname'  => Setting::get('sms_hostname' . $id, ''),
                'user'      => Setting::get('sms_user' . $id, ''),
                // heslo / API klíč se do stránky neposílá, jen příznak
                'password'  => '',
                'password_set' => (string) Setting::get('sms_password' . $id, '') !== '',
                'test_mode' => Setting::get('sms_test_mode' . $id, '0'),
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{state:int, hostname:string, user:string, password:string, test_mode:bool}> $drivers
     */
    public function save(bool $enabled, string $sender, string $driver, array $drivers): void
    {
        Setting::set('sms_enabled', $enabled ? 1 : 0);
        Setting::set('sms_sender_number', trim($sender));
        Setting::set('sms_driver', $driver);

        foreach (array_keys(self::DRIVERS) as $id) {
            $d = $drivers[$id];
            Setting::set('sms_driver_state' . $id, in_array($d['state'], [1, 2]) ? $d['state'] : 1);
            Setting::set('sms_hostname' . $id, trim($d['hostname']));
            Setting::set('sms_user' . $id, trim($d['user']));
            if (trim($d['password']) !== '') {   // prázdné = ponechat uložené
                Setting::set('sms_password' . $id, trim($d['password']));
            }
            Setting::set('sms_test_mode' . $id, $d['test_mode'] ? 1 : 0);
        }
    }
}
