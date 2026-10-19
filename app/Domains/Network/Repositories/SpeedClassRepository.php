<?php

namespace App\Domains\Network\Repositories;

use App\Models\SpeedClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Třídy rychlosti (speed_classes): QoS rate/ceil, ceníková cena, výchozí pro člena / žadatele. */
class SpeedClassRepository
{
    public function allWithMemberCounts(): Collection
    {
        return SpeedClass::withCount('members')->get();
    }

    public function findOrFail(int $id): SpeedClass
    {
        return SpeedClass::findOrFail($id);
    }

    public function findWithMemberCountOrFail(int $id): SpeedClass
    {
        return SpeedClass::withCount('members')->findOrFail($id);
    }

    public function members(int $id): Collection
    {
        return DB::table('members as m')->where('m.speed_class_id', $id)->orderBy('m.name')->get(['m.id', 'm.name', 'm.type']);
    }

    public function create(array $values): void
    {
        SpeedClass::create($values + ['regular_member_default' => false, 'applicant_default' => false]);
    }

    public function update(SpeedClass $speedClass, array $values): void
    {
        $speedClass->update($values);
    }

    /**
     * Kde jinde než u členů je třída použitá: přípojná místa s vlastní
     * rychlostí a nepodepsané dodatky ke smlouvě, které ji teprve nastaví.
     * @return array{places: int, addons: int}
     */
    public function otherUsage(int $id): array
    {
        return [
            'places' => DB::table('allowed_subnets')->where('speed_class_id', $id)->count(),
            'addons' => DB::connection('contracts')->table('contract_addons')->where('new_speed_class_id', $id)->where('status', '!=', 'signed')->count(),
        ];
    }

    public function delete(SpeedClass $speedClass): void
    {
        $speedClass->delete();
    }

    /** Přepne výchozí třídu ($column regular_member_default / applicant_default) — jediná na sloupec. */
    public function toggleDefault(SpeedClass $speedClass, string $column): void
    {
        if ($speedClass->$column) {
            $speedClass->update([$column => false]);
            return;
        }
        SpeedClass::where('id', '!=', $speedClass->id)->update([$column => false]);
        $speedClass->update([$column => true]);
    }

    public function allByName(): Collection
    {
        return SpeedClass::orderBy('name')->get();
    }

    /** Výchozí třída pro řádného člena. */
    public function memberDefault(): ?SpeedClass
    {
        return SpeedClass::where('regular_member_default', true)->first();
    }
}
