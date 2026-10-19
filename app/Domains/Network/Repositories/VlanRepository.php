<?php

namespace App\Domains\Network\Repositories;

use App\Models\Vlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** VLAN (vlans) a jejich přiřazení k rozhraním (ifaces_vlans). */
class VlanRepository
{
    /** S počtem rozhraní; hledá v názvu / komentáři, číslo i v id a tagu. */
    public function paginate(string $q, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        $query = Vlan::withCount('ifaces');

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('name', 'like', $like)
                  ->orWhere('comment', 'like', $like);
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q)
                      ->orWhere('tag_802_1q', (int) $q);
                }
            });
        }

        return $query->orderBy($sort, $dir)->paginate($perPage)->withQueryString();
    }

    public function findOrFail(int $id): Vlan
    {
        return Vlan::findOrFail($id);
    }

    public function findWithIfacesOrFail(int $id): Vlan
    {
        return Vlan::with(['ifaces.device'])->findOrFail($id);
    }

    public function create(array $data): Vlan
    {
        return Vlan::create($data);
    }

    public function update(Vlan $vlan, array $data): void
    {
        $vlan->update($data);
    }

    public function hasIfaces(Vlan $vlan): bool
    {
        return $vlan->ifaces()->exists();
    }

    public function delete(Vlan $vlan): void
    {
        $vlan->delete();
    }
}
