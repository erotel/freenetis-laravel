<?php

namespace App\Http\Controllers\Devices;

use App\Domains\Network\Repositories\DeviceRepository;

/** Společná autorizace controllerů zařízení (ACL Devices_Controller + vlastní zařízení). */
trait DeviceAccess
{
    private function can(string $action, string $value = 'devices'): bool
    {
        return $this->aclCheck($action, 'Devices_Controller', $value);
    }

    /**
     * True, pokud daný uživatel patří pod stejného člena jako přihlášený uživatel
     * (self-access — zákazník smí vidět vlastní zařízení i bez ACL view_all).
     */
    private function isOwnUser(?int $userId): bool
    {
        $ownMemberId = auth()->user()?->member_id;
        if (!$ownMemberId || !$userId) {
            return false;
        }

        return app(DeviceRepository::class)->memberIdOfUser($userId) == $ownMemberId;
    }
}
