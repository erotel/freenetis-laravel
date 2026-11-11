<?php

namespace App\Domains\Access\Repositories;

use App\Domains\Access\Support\SharedCircuitDetector;
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
 * (circuit_id, remote_id), prázdný remote = ''. Sloupce jsou na produkci
 * VARBINARY (surové bajty option82, RADIUS hledá přes HEX(username)).
 * Schéma neměnit bez úpravy views. Celá topologie: ../INFRASTRUKTURA.md.
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

    /**
     * Zapíše pozorování (circuit-id, remote-id, MAC) z leasu Kea do `line_id_seen`
     * ve stejném tvaru jako FreeRADIUS accounting od MikroTiků ('0x…' malá hex,
     * MAC velká s dvojtečkami). Na rozdíl od accountingu se volá opakovaně se
     * stejným leasem (každý `lineid:sync`), proto počítadlo a last_seen posune jen
     * když je lease novější než uložené pozorování. `reconciled` nemění.
     *
     * @return bool true = nový záznam nebo posun (novější lease)
     */
    public function recordSeenFromLease(string $circuitHex, string $remoteHex, string $mac, \DateTimeInterface $seenAt): bool
    {
        // last_seen je v místním čase (FreeRADIUS píše NOW()) → stejné pásmo i tady,
        // jinak by se tentýž lease jevil o hodinu jiný a započítal by se znovu.
        $seen = \Carbon\Carbon::instance($seenAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $row = DB::table('line_id_seen')->where('circuit_id_hex', $circuitHex)->where('mac', $mac)->first();
        if ($row === null) {
            DB::table('line_id_seen')->insert([
                'circuit_id_hex' => $circuitHex,
                'remote_id_hex'  => $remoteHex,
                'mac'            => $mac,
                'seen_count'     => 1,
                'reconciled'     => 0,
                'last_seen'      => $seen,
            ]);
            return true;
        }
        if ($row->last_seen !== null && (string) $row->last_seen >= $seen) {
            return false; // tentýž (nebo starší) lease, už započtený
        }
        DB::table('line_id_seen')->where('id', $row->id)->update([
            'remote_id_hex' => $remoteHex !== '' ? $remoteHex : $row->remote_id_hex,
            'seen_count'    => DB::raw('seen_count + 1'),
            'last_seen'     => $seen,
        ]);
        return true;
    }

    public function markSeenReconciled(int $seenId): void
    {
        DB::table('line_id_seen')->where('id', $seenId)->update(['reconciled' => 1]);
    }

    /**
     * Počet různých MAC viděných na DVOJICI (circuit, remote) — >1 = sdílený port.
     * `$since`: počítat jen MAC viděné od té doby (stará MAC po výměně routeru
     * jinak port navždy „zasekne" jako sdílený).
     */
    public function distinctMacCount(string $circuitHex, ?string $remoteHex, ?\DateTimeInterface $since = null): int
    {
        return DB::table('line_id_seen')
            ->where('circuit_id_hex', $circuitHex)
            ->whereRaw('COALESCE(remote_id_hex, "") = COALESCE(?, "")', [$remoteHex])
            ->when($since !== null, fn ($q) => $q->where(fn ($w) => $w->where('last_seen', '>=', $since)->orWhereNull('last_seen')))
            ->distinct()->count('mac');
    }

    /** Nejnovější last_seen JINÉ MAC na dvojici (circuit, remote), nebo null. */
    public function latestOtherSeen(string $circuitHex, ?string $remoteHex, string $mac): ?string
    {
        $v = DB::table('line_id_seen')
            ->where('circuit_id_hex', $circuitHex)
            ->whereRaw('COALESCE(remote_id_hex, "") = COALESCE(?, "")', [$remoteHex])
            ->where('mac', '<>', $mac)
            ->max('last_seen');
        return $v === null ? null : (string) $v;
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

    /**
     * Pro dané MAC vrátí mapu MAC(upper) → čitelný circuit-id u těch, jejichž
     * line_id_seen záznam je na SDÍLENÉM circuit-id (týž circuit-id hex vidí víc
     * MAC → per-zákazník neidentifikuje, typicky bytovka). Dvě agregační query,
     * žádný N+1. Mimo tuto množinu = buď má vlastní line-id, nebo se nenaučil.
     *
     * @param string[] $macs
     * @return array<string, string>
     */
    public function sharedCircuitsByMac(array $macs, ?\DateTimeInterface $since = null): array
    {
        if (empty($macs)) {
            return [];
        }
        $since ??= now()->subDays(SharedCircuitDetector::RECENT_DAYS);
        $rows = DB::table('line_id_seen')->whereIn('mac', $macs)
            ->where(fn ($w) => $w->where('last_seen', '>=', $since)->orWhereNull('last_seen')) // NULL = neznámé stáří → počítat jako dřív
            ->get(['mac', 'circuit_id_hex', 'remote_id_hex']);
        if ($rows->isEmpty()) {
            return [];
        }
        // Dvojice (circuit, remote) s víc nedávnými MAC (přes CELOU tabulku, ne jen
        // zadané MAC) — stejná pravidla jako SharedCircuitDetector::isShared.
        $shared = DB::table('line_id_seen')
            ->whereIn('circuit_id_hex', $rows->pluck('circuit_id_hex')->unique()->all())
            ->where(fn ($w) => $w->where('last_seen', '>=', $since)->orWhereNull('last_seen'))
            ->groupBy('circuit_id_hex', DB::raw('COALESCE(remote_id_hex, "")'))
            ->havingRaw('COUNT(DISTINCT mac) > 1')
            ->get([DB::raw('circuit_id_hex'), DB::raw('COALESCE(remote_id_hex, "") AS remote')])
            ->mapWithKeys(fn ($r) => [$r->circuit_id_hex . '|' . $r->remote => true]);

        $out = [];
        foreach ($rows as $r) {
            if (!isset($shared[$r->circuit_id_hex . '|' . ($r->remote_id_hex ?? '')])) {
                continue;
            }
            $hex = preg_replace('/^0x/i', '', (string) $r->circuit_id_hex);
            $circuit = (ctype_xdigit($hex) && strlen($hex) % 2 === 0) ? @hex2bin($hex) : false;
            $out[strtoupper($r->mac)] = $circuit !== false ? $circuit : $r->circuit_id_hex;
        }
        return $out;
    }

    /** Anomálie (nejzávažnější, pak nejčerstvější); bez vyřešených, pokud nechceme. */
    public function anomalies(string $severity, bool $withResolved, int $perPage)
    {
        return LineIdAnomaly::with([
                'expectedIface.device.user.member',
                'seenIface.device.user.member',
            ])
            ->when(!$withResolved, fn ($q) => $q->whereNull('resolved_at'))
            ->when($severity !== '', fn ($q) => $q->where('severity', $severity))
            ->orderByRaw("FIELD(severity,'critical','high','warning')")
            ->orderByDesc('last_seen')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function openCriticalAnomalies(): int
    {
        return LineIdAnomaly::whereNull('resolved_at')->where('severity', 'critical')->count();
    }

    public function resolveAnomaly(int $id): void
    {
        LineIdAnomaly::whereKey($id)->update(['resolved_at' => now()]);
    }
}
