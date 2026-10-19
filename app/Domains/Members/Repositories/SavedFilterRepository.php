<?php

namespace App\Domains\Members\Repositories;

use App\Models\SavedFilter;
use Illuminate\Support\Collection;

/** Uložené filtry seznamů — jen vlastní uživatele (user_id + page + jméno). */
class SavedFilterRepository
{
    public function forPage(int $userId, string $page): Collection
    {
        return SavedFilter::where('user_id', $userId)->where('page', $page)->orderBy('name')->get(['id', 'name', 'filters'])
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'filters' => $f->filters]);
    }

    /** Uloží; stejné jméno na stejné stránce přepíše. */
    public function save(int $userId, string $page, string $name, array $filters): SavedFilter
    {
        $existing = SavedFilter::where('user_id', $userId)->where('page', $page)->where('name', $name)->first();
        if ($existing) {
            $existing->update(['filters' => $filters]);
            return $existing;
        }
        return SavedFilter::create(['user_id' => $userId, 'name' => $name, 'page' => $page, 'filters' => $filters]);
    }

    public function deleteOwnOrFail(int $userId, int $id): void
    {
        SavedFilter::where('id', $id)->where('user_id', $userId)->firstOrFail()->delete();
    }
}
