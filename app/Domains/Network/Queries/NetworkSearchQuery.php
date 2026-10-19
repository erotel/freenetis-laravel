<?php

namespace App\Domains\Network\Queries;

use App\Domains\Members\Queries\MemberSearchQuery;
use App\Domains\Network\Support\MacAddress;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Globální hledání — zařízení (jméno, MAC i v jiném zápisu, IPv4, IPv6,
 * adresa umístění) a podsítě (jméno, adresa sítě).
 */
class NetworkSearchQuery
{
    /** @return array{0: Collection, 1: int} [max $limit řádků, celkem] */
    public function devices(string $query, int $limit): array
    {
        $tokens = MemberSearchQuery::tokens($query);

        $qb = $this->devicesBase($query, function ($q) use ($tokens) {
            if (count($tokens) > 1) {
                MemberSearchQuery::allTokens($q, $tokens, "CONCAT_WS(' ', d.name, IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(t.town,''))");
            }
        })
            ->select('d.id', 'd.name as device_name', 'i.mac', 'ip.ip_address', 'ip6.ip_address as ipv6_address',
                     'm.id as member_id', 'm.name as member_name', 's.street', 'ap.street_number', 't.town')
            ->distinct();

        return [(clone $qb)->limit($limit)->get(), (clone $qb)->count('d.id')];
    }

    /** Našeptávač (bez víceslovné adresy). */
    public function devicesQuick(string $query, int $limit): Collection
    {
        return $this->devicesBase($query, fn () => null)
            ->select('d.id', 'd.name as device_name', 'i.mac', 'ip.ip_address', 'ip6.ip_address as ipv6_address', 'm.id as member_id', 'm.name as member_name')
            ->distinct()->limit($limit)->get();
    }

    /** @return array{0: Collection, 1: int} */
    public function subnets(string $query, int $limit): array
    {
        $like = '%' . $query . '%';
        $qb = DB::table('subnets')
            ->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)->orWhere('network_address', 'LIKE', $like);
            })
            ->select('id', 'name', 'network_address', 'netmask');

        return [(clone $qb)->limit($limit)->get(), (clone $qb)->count()];
    }

    private function devicesBase(string $query, \Closure $extra): Builder
    {
        $like    = '%' . $query . '%';
        $macNorm = MacAddress::normalize($query);

        return DB::table('devices as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('members as m', 'm.id', '=', 'u.member_id')
            ->leftJoin('ifaces as i', 'i.device_id', '=', 'd.id')
            ->leftJoin('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
            ->leftJoin('ip6_addresses as ip6', 'ip6.iface_id', '=', 'i.id')
            ->leftJoin('address_points as ap', 'ap.id', '=', 'd.address_point_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->where(function ($q) use ($like, $macNorm, $extra) {
                $q->where('d.name', 'LIKE', $like)
                  ->orWhere('i.mac', 'LIKE', $like)
                  ->orWhere('ip.ip_address', 'LIKE', $like)
                  ->orWhere('ip6.ip_address', 'LIKE', $like)
                  ->orWhere('t.town', 'LIKE', $like)
                  ->orWhere('s.street', 'LIKE', $like)
                  ->orWhere('ap.street_number', 'LIKE', $like);
                if ($macNorm !== null) {
                    $q->orWhere('i.mac', '=', $macNorm);
                }
                $extra($q);
            });
    }
}
