<?php

namespace App\Domains\Network\Repositories;

use App\Models\PublicIp;
use App\Models\PublicPortForward;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Port forwardy z veřejných IP (public_port_forwards) — exportují se branám (Access/Gateway). */
class PublicPortForwardRepository
{
    /** Veřejné IP, ze kterých lze forwardovat. @return string[] */
    public function allowedPublicIps(): array
    {
        return PublicIp::allowedIps();
    }

    /** Přehled s vlastníkem cílové IP a autory; hledání IP / protokol / vlastník / port v rozsahu / id. */
    public function search(string $q): Collection
    {
        $query = DB::table('public_port_forwards AS p')
            ->leftJoin('ip_addresses AS ip', DB::raw('ip.ip_address COLLATE utf8mb3_uca1400_ai_ci'), '=', 'p.private_ip')
            ->leftJoin('ifaces AS i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices AS d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users AS u', 'u.id', '=', 'd.user_id')
            ->leftJoin('members AS om', 'om.id', '=', 'u.member_id')
            ->leftJoin('members AS mm', 'mm.id', '=', 'p.modified_by')
            ->leftJoin('members AS mc', 'mc.id', '=', 'p.created_by')
            ->select(
                'p.id', 'p.public_ip', 'p.public_port_from', 'p.public_port_to',
                'p.private_ip', 'p.private_port_from', 'p.private_port_to',
                'p.protocol', 'p.enabled', 'p.created', 'p.modified',
                'om.name AS owner_member_name', 'mm.name AS modified_by_name', 'mc.name AS created_by_name'
            )
            ->orderByRaw('INET_ATON(p.public_ip) ASC, p.protocol ASC, p.public_port_from ASC');

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('p.public_ip', 'like', $like)
                  ->orWhere('p.private_ip', 'like', $like)
                  ->orWhere('p.protocol', 'like', $like)
                  ->orWhere('om.name', 'like', $like);
                if (ctype_digit($q)) {
                    $port = (int) $q;
                    $w->orWhere('p.id', $port)
                      ->orWhere(fn ($pp) => $pp->where('p.public_port_from', '<=', $port)->where('p.public_port_to', '>=', $port))
                      ->orWhere(fn ($pp) => $pp->where('p.private_port_from', '<=', $port)->where('p.private_port_to', '>=', $port));
                }
            });
        }
        return $query->get();
    }

    /** Překrývá se rozsah s jiným zapnutým forwardem na stejné veřejné IP a protokolu? */
    public function collides(array $data, int $ignoreId = 0): bool
    {
        return DB::table('public_port_forwards')
            ->where('public_ip', $data['public_ip'])
            ->where('protocol', $data['protocol'])
            ->where('enabled', 1)
            ->where('id', '!=', $ignoreId)
            ->where('public_port_from', '<=', $data['public_port_to'])
            ->where('public_port_to', '>=', $data['public_port_from'])
            ->exists();
    }

    public function findOrFail(int $id): PublicPortForward
    {
        return PublicPortForward::findOrFail($id);
    }

    public function create(array $data, ?int $memberId): void
    {
        PublicPortForward::create($data + ['created' => now(), 'created_by' => $memberId]);
    }

    public function update(PublicPortForward $forward, array $data, ?int $memberId): void
    {
        $forward->update($data + ['modified' => now(), 'modified_by' => $memberId]);
    }

    public function delete(PublicPortForward $forward): void
    {
        $forward->delete();
    }
}
