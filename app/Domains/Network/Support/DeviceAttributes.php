<?php

namespace App\Domains\Network\Support;

use App\Domains\Network\Exceptions\DeviceOwnerInvalid;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Models\Device;
use App\Services\AddressResolverService;

/**
 * Převod validovaných dat formuláře zařízení na sloupce devices:
 * člen → hlavní uživatel (user_id), obec/ulice/č.p. → address_point_id,
 * WPA2 klíč jen u AP (jinak null; prázdný → null).
 */
class DeviceAttributes
{
    public function __construct(
        private DeviceRepository $devices,
        private AddressResolverService $addresses,
    ) {
    }

    /** @throws DeviceOwnerInvalid */
    public function fromForm(array $data): array
    {
        $data = self::normalizeWpaKey($data);

        $userId = $this->devices->mainUserIdOfMember((int) $data['member_id']);
        if (!$userId) {
            throw DeviceOwnerInvalid::noMainUser();
        }
        $data['user_id'] = $userId;
        unset($data['member_id']);

        $data['address_point_id'] = $this->addresses->resolveAddressPoint(
            $data['town_id'] ?? null,
            $data['street_id'] ?? null,
            $data['street_number'] ?? null
        );
        unset($data['town_id'], $data['street_id'], $data['street_number']);

        return $data;
    }

    /**
     * WPA2 klíč dává smysl jen u AP — u jiných typů se zahodí (i při přetypování
     * AP → switch). Působí jen když je `wpa_key` v datech (uživatel má právo na heslo).
     */
    public static function normalizeWpaKey(array $data): array
    {
        if (!array_key_exists('wpa_key', $data)) {
            return $data;
        }
        $key  = trim((string) ($data['wpa_key'] ?? ''));
        $isAp = (int) ($data['type'] ?? 0) === Device::AP_TYPE_ID;
        $data['wpa_key'] = ($isAp && $key !== '') ? $key : null;

        return $data;
    }
}
