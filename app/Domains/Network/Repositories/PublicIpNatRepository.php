<?php

namespace App\Domains\Network\Repositories;

use App\Models\PublicIpNat1to1;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** 1:1 NAT veřejných IP (public_ip_nat_1to1) — mapování veřejná → privátní IP, exportuje se na brány. */
class PublicIpNatRepository
{
    /** Zapnuté první, pak podle veřejné IP; s vlastníkem privátní IP a autory změn. */
    public function search(string $enabled, string $q): Collection
    {
        $query = DB::table('public_ip_nat_1to1 AS n')
            ->leftJoin('ip_addresses AS ip', DB::raw('ip.ip_address COLLATE utf8mb3_uca1400_ai_ci'), '=', 'n.private_ip')
            ->leftJoin('ifaces AS i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices AS d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users AS u', 'u.id', '=', 'd.user_id')
            ->leftJoin('members AS om', 'om.id', '=', 'u.member_id')
            ->leftJoin('members AS mm', 'mm.id', '=', 'n.modified_by')
            ->leftJoin('members AS mc', 'mc.id', '=', 'n.created_by')
            ->select(
                'n.id', 'n.public_ip', 'n.private_ip', 'n.scope',
                'n.enabled', 'n.comment', 'n.created', 'n.modified',
                'om.name AS owner_member_name',
                'mm.name AS modified_by_name',
                'mc.name AS created_by_name'
            )
            ->orderByRaw('n.enabled DESC, INET_ATON(n.public_ip) ASC');

        if ($enabled === '1' || $enabled === '0') {
            $query->where('n.enabled', (int) $enabled);
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('n.public_ip', 'like', "%{$q}%")
                    ->orWhere('n.private_ip', 'like', "%{$q}%")
                    ->orWhere('om.name', 'like', "%{$q}%");
            });
        }

        return $query->get();
    }

    public function findOrFail(int $id): PublicIpNat1to1
    {
        return PublicIpNat1to1::findOrFail($id);
    }

    /** $memberId = kdo měnil (člen přihlášeného uživatele). */
    public function update(PublicIpNat1to1 $record, array $values, ?int $memberId): void
    {
        $record->update($values + ['modified' => now(), 'modified_by' => $memberId]);
    }
}
