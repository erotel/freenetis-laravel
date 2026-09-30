<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\AllowedSubnetSyncService;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Převod síťové části člena na jiného člena — typicky infrastruktura sdružení
 * z člena 1 (který má zůstat jen „základní“: admin + účetnictví sdružení) na
 * člena 2748.
 *
 * Převádí:
 *  - zařízení všech uživatelů zdrojového člena → uživatel cílového člena,
 *  - IP přiřazené přímo členovi (ip_addresses.member_id) a vlastnictví subnetů
 *    (subnets_owners),
 *  - allowed_subnets: cíl získá subnety převedených IP (stejně jako přesun
 *    zařízení v UI), zdroj ztratí ty, kde už žádnou IP nemá.
 * Nesahá na účetnictví (účty, převody, bankovní účty, poplatky) ani uživatele.
 * DHCP subnety převedených IP označí jako změněné (komentáře leasů „ID <člen>“).
 *
 * Bezpečnost: bez --execute jen vypíše, co by udělal. Před zápisem uloží
 * zálohu do storage/app a celé proběhne v jedné transakci. Odmítne běh, pokud
 * má cílový člen omezený počet povolených podsítí (převedená infrastruktura by
 * spadla do „nepovolené podsíti“ → přesměrování).
 */
class MembersMoveNetwork extends Command
{
    protected $signature = 'members:move-network
        {from : ID zdrojového člena}
        {to : ID cílového člena}
        {--user= : ID cílového uživatele (výchozí: první uživatel cílového člena typu 1)}
        {--execute : Opravdu provést (bez něj jen náhled)}';

    protected $description = 'Převede zařízení, IP a subnety člena na jiného člena (účetnictví nechá)';

    public function handle(AllowedSubnetSyncService $sync): int
    {
        $from = (int) $this->argument('from');
        $to   = (int) $this->argument('to');
        if ($from === $to || !DB::table('members')->where('id', $from)->exists() || !DB::table('members')->where('id', $to)->exists()) {
            $this->error('Neplatný zdrojový nebo cílový člen.');
            return self::FAILURE;
        }

        $toUser = $this->option('user')
            ? (int) $this->option('user')
            : (int) DB::table('users')->where('member_id', $to)->orderByRaw('type <> 1')->orderBy('id')->value('id');
        if (!$toUser || (int) DB::table('users')->where('id', $toUser)->value('member_id') !== $to) {
            $this->error("Cílový uživatel {$toUser} nepatří členovi {$to}.");
            return self::FAILURE;
        }

        $fromUsers = DB::table('users')->where('member_id', $from)->pluck('id')->all();
        $deviceIds = DB::table('devices')->whereIn('user_id', $fromUsers)->pluck('id')->all();
        $deviceSubnets = DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->whereIn('i.device_id', $deviceIds ?: [0])
            ->whereNotNull('ip.subnet_id')
            ->distinct()->pluck('ip.subnet_id')->map(fn ($x) => (int) $x)->all();
        $directIps = DB::table('ip_addresses as ip')->leftJoin('subnets as s', 's.id', '=', 'ip.subnet_id')
            ->where('ip.member_id', $from)
            ->get(['ip.id', 'ip.ip_address', 'ip.subnet_id', 's.name']);
        $owned = DB::table('subnets_owners as o')->join('subnets as s', 's.id', '=', 'o.subnet_id')
            ->where('o.member_id', $from)->get(['o.subnet_id', 's.name', 's.network_address', 's.netmask']);

        $this->info("Člen {$from} → člen {$to} (uživatel {$toUser})");
        $this->line('  zařízení: ' . count($deviceIds) . ' (IP v ' . count($deviceSubnets) . ' subnetech)');
        $this->line('  IP přiřazené přímo členovi: ' . $directIps->count());
        foreach ($directIps->groupBy('name') as $name => $ips) {
            $this->line("    {$name}: " . $ips->pluck('ip_address')->implode(', '));
        }
        $this->line('  vlastnictví subnetů: ' . $owned->count());
        foreach ($owned as $o) {
            $this->line("    {$o->subnet_id} {$o->name} {$o->network_address}/{$o->netmask}");
        }

        // Limit povolených podsítí cíle (0 = neomezeno; bez řádku platí globální default).
        $limitRow = DB::table('allowed_subnets_counts')->where('member_id', $to)->value('count');
        $limit    = $limitRow !== null ? (int) $limitRow : (int) Setting::get('allowed_subnets_default_count', 1);
        $needed   = collect($deviceSubnets)->merge($directIps->pluck('subnet_id'))
            ->merge(DB::table('allowed_subnets')->where('member_id', $to)->pluck('subnet_id'))
            ->filter()->unique()->count();
        $this->line('  povolené podsítě cíle: limit ' . ($limit > 0 ? $limit : 'neomezeno') . ", po převodu potřeba {$needed}");
        if ($limit > 0 && $needed > $limit) {
            $this->error("Cílový člen {$to} má limit {$limit} povolených podsítí — převedená zařízení by se začala "
                . 'přesměrovávat. Nastav mu neomezeno (Profil člena → Povolené podsítě → počet 0) a spusť znovu.');
            return self::FAILURE;
        }

        if (!$this->option('execute')) {
            $this->warn('Náhled — nic nezměněno. Proveď s --execute.');
            return self::SUCCESS;
        }

        $backupPath = storage_path('app/move_network_' . $from . '_to_' . $to . '_' . now()->format('Ymd_His') . '.json');
        file_put_contents($backupPath, json_encode([
            'at'                 => now()->toDateTimeString(),
            'devices'            => DB::table('devices')->whereIn('id', $deviceIds ?: [0])->get(['id', 'user_id']),
            'direct_ip_ids'      => $directIps->pluck('id'),
            'subnets_owners'     => $owned->pluck('subnet_id'),
            'allowed_subnets'    => DB::table('allowed_subnets')->whereIn('member_id', [$from, $to])->get(),
        ], JSON_PRETTY_PRINT));
        $this->line("  záloha: {$backupPath}");

        $result = DB::transaction(function () use ($from, $to, $toUser, $deviceIds, $deviceSubnets, $directIps, $sync) {
            $moved = $deviceIds ? DB::table('devices')->whereIn('id', $deviceIds)->update(['user_id' => $toUser]) : 0;
            $ips   = DB::table('ip_addresses')->where('member_id', $from)->update(['member_id' => $to]);
            $own   = DB::table('subnets_owners')->where('member_id', $from)->update(['member_id' => $to]);

            $subnets = collect($deviceSubnets)->merge($directIps->pluck('subnet_id'))->filter()->map(fn ($x) => (int) $x)->unique()->values()->all();
            $sync->updateEnabled($to, $subnets);

            // Zdroji zůstanou jen podsítě, kde ještě má nějakou IP (zařízení nebo přímou).
            $stillHas = DB::table('ip_addresses as ip')
                ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
                ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
                ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
                ->where(fn ($q) => $q->where('u.member_id', $from)->orWhere('ip.member_id', $from))
                ->distinct()->pluck('ip.subnet_id')->map(fn ($x) => (int) $x)->all();
            $orphan = DB::table('allowed_subnets')->where('member_id', $from)
                ->whereNotIn('subnet_id', $stillHas ?: [0])->pluck('subnet_id')->map(fn ($x) => (int) $x)->all();
            $sync->updateEnabled($from, [], [], $orphan);

            $expired = $subnets
                ? DB::table('subnets')->whereIn('id', $subnets)->where('dhcp', 1)
                    ->update(['dhcp_expired' => 1, 'dhcp_changed_at' => now()->format('Y-m-d H:i:s.u')])
                : 0;

            AuditLogger::log('network_moved', 'members', $from, null, [
                'to_member' => $to, 'to_user' => $toUser, 'devices' => $moved,
                'direct_ips' => $ips, 'subnets_owners' => $own, 'allowed_removed' => count($orphan),
            ]);

            return compact('moved', 'ips', 'own', 'orphan', 'expired');
        });

        $this->info("Hotovo: zařízení {$result['moved']}, přímých IP {$result['ips']}, vlastnictví {$result['own']}, "
            . 'odebraných povolených podsítí zdroje ' . count($result['orphan']) . ", DHCP subnetů k přeexportu {$result['expired']}.");
        return self::SUCCESS;
    }
}
