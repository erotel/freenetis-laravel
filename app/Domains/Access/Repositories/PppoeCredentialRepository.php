<?php

namespace App\Domains\Access\Repositories;

use App\Models\ConnectionRequest;
use App\Models\Iface;
use App\Models\Member;
use App\Models\PppoeSecret;
use Illuminate\Support\Facades\DB;

/**
 * PPPoE credentialy per přípojka (`pppoe_secrets`, klíč iface_id) + čekající
 * credentialy na žádostech o připojení (`connection_requests.pppoe_*`).
 *
 * KONTRAKT: nad `pppoe_secrets` (enabled=1) UNION čekající žádosti stojí RADIUS
 * views radcheck/radreply_pppoe_v → username musí být unikátní napříč OBĚMA.
 */
class PppoeCredentialRepository
{
    public function find(int $ifaceId): ?PppoeSecret
    {
        return PppoeSecret::find($ifaceId);
    }

    public function create(int $ifaceId, string $username, string $secret): PppoeSecret
    {
        return PppoeSecret::create([
            'iface_id' => $ifaceId,
            'username' => $username,
            'secret'   => $secret,
            'enabled'  => true,
        ]);
    }

    public function update(PppoeSecret $credential, array $values): void
    {
        $credential->update($values);
    }

    /** Credentialy daných přípojek, klíčované iface_id. */
    public function forIfaces(iterable $ifaceIds): \Illuminate\Support\Collection
    {
        return PppoeSecret::whereIn('iface_id', collect($ifaceIds))->get()->keyBy('iface_id');
    }

    /** Množina iface_id, které už credential mají (klíče pole = iface_id). */
    public function existingIfaceIds(): array
    {
        return PppoeSecret::pluck('iface_id')->flip()->all();
    }

    /**
     * Zákaznické přípojky: rozhraní s klientskou IP (gateway=0, ne service)
     * u zařízení člena; asociace (member 1) = infrastruktura, ne zákazník.
     */
    public function customerConnectionIfaceIds(): \Illuminate\Support\Collection
    {
        return DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('ip.gateway', 0)
            ->where('ip.service', 0)
            ->whereNotNull('u.member_id')
            ->where('u.member_id', '!=', 1)
            ->distinct()
            ->pluck('i.id');
    }

    /** Založí/přepíše credential přípojky a zapne ho (idempotentní přes iface_id). */
    public function upsertEnabled(int $ifaceId, string $username, string $secret): PppoeSecret
    {
        return PppoeSecret::updateOrCreate(
            ['iface_id' => $ifaceId],
            ['username' => $username, 'secret' => $secret, 'enabled' => true]
        );
    }

    /** Username obsazený v pppoe_secrets (mimo $exceptIfaceId) nebo na čekající žádosti. */
    public function usernameTaken(string $username, ?int $exceptIfaceId = null): bool
    {
        $inSecrets = PppoeSecret::where('username', $username)
            ->when($exceptIfaceId !== null, fn ($q) => $q->where('iface_id', '!=', $exceptIfaceId))
            ->exists();
        if ($inSecrets) {
            return true;
        }
        return DB::table('connection_requests')
            ->where('pppoe_username', $username)
            ->where('state', ConnectionRequest::STATE_UNDECIDED)
            ->exists();
    }

    /** member_id z iface → device → user. Null když chybí kterýkoli článek. */
    public function memberIdForIface(Iface $iface): ?int
    {
        $iface->loadMissing('device.user');
        $mid = $iface->device?->user?->member_id;
        return $mid ? (int) $mid : null;
    }

    /**
     * Variabilní symbol člena (první VS prvního účtu), fallback member_id.
     * Stejný vzor jako InvoiceService/ContractService.
     */
    public function variableSymbol(int $memberId): string
    {
        $member = Member::with('accounts.variableSymbols')->find($memberId);
        $vs = $member?->accounts
            ->flatMap(fn ($a) => $a->variableSymbols)
            ->first()?->variable_symbol;

        return (string) ($vs ?: $memberId);
    }

    /**
     * Credential, který žadatel dostal se žádostí o připojení (instalátor ho
     * zadal do CPE). Null = žádost neexistuje nebo credential nemá.
     *
     * @return array{username: string, secret: string}|null
     */
    public function requestCredential(int $connectionRequestId): ?array
    {
        $cr = ConnectionRequest::find($connectionRequestId);
        if (!$cr || !$cr->pppoe_username || !$cr->pppoe_secret) {
            return null;
        }
        return ['username' => $cr->pppoe_username, 'secret' => $cr->pppoe_secret];
    }

    /**
     * Přípojná rozhraní zařízení = ta s IP gateway=0 (zákaznická strana).
     *
     * @return \Illuminate\Support\Collection<int, Iface>
     */
    public function connectionIfaces(int $deviceId): \Illuminate\Support\Collection
    {
        return Iface::with('device.user')
            ->where('device_id', $deviceId)
            ->whereHas('ipAddresses', fn ($q) => $q->where('gateway', 0))
            ->get();
    }
}
