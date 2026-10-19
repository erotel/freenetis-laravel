<?php

namespace App\Domains\Access\Repositories;

use App\Models\ConnectionRequest;
use App\Models\DeviceTemplate;
use App\Models\EnumType;
use App\Models\Setting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Žádosti o připojení (connection_requests) a volnost IP pro novou žádost. */
class ConnectionRequestRepository
{
    /**
     * Podsíť, do které IP patří, pokud je IP v ní volná (bez ip_addresses) a nemá
     * čekající žádost. null = IP není v žádné podsíti / je obsazená / čeká.
     *
     * Samoobslužné žádosti mohou mít ip_address NULL — v NOT IN se vynechávají
     * (NULL v seznamu by podmínku pro všechny IP zneplatnil).
     */
    public function freeSubnetFor(string $ip): ?int
    {
        $row = DB::selectOne("
            SELECT s.subnet_id FROM (
                SELECT s.id AS subnet_id
                FROM subnets s
                WHERE inet_aton(s.netmask) & inet_aton(?) = inet_aton(s.network_address)
            ) s
            LEFT JOIN ip_addresses ip ON ip.subnet_id = s.subnet_id
                AND inet_aton(ip.ip_address) = inet_aton(?)
            WHERE ? NOT IN (
                SELECT cr.ip_address FROM connection_requests cr WHERE cr.state = ? AND cr.ip_address IS NOT NULL
            )
            GROUP BY s.subnet_id
            HAVING COUNT(ip.id) = 0
        ", [$ip, $ip, $ip, ConnectionRequest::STATE_UNDECIDED]);

        return $row ? (int) $row->subnet_id : null;
    }

    public function hasPendingForIp(string $ip): bool
    {
        return ConnectionRequest::where('ip_address', $ip)->where('state', ConnectionRequest::STATE_UNDECIDED)->exists();
    }

    /** Nová čekající žádost. */
    public function createPending(array $attributes): ConnectionRequest
    {
        return ConnectionRequest::create($attributes + [
            'decided_user_id'    => null,
            'state'              => ConnectionRequest::STATE_UNDECIDED,
            'created_at'         => now(),
            'decided_at'         => null,
            'device_id'          => null,
            'comments_thread_id' => null,
        ]);
    }

    /** Nejnovější první; $memberId = jen žádosti člena (view_own). */
    public function paginate(?int $memberId, bool $onlyMember, ?int $state, ?string $ip, int $perPage): LengthAwarePaginator
    {
        return ConnectionRequest::with(['member', 'subnet', 'addedUser'])
            ->orderByDesc('id')
            ->when($onlyMember, fn ($q) => $q->where('member_id', $memberId))
            ->when($state !== null, fn ($q) => $q->where('state', $state))
            ->when($ip, fn ($q) => $q->where('ip_address', 'like', '%' . $ip . '%'))
            ->paginate($perPage)->withQueryString();
    }

    public function ofMember(int $memberId): Collection
    {
        return ConnectionRequest::with(['subnet', 'addedUser'])
            ->where('member_id', $memberId)
            ->orderByDesc('id')
            ->get();
    }

    public function findOrFail(int $id, array $with = []): ConnectionRequest
    {
        return ConnectionRequest::with($with)->findOrFail($id);
    }

    /** Typy zařízení pro žádost (id => název), omezené nastavením connection_request_device_types. */
    public function deviceTypes(): array
    {
        $types = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)
            ->orderBy('value')
            ->pluck('value', 'id')
            ->toArray();

        $allowed = Setting::get('connection_request_device_types');
        if ($allowed) {
            $ids = explode(':', $allowed);
            $types = array_filter($types, fn ($id) => in_array($id, $ids), ARRAY_FILTER_USE_KEY);
        }
        return $types;
    }

    public function deviceTemplates(): Collection
    {
        return DeviceTemplate::orderBy('name')->get(['id', 'name', 'enum_type_id']);
    }
}
