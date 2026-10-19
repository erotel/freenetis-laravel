<?php

namespace App\Domains\Access\Repositories;

use App\Models\Member;
use App\Models\Ont;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Evidence GPON ONT (onts) — stav registrace na OLT, zákazník, poloha. */
class OntRepository
{
    public const STATUSES = ['new', 'registered', 'removed'];

    /** Nejnovější první; neznámý stav = všechny. */
    public function paginate(?string $status, int $perPage): LengthAwarePaginator
    {
        return Ont::with(['member', 'addedBy'])->orderByDesc('created_at')
            ->when($status && in_array($status, self::STATUSES, true), fn ($q) => $q->where('reg_status', $status))
            ->paginate($perPage)->withQueryString();
    }

    /** @return array{new:int, registered:int, removed:int} */
    public function countsByStatus(): array
    {
        return [
            'new'        => Ont::newOnts()->count(),
            'registered' => Ont::registered()->count(),
            'removed'    => Ont::removed()->count(),
        ];
    }

    public function findOrFail(int $id): Ont
    {
        return Ont::findOrFail($id);
    }

    public function findWithRelationsOrFail(int $id): Ont
    {
        return Ont::with(['member', 'device', 'addedBy'])->findOrFail($id);
    }

    /** Zákazníci k přiřazení (typy člena 2 a 18). */
    public function customers(): Collection
    {
        return Member::whereIn('type', [2, 18])->orderBy('name')->get(['id', 'name']);
    }

    public function setMember(Ont $ont, ?int $memberId): void
    {
        $ont->member_id = $memberId;
        $ont->save();
    }

    /** Po registraci se zákazníkem jméno uživatele (kdo registroval) nahradí člen. */
    public function assignRegisteredMember(int $id, int $memberId): void
    {
        Ont::findOrFail($id)->update(['member_id' => $memberId, 'user_name' => null]);
    }

    public function delete(Ont $ont): void
    {
        $ont->delete();
    }

    /** Registrované ONT s polohou pro mapu. */
    public function mapped(): Collection
    {
        return Ont::whereNotNull('gps_lat')
            ->whereNotNull('gps_lng')
            ->where('reg_status', 'registered')
            ->get(['id', 'serial', 'house_no', 'user_name', 'gps_lat', 'gps_lng', 'gpon_port', 'port_index', 'ont_id', 'olt_ip']);
    }
}
