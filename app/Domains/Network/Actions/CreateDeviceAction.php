<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Exceptions\DeviceOwnerInvalid;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Domains\Network\Support\DeviceAttributes;
use App\Models\Device;

/**
 * Založení zařízení bez šablony (bez rozhraní). Datum koupě nezadáno → dnes
 * (jako Kohana). Oprávnění a přípustná pole (login/heslo dle práv) řeší volající.
 */
class CreateDeviceAction
{
    public function __construct(
        private DeviceRepository $devices,
        private DeviceAttributes $attributes,
    ) {
    }

    /** @throws DeviceOwnerInvalid */
    public function handle(array $data): Device
    {
        $data = $this->attributes->fromForm($data);
        if (empty($data['buy_date'])) {
            $data['buy_date'] = now()->toDateString();
        }

        return $this->devices->create($data);
    }
}
