<?php

namespace App\Domains\Members\Repositories;

use App\Models\Street;
use App\Models\Town;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Číselník adres: obce (towns) a ulice (streets), statistika adresních bodů. */
class AddressBookRepository
{
    public function towns(string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        return Town::orderBy($sort, $dir)->paginate($perPage)->withQueryString();
    }

    public function streets(string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        return Street::with('town')->orderBy($sort, $dir)->paginate($perPage)->withQueryString();
    }

    public function town(int $id): ?Town
    {
        return Town::find($id);
    }

    public function street(int $id, bool $withTown = false): ?Street
    {
        return Street::when($withTown, fn ($q) => $q->with('town'))->find($id);
    }

    public function townsForSelect(): Collection
    {
        return Town::orderBy('town')->orderBy('quarter')->orderBy('zip_code')->get();
    }

    /**
     * Adresní body obce / ulice: celkem, „prázdné" (bez člena, zařízení
     * i bydliště — kandidáti na úklid) a počet členů.
     * @param string $column town_id | street_id
     * @return array{0: int, 1: int, 2: int}
     */
    public function addressPointStats(string $column, int $id): array
    {
        $total  = DB::table('address_points')->where($column, $id)->count();
        $orphan = DB::table('address_points')->where($column, $id)
            ->whereNotExists(fn ($q) => $q->from('members')->whereColumn('members.address_point_id', 'address_points.id'))
            ->whereNotExists(fn ($q) => $q->from('devices')->whereColumn('devices.address_point_id', 'address_points.id'))
            ->whereNotExists(fn ($q) => $q->from('members_domiciles')->whereColumn('members_domiciles.address_point_id', 'address_points.id'))
            ->count();
        $members = DB::table('members')->join('address_points as ap', 'ap.id', '=', 'members.address_point_id')->where('ap.' . $column, $id)->count();
        return [$total, $orphan, $members];
    }

    public function createTown(array $data): void
    {
        Town::create($data);
    }

    public function createStreet(array $data): void
    {
        Street::create($data);
    }

    /** Obce podle názvu (formulář člena). */
    public function townsByName(): Collection
    {
        return Town::orderBy('town')->get();
    }

    /** Ulice podle názvu (formulář člena). */
    public function streetsByName(): Collection
    {
        return Street::orderBy('street')->get();
    }
}
