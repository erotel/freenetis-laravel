<?php

namespace App\Domains\Members\Repositories;

use App\Models\EnumType;
use App\Models\EnumTypeName;
use Illuminate\Support\Collection;

/** Číselník typů (enum_types) ve skupinách (enum_type_names): typy členů, zařízení, kontaktů, poplatků. */
class EnumTypeRepository
{
    /** Skupiny podle názvu, hodnoty podle abecedy. */
    public function groups(): Collection
    {
        return EnumTypeName::with(['enumTypes' => fn ($q) => $q->orderBy('value')])->orderBy('type_name')->get();
    }

    public function typeNames(): Collection
    {
        return EnumTypeName::orderBy('type_name')->get();
    }

    public function findOrFail(int $id): EnumType
    {
        return EnumType::findOrFail($id);
    }

    public function findWithTypeNameOrFail(int $id): EnumType
    {
        return EnumType::with('typeName')->findOrFail($id);
    }

    public function create(array $data): EnumType
    {
        return EnumType::create($data);
    }

    public function update(EnumType $type, array $data): void
    {
        $type->update($data);
    }

    public function delete(EnumType $type): void
    {
        $type->delete();
    }
}
