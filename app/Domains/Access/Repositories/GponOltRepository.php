<?php

namespace App\Domains\Access\Repositories;

use App\Models\GponOlt;
use Illuminate\Support\Collection;

/** GPON OLT (Nastavení → GPON); SNMP hesla šifruje model. */
class GponOltRepository
{
    public function allWithOntCounts(): Collection
    {
        return GponOlt::withCount('onts')->get();
    }

    public function exists(int $id): bool
    {
        return GponOlt::whereKey($id)->exists();
    }

    public function create(array $data): GponOlt
    {
        return GponOlt::create($data);
    }

    public function update(int $id, array $data): void
    {
        GponOlt::findOrFail($id)->update($data);
    }

    /** Smaže OLT bez registrovaných / nových ONT. @return bool false = má ONT */
    public function deleteUnlessUsed(int $id): bool
    {
        $olt = GponOlt::findOrFail($id);
        if ($olt->onts()->whereIn('reg_status', ['registered', 'new'])->exists()) {
            return false;
        }
        $olt->delete();
        return true;
    }
}
