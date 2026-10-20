<?php

namespace App\Http\Requests\Devices;

use App\Domains\Acl\AclService;

/** Sdílené ACL a pravidla formulářů zařízení. */
trait DeviceRules
{
    /** ACL sekce zařízení (Devices_Controller / $value) pro akci. */
    protected function canDevices(string $action, string $value = 'devices'): bool
    {
        return app(AclService::class)->hasAccess($this->user()?->id ?? 0, $action, 'Devices_Controller', $value);
    }

    /** Pravidla adresy umístění (obec/ulice/č.p. → address_point). */
    protected static function addressRules(): array
    {
        return [
            'town_id'       => 'nullable|integer|exists:towns,id',
            'street_id'     => 'nullable|integer|exists:streets,id',
            'street_number' => 'nullable|string|max:50',
        ];
    }
}
