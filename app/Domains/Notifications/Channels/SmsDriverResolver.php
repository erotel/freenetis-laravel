<?php

namespace App\Domains\Notifications\Channels;

use App\Models\Setting;

/**
 * Výběr SMS brány pro zprávu (sms_messages.driver, konstanty jako Kohana Sms):
 * požadovaný ovladač, jinak SmsManager (5), jinak Klikniavolej (3) — jen
 * aktivní (sms_driver_stateN = 2) s vyplněnými přihlašovacími údaji.
 * SOUNDWINV100 (2) a ARTIO (4) nejsou implementované.
 */
class SmsDriverResolver
{
    public const DRIVER_KLIKNIAVOLEJ = 3;
    public const DRIVER_SMSMANAGER   = 5;

    private const DRIVER_INACTIVE = 1;
    private const DRIVER_ACTIVE   = 2;

    public function resolve(int $driverId): SmsManagerDriver|KlikniavolejDriver|null
    {
        $candidates = array_unique(array_filter([$driverId, self::DRIVER_SMSMANAGER, self::DRIVER_KLIKNIAVOLEJ]));
        foreach ($candidates as $id) {
            $state = (int) Setting::get('sms_driver_state' . $id, self::DRIVER_INACTIVE);
            if ($state !== self::DRIVER_ACTIVE) continue;

            if ($id === self::DRIVER_SMSMANAGER) {
                $apiKey = (string) Setting::get('sms_password' . $id, '');
                if ($apiKey === '') continue;
                return new SmsManagerDriver($apiKey);
            }
            if ($id === self::DRIVER_KLIKNIAVOLEJ) {
                $user     = (string) Setting::get('sms_user' . $id, '');
                $password = (string) Setting::get('sms_password' . $id, '');
                if ($user === '' || $password === '') continue;
                $hostname = (string) Setting::get('sms_hostname' . $id, KlikniavolejDriver::DEFAULT_HOSTNAME);
                $testMode = (bool)   Setting::get('sms_test_mode' . $id, 0);
                return new KlikniavolejDriver($user, $password, $hostname, $testMode);
            }
        }
        return null;
    }
}
