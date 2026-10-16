<?php

namespace App\Domains\Network\Queries;

use App\Http\Filters\DeviceFilter;
use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Čtecí dotazy nad seznamy zařízení (read model). Řazení musí volající předat ověřené. */
class DeviceListQuery
{
    public const SORTS      = ['id', 'name', 'type', 'user_id', 'access_time'];
    public const PER_PAGES  = [50, 100, 150, 200, 250, 300, 350, 400, 450, 500];
    public const USER_SORTS = ['id', 'name'];

    /** Minuty bez stažení exportu, po kterých se DHCP server hlásí jako chybový. */
    private const DHCP_STALE_MINUTES = 5;

    public function paginate(string $search, array $advancedFilters, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        $query = Device::with(['user', 'enumType']);

        if ($search !== '') {
            // Stejná pole jako SearchController — název, MAC, IPv4, IPv6 a adresa
            // (town/street/č.p. + multi-token composite), aby odkaz „Otevřít všechny
            // v seznamu" z /search seděl.
            $like   = '%' . $search . '%';
            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $multi  = count($tokens) > 1;
            $query->where(function ($q) use ($like, $tokens, $multi) {
                $q->where('devices.name', 'like', $like);
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('ifaces as i')
                        ->whereColumn('i.device_id', 'devices.id')
                        ->where('i.mac', 'like', $like);
                });
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('ifaces as i')
                        ->join('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
                        ->whereColumn('i.device_id', 'devices.id')
                        ->where('ip.ip_address', 'like', $like);
                });
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('ifaces as i')
                        ->join('ip6_addresses as ip6', 'ip6.iface_id', '=', 'i.id')
                        ->whereColumn('i.device_id', 'devices.id')
                        ->where('ip6.ip_address', 'like', $like);
                });
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('address_points as ap')
                        ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                        ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                        ->whereColumn('ap.id', 'devices.address_point_id')
                        ->where(function ($qq) use ($like) {
                            $qq->where('t.town', 'like', $like)
                               ->orWhere('s.street', 'like', $like)
                               ->orWhere('ap.street_number', 'like', $like);
                        });
                });
                if ($multi) {
                    $q->orWhere(function ($qq) use ($tokens) {
                        foreach ($tokens as $t) {
                            $qq->whereExists(function ($sub) use ($t) {
                                $sub->from('address_points as ap')
                                    ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                                    ->leftJoin('towns as tn', 'tn.id', '=', 'ap.town_id')
                                    ->whereColumn('ap.id', 'devices.address_point_id')
                                    ->whereRaw(
                                        "CONCAT_WS(' ', devices.name, IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(tn.town,'')) LIKE ?",
                                        ['%' . $t . '%']
                                    );
                            });
                        }
                    });
                }
            });
        }

        if (!empty($advancedFilters)) {
            DeviceFilter::apply($query, $advancedFilters);
        }

        return $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Zařízení uživatele s typy, rozhraními, IP a podsítěmi. */
    public function forUser(int $userId, string $sort, string $dir): Collection
    {
        return Device::with(['enumType', 'ifaces.ipAddresses.subnet'])
            ->where('user_id', $userId)
            ->orderBy($sort, $dir)
            ->get();
    }

    /**
     * DHCP servery = zařízení s branou (gateway+dhcp) v podsíti se zapnutým DHCP.
     * s.dhcp = 1 filtruje vypnuté podsítě — jinak by zombie dhcp_expired=1 držel
     * zařízení trvale ve stavu „Změněno" (export flag u dhcp=0 nikdy nezresetuje).
     * error = poslední stažení exportu starší než 5 minut.
     */
    public function dhcpServers(): array
    {
        $devices = DB::select("
            SELECT DISTINCT d.id, d.name, d.access_time,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS subnets,
                MAX(s.dhcp_expired) AS has_expired
            FROM devices d
            JOIN ifaces i ON i.device_id = d.id
            JOIN ip_addresses ip ON ip.iface_id = i.id
            JOIN subnets s ON s.id = ip.subnet_id
            WHERE ip.gateway = 1 AND ip.dhcp = 1 AND s.dhcp = 1
            GROUP BY d.id, d.name, d.access_time
            ORDER BY d.access_time ASC
        ");

        return array_map(function ($device) {
            $device->error = $device->access_time &&
                $device->access_time !== '0000-00-00 00:00:00' &&
                Carbon::parse($device->access_time)->diffInMinutes(now()) > self::DHCP_STALE_MINUTES;
            return $device;
        }, $devices);
    }
}
