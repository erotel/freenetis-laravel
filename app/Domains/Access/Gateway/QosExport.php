<?php

namespace App\Domains\Access\Gateway;

use App\Domains\Access\Repositories\GatewayExportRepository;
use App\Domains\Network\Support\Ip6FromIpv4;
use App\Models\Setting;

/**
 * QoS export pro brány: rychlostní profily (kbit = rate / 1e6 * 1024) a členové
 * s IP. Efektivní profil IP = rychlost jejího přípojného místa (allowed_subnets),
 * jinak rychlost člena; bez rychlosti IP do QoS nepatří. member.profile_id +
 * ploché ipv4/ipv6 zůstávají pro starší brány, assignments[] = profil per IP.
 * IPv6 /56 se odvozuje z IPv4 (sdílí tak přípojné místo i rychlost). Sdružení
 * (člen 1) se neexportuje.
 */
class QosExport
{
    private const ASSOCIATION_MEMBER_ID = 1;

    public function __construct(private GatewayExportRepository $repo)
    {
    }

    /** @return array{profiles: array, members: array} */
    public function build(): array
    {
        $profiles = [];
        foreach ($this->repo->speedClasses() as $sc) {
            $profiles[] = [
                'id'             => (int) $sc->id,
                'name'           => (string) $sc->name,
                'up_kbit'        => (int) ($sc->u_rate  / 1000000 * 1024),
                'down_kbit'      => (int) ($sc->d_rate  / 1000000 * 1024),
                'up_ceil_kbit'   => (int) ($sc->u_ceil  / 1000000 * 1024),
                'down_ceil_kbit' => (int) ($sc->d_ceil  / 1000000 * 1024),
            ];
        }

        $memberSpeed = $this->repo->memberSpeeds();
        $placeSpeed  = $this->repo->placeSpeeds();

        // prázdná hodnota v DB (legacy import) → default, ne ":Y:Z00::/"
        $v6prefix = Setting::get('ipv6_prefix') ?: '2a07:9c0';
        $v6mask   = Setting::get('ipv6_mask')   ?: '56';

        $members = [];
        $seen    = []; // jeden vlastník na IP

        foreach ($this->repo->ownedIps(self::ASSOCIATION_MEMBER_ID) as $r) {
            $ipStr = (string) $r->ip_address;
            if ($ipStr === '' || isset($seen[$ipStr])) {
                continue;
            }
            $mid = (int) $r->member_id;

            $profile = $placeSpeed[$mid . '-' . $r->subnet_id]
                ?? (isset($memberSpeed[$mid]) ? (int) $memberSpeed[$mid] : null);
            if ($profile === null) {
                continue;
            }
            $seen[$ipStr] = true;

            $members[$mid] ??= [
                'member_id'   => $mid,
                'profile_id'  => isset($memberSpeed[$mid]) ? (int) $memberSpeed[$mid] : $profile,
                'ipv4'        => [],
                'ipv6'        => [],
                'assignments' => [],
            ];
            $members[$mid]['assignments'][$profile] ??= ['profile_id' => $profile, 'ipv4' => [], 'ipv6' => []];

            $members[$mid]['ipv4'][] = $ipStr;
            $members[$mid]['assignments'][$profile]['ipv4'][] = $ipStr;

            $v6 = Ip6FromIpv4::deriveWith($ipStr, $v6prefix, $v6mask);
            if ($v6 !== null) {
                $members[$mid]['ipv6'][] = $v6;
                $members[$mid]['assignments'][$profile]['ipv6'][] = $v6;
            }
        }

        // víc IPv4 může mapovat na stejnou /56
        $members = array_map(function ($m) {
            $m['ipv6'] = array_values(array_unique($m['ipv6']));
            $m['assignments'] = array_values(array_map(function ($a) {
                $a['ipv6'] = array_values(array_unique($a['ipv6']));
                return $a;
            }, $m['assignments']));
            return $m;
        }, array_values($members));

        return ['profiles' => $profiles, 'members' => $members];
    }
}
