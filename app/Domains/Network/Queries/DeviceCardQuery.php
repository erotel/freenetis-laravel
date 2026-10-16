<?php

namespace App\Domains\Network\Queries;

use App\Domains\Access\Repositories\PppoeCredentialRepository;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Collection;

/** Data detailu zařízení (read model). Oprávnění řeší volající. */
class DeviceCardQuery
{
    public function find(int $id): ?Device
    {
        return Device::with([
            'user.member',
            'enumType',
            'addressPoint.street',
            'addressPoint.town',
            'ifaces.ipAddresses.subnet',
            'ifaces.ip6Addresses',
            'ifaces.lineId',
            'deviceEngineers.user',
        ])->find($id);
    }

    /** Uživatelé, které lze přidat jako technika (bez už přiřazených), podle příjmení. */
    public function assignableEngineers(Device $device): Collection
    {
        return User::orderBy('surname')->orderBy('name')
            ->whereNotIn('id', $device->deviceEngineers->pluck('user_id')->all())
            ->get(['id', 'login', 'name', 'surname']);
    }

    /** PPPoE přístupy rozhraní zařízení, klíčované iface_id. */
    public function pppoeSecrets(Device $device): Collection
    {
        return app(PppoeCredentialRepository::class)->forIfaces($device->ifaces->pluck('id'));
    }
}
