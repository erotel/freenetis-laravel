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
}
