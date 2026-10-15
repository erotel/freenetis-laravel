<?php

namespace App\Domains\Members\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Zařízení a adresy člena (devices → ifaces → ip_addresses/ip6_addresses,
 * přímé ip_addresses.member_id, subnets_owners) — pro úklid po odchodu.
 */
class MemberDeviceRepository
{
    /**
     * IPv4 adresy člena: přímo přiřazené (ip_addresses.member_id) i na
     * rozhraních jeho zařízení. Unikátní, seřazené (řetězcově).
     *
     * @return string[]
     */
    public function ipAddresses(int $memberId): array
    {
        $direct = DB::table('ip_addresses')
            ->where('member_id', $memberId)
            ->whereNotNull('ip_address')
            ->pluck('ip_address');

        $onDevices = DB::table('ip_addresses as ia')
            ->join('ifaces as i', 'i.id', '=', 'ia.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('u.member_id', $memberId)
            ->whereNotNull('ia.ip_address')
            ->pluck('ia.ip_address');

        return $direct->merge($onDevices)->unique()->sort()->values()->all();
    }

    /** Řádek na začátek members.comment (sloupec drží max 250 znaků). */
    public function prependComment(int $memberId, string $line): void
    {
        DB::statement("
            UPDATE members
            SET comment = LEFT(
                CONCAT(?, IF(comment IS NULL OR comment = '', '', '\n'), IFNULL(comment, '')),
                250
            )
            WHERE id = ?
        ", [$line, $memberId]);
    }

    /**
     * Smaže zařízení uživatelů člena (s rozhraními a adresami), přímo
     * přiřazené IP a vlastnictví podsítí. Idempotentní. Vrací počet zařízení.
     */
    public function deleteAll(int $memberId): int
    {
        $count = $this->deleteDevices($memberId);

        DB::table('ip_addresses')->where('member_id', $memberId)->delete();
        DB::table('subnets_owners')->where('member_id', $memberId)->delete();

        return $count;
    }

    /**
     * Jen zařízení uživatelů člena (devices → ifaces → ip/ip6 adresy). Přímo
     * přiřazené IP ani vlastnictví podsítí nemaže. Vrací počet zařízení.
     */
    public function deleteDevices(int $memberId): int
    {
        $userIds   = DB::table('users')->where('member_id', $memberId)->pluck('id');
        $deviceIds = DB::table('devices')->whereIn('user_id', $userIds)->pluck('id');
        foreach ($deviceIds as $deviceId) {
            $ifaceIds = DB::table('ifaces')->where('device_id', $deviceId)->pluck('id');
            foreach ($ifaceIds as $ifaceId) {
                DB::table('ip6_addresses')->where('iface_id', $ifaceId)->delete();
                DB::table('ip_addresses')->where('iface_id', $ifaceId)->delete();
            }
            DB::table('ifaces')->where('device_id', $deviceId)->delete();
        }
        DB::table('devices')->whereIn('user_id', $userIds)->delete();

        return $deviceIds->count();
    }
}
