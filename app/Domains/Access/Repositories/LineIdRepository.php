<?php

namespace App\Domains\Access\Repositories;

use App\Models\LineId;
use App\Models\LineIdAnomaly;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Line-id tabulky: `line_id_seen` (staging z RADIUS accountingu — píše FreeRADIUS
 * na .54 i na Kea uzlech), `line_ids` (MAC→iface mapování) a `line_id_anomalies`.
 *
 * KONTRAKT: nad `line_ids` stojí RADIUS views (radcheck/radreply_lineid_v,
 * *_kea4_v klíčují CONCAT(remote_id, circuit_id)) → identita řádku je dvojice
 * (circuit_id, remote_id), prázdný remote = ''. Schéma neměnit bez úpravy views.
 */
class LineIdRepository
{
    // ── line_id_seen ─────────────────────────────────────────────────────────

    public function unreconciledSeen(): Collection
    {
        return DB::table('line_id_seen')->where('reconciled', 0)->get();
    }

    public function seenSince(\DateTimeInterface $since): Collection
    {
        return DB::table('line_id_seen')->where('last_seen', '>=', $since)->get();
    }

    public function markSeenReconciled(int $seenId): void
    {
        DB::table('line_id_seen')->where('id', $seenId)->update(['reconciled' => 1]);
    }

    /** Počet různých MAC viděných na DVOJICI (circuit, remote) — >1 = sdílený port. */
    public function distinctMacCount(string $circuitHex, ?string $remoteHex): int
    {
        return DB::table('line_id_seen')
            ->where('circuit_id_hex', $circuitHex)
            ->whereRaw('COALESCE(remote_id_hex, "") = COALESCE(?, "")', [$remoteHex])
            ->distinct()->count('mac');
    }

    // ── line_ids ─────────────────────────────────────────────────────────────

    public function find(string $circuit, string $remote): ?object
    {
        return DB::table('line_ids')->where('circuit_id', $circuit)->where('remote_id', $remote)->first();
    }

    /** Domovský port přípojky (první mapování iface). */
    public function findByIface(int $ifaceId): ?object
    {
        return DB::table('line_ids')->where('iface_id', $ifaceId)->first();
    }

    public function create(array $attributes): LineId
    {
        return LineId::create($attributes);
    }

    public function update(int $id, array $values): void
    {
        DB::table('line_ids')->where('id', $id)->update($values);
    }

    /** @return int počet smazaných */
    public function delete(string $circuit, string $remote): int
    {
        return DB::table('line_ids')->where('circuit_id', $circuit)->where('remote_id', $remote)->delete();
    }

    public function deleteById(int $id): void
    {
        DB::table('line_ids')->where('id', $id)->delete();
    }

    /** Všechna mapování (jen identita) — pro úklid sdílených. */
    public function allIdentities(): Collection
    {
        return DB::table('line_ids')->get(['id', 'circuit_id', 'remote_id']);
    }

    /** Mapování s úplně prázdným parsem (vendor/device_ident/port). */
    public function withoutParse(): Collection
    {
        return DB::table('line_ids')
            ->whereNull('vendor')->whereNull('device_ident')->whereNull('port')
            ->get(['id', 'circuit_id']);
    }

    // ── ifaces ───────────────────────────────────────────────────────────────

    /** Registrovaná přípojka podle MAC (normalizace '-'→':' a velikosti písmen). */
    public function ifaceIdByMac(string $mac): ?int
    {
        $id = DB::table('ifaces')
            ->whereRaw("UPPER(REPLACE(mac,'-',':')) = UPPER(?)", [$mac])
            ->value('id');
        return $id ? (int) $id : null;
    }

    // ── line_id_anomalies ────────────────────────────────────────────────────

    /**
     * Idempotentní upsert anomálie podle (circuit_id, seen_mac). Vyřešenou
     * znovuotevře jen když se objevila PO vyřešení (přetrvávající přehození).
     */
    public function recordAnomaly(
        string $circuit, ?int $expectedIfaceId, string $mac, ?int $seenIfaceId,
        string $type, string $severity, $lastSeen
    ): void {
        $now = $lastSeen ? \Carbon\Carbon::parse($lastSeen) : now();

        $a = LineIdAnomaly::firstOrNew(['circuit_id' => $circuit, 'seen_mac' => $mac]);
        if (!$a->exists) {
            $a->first_seen = $now;
            $a->seen_count = 0;
        }
        if ($a->resolved_at !== null && $now->greaterThan($a->resolved_at)) {
            $a->resolved_at = null;
        }
        $a->expected_iface_id = $expectedIfaceId;
        $a->seen_iface_id     = $seenIfaceId;
        $a->type              = $type;
        $a->severity          = $severity;
        $a->seen_count        = (int) $a->seen_count + 1;
        $a->last_seen         = $now;
        $a->save();
    }
}
