<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Exceptions\DeviceOwnerInvalid;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Domains\Network\Support\DeviceAttributes;
use App\Domains\Network\Support\SubnetAssignmentSync;
use App\Models\Device;
use App\Models\User;

/**
 * Úprava zařízení. Při změně majitele se přepíší povolené podsítě: allowed_subnets
 * jsou per člen a IP se při přesunu nemění, jen vlastník — proto se podsítě
 * zařízení zjistí ještě před změnou user_id. Oprávnění řeší volající.
 */
class UpdateDeviceAction
{
    public function __construct(
        private DeviceRepository $devices,
        private DeviceAttributes $attributes,
        private SubnetAssignmentSync $subnets,
    ) {
    }

    /** @throws DeviceOwnerInvalid */
    public function handle(Device $device, array $data): void
    {
        $data = $this->attributes->fromForm($data);

        $oldMemberId = $device->user?->member_id;
        $newMemberId = User::find($data['user_id'])?->member_id;
        $subnetIds   = $this->devices->subnetIdsOfDevice((int) $device->id);

        $device->update($data);

        $this->subnets->ownerChanged($oldMemberId, $newMemberId, $subnetIds);
    }
}
