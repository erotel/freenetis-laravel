<?php

namespace App\Domains\Network\Repositories;

use App\Models\AllowedSubnet;
use App\Models\AllowedSubnetsCount;
use App\Models\Setting;
use App\Models\SpeedClass;
use App\Models\Subnet;
use Illuminate\Support\Collection;

/**
 * Povolené podsítě člena = přípojná místa (allowed_subnets): zapnutí,
 * vlastní rychlost (NULL = rychlost člena), účtování; limit zapnutých
 * (allowed_subnets_counts, jinak globální allowed_subnets_default_count;
 * 0 = neomezeno).
 */
class AllowedSubnetRepository
{
    public function find(int $id): ?AllowedSubnet
    {
        return AllowedSubnet::find($id);
    }

    public function findOrFail(int $id): AllowedSubnet
    {
        return AllowedSubnet::findOrFail($id);
    }

    /** Místa člena podle id (stabilní pořadí — přepnutí řádky nepřehází). */
    public function forMember(int $memberId): Collection
    {
        return AllowedSubnet::where('member_id', $memberId)->with(['subnet', 'speedClass'])->orderBy('id')->get();
    }

    public function exists(int $memberId, int $subnetId): bool
    {
        return AllowedSubnet::where('member_id', $memberId)->where('subnet_id', $subnetId)->exists();
    }

    /** Podsítě, které člen ještě nemá, podle jména. */
    public function subnetsExcept(Collection $subnetIds): Collection
    {
        return Subnet::whereNotIn('id', $subnetIds)->orderBy('name')->get();
    }

    public function speedClasses(): Collection
    {
        return SpeedClass::orderByDesc('d_ceil')->get();
    }

    public function globalLimit(): int
    {
        return (int) Setting::get('allowed_subnets_default_count', 1);
    }

    public function hasOwnLimit(int $memberId): bool
    {
        return AllowedSubnetsCount::where('member_id', $memberId)->exists();
    }

    /** Limit zapnutých míst: vlastní, jinak globální. 0 = neomezeno. */
    public function limit(int $memberId): int
    {
        $row = AllowedSubnetsCount::firstWhere('member_id', $memberId);
        return $row ? (int) $row->count : $this->globalLimit();
    }

    public function setLimit(int $memberId, int $count): void
    {
        AllowedSubnetsCount::updateOrCreate(['member_id' => $memberId], ['count' => $count]);
    }

    public function clearLimit(int $memberId): void
    {
        AllowedSubnetsCount::where('member_id', $memberId)->delete();
    }

    public function create(int $memberId, int $subnetId): AllowedSubnet
    {
        return AllowedSubnet::create(['member_id' => $memberId, 'subnet_id' => $subnetId, 'enabled' => true]);
    }

    public function enabledCount(int $memberId): int
    {
        return AllowedSubnet::where('member_id', $memberId)->where('enabled', true)->count();
    }

    /** Nejstarší zapnuté místo člena (volitelně kromě jednoho). */
    public function oldestEnabled(int $memberId, ?int $exceptId = null): ?AllowedSubnet
    {
        return AllowedSubnet::where('member_id', $memberId)
            ->where('enabled', true)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->orderBy('id', 'asc')
            ->first();
    }

    public function update(AllowedSubnet $as, array $values): void
    {
        $as->update($values);
    }

    public function delete(AllowedSubnet $as): void
    {
        $as->delete();
    }
}
