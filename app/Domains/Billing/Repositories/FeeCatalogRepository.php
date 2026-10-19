<?php

namespace App\Domains\Billing\Repositories;

use App\Models\Fee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Ceník poplatků / tarifů (fees): přehled s počty aktivních přiřazení, CRUD, archivace. */
class FeeCatalogRepository
{
    /** Archivované skryté (pokud je nechceme), u každého počet aktivních přiřazení k dnešku. */
    public function paginate(string $sort, string $dir, bool $withArchived, int $perPage): LengthAwarePaginator
    {
        $fees = Fee::with('enumType')
            ->when(!$withArchived, fn ($q) => $q->where('archived', false))
            ->orderBy($sort, $dir)
            ->paginate($perPage)->withQueryString();

        $today = now()->toDateString();
        $fees->getCollection()->each(function ($fee) use ($today) {
            $fee->active_count = $fee->activeAssignmentsCount($today);
            $fee->has_active   = $fee->active_count > 0;
        });
        return $fees;
    }

    public function archivedCount(): int
    {
        return Fee::where('archived', true)->count();
    }

    public function findOrFail(int $id): Fee
    {
        return Fee::findOrFail($id);
    }

    public function findWithTypeOrFail(int $id): Fee
    {
        return Fee::with('enumType')->findOrFail($id);
    }

    /** Členové s tarifem aktivním k dnešku. */
    public function activeMembers(int $feeId): Collection
    {
        $today = now()->toDateString();
        return DB::table('members_fees as mf')
            ->join('members as m', 'm.id', '=', 'mf.member_id')
            ->where('mf.fee_id', $feeId)
            ->where('mf.activation_date', '<=', $today)
            ->where('mf.deactivation_date', '>=', $today)
            ->orderBy('m.name')
            ->get(['m.id as member_id', 'm.name', 'm.type', 'mf.activation_date', 'mf.deactivation_date', 'mf.comment']);
    }

    public function create(array $data): Fee
    {
        return Fee::create($data + ['readonly' => 0]);
    }

    public function update(Fee $fee, array $data): void
    {
        $fee->update($data);
    }

    public function isAssigned(Fee $fee): bool
    {
        return $fee->memberFees()->exists();
    }

    public function delete(Fee $fee): void
    {
        $fee->delete();
    }
}
