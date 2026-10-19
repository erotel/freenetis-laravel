<?php

namespace App\Domains\Network\Repositories;

use App\Http\Filters\SubnetFilter;
use App\Models\Subnet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Podsítě (subnets): přehled s počty IP, detail s IP adresami, CRUD. */
class SubnetRepository
{
    public function paginate(string $search, array $filters, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        $query = Subnet::withCount('ipAddresses');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('network_address', 'like', "%{$search}%");
            });
        }

        if (!empty($filters)) {
            SubnetFilter::apply($query, $filters);
        }

        return $query->orderBy($sort, $dir)->paginate($perPage)->withQueryString();
    }

    /**
     * S IP adresami (vlastník, rozhraní se zařízením a line-id). IP řadíme
     * číselně (INET_ATON), ne podle id/textu — jinak výpis vypadá náhodně
     * (…139.20, …139.125, …139.16). Sloupec je varchar IPv4, INET_ATON je bezpečné.
     */
    public function findWithIpAddressesOrFail(int $id): Subnet
    {
        return Subnet::with([
            'ipAddresses' => fn ($q) => $q->orderByRaw('INET_ATON(ip_address)'),
            'ipAddresses.member',
            'ipAddresses.iface.device',
            'ipAddresses.iface.lineId',
        ])->findOrFail($id);
    }

    public function findOrFail(int $id): Subnet
    {
        return Subnet::findOrFail($id);
    }

    public function create(array $data): Subnet
    {
        return Subnet::create($data);
    }

    public function update(Subnet $subnet, array $data): void
    {
        $subnet->update($data);
    }

    public function hasIpAddresses(Subnet $subnet): bool
    {
        return $subnet->ipAddresses()->exists();
    }

    public function delete(Subnet $subnet): void
    {
        $subnet->delete();
    }
}
