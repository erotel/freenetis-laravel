<?php

namespace App\Domains\Network\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Network\Repositories\DeviceRepository;

/** Odebrání technika ze zařízení + audit (hromadný delete nespustí model eventy). */
class RemoveDeviceEngineerAction
{
    public function __construct(private DeviceRepository $devices)
    {
    }

    public function handle(int $deviceId, int $userId): void
    {
        $this->devices->removeEngineer($deviceId, $userId);
        AuditLogger::log('deleted', 'device_engineers', $deviceId, [
            'device_id' => $deviceId,
            'user_id'   => $userId,
        ], null);
    }
}
