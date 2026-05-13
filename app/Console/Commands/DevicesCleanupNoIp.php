<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Smaže zařízení, která nemají přiřazenou žádnou IP adresu (IPv4 ani IPv6).
 *
 * Filtruje podle `enum_types.value` (typ zařízení): default jen klientská
 * zařízení (PC, client, notebook). Infrastrukturu (router, switch, AP...)
 * je třeba přidat explicitně přes --types, protože může být legitimně bez
 * IP (čekající konfigurace, L2-only).
 *
 * Default je dry-run — pro skutečné smazání nutno --force.
 */
class DevicesCleanupNoIp extends Command
{
    protected $signature = 'devices:cleanup-no-ip
        {--types=PC,client,notebook : Čárkou oddělené typy (enum_types.value); prázdné = všechny typy}
        {--force                    : Skutečně smazat (default dry-run)}
        {--limit=                   : Omezit počet smazaných záznamů (sanity check)}';

    protected $description = 'Smaže zařízení bez přiřazené IP adresy (default jen klienti).';

    public function handle(): int
    {
        $force  = (bool) $this->option('force');
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;
        $types  = array_filter(array_map('trim', explode(',', (string) $this->option('types'))));

        $query = DB::table('devices as d')
            ->leftJoin('enum_types as et', 'et.id', '=', 'd.type')
            ->select('d.id', 'd.name', 'd.user_id', 'et.value as typ')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('ifaces as i')
                    ->join('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
                    ->whereColumn('i.device_id', 'd.id')
                    ->whereNotNull('ip.ip_address')->where('ip.ip_address', '<>', '');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('ifaces as i')
                    ->join('ip6_addresses as ip6', 'ip6.iface_id', '=', 'i.id')
                    ->whereColumn('i.device_id', 'd.id')
                    ->whereNotNull('ip6.ip_address')->where('ip6.ip_address', '<>', '');
            });

        if (!empty($types)) {
            $query->whereIn('et.value', $types);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $devices = $query->orderBy('d.id')->get();

        if ($devices->isEmpty()) {
            $this->info('Nic ke smazání. ✓');
            return self::SUCCESS;
        }

        $this->info(sprintf('Najito: %d zařízení bez IP %s%s.',
            $devices->count(),
            $types ? '(typy: ' . implode(', ', $types) . ')' : '(všechny typy)',
            $force ? '' : ' — DRY-RUN'
        ));

        // Breakdown
        $byType = $devices->groupBy('typ')->map->count()->sortDesc();
        foreach ($byType as $typ => $cnt) {
            $this->line(sprintf('  %-20s %d', $typ ?: '(neznámý)', $cnt));
        }

        if (!$force) {
            $this->newLine();
            $this->warn('DRY-RUN: nic neměním. Pro skutečné smazání spusť znovu s --force.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Mažu...');

        $deleted = 0;
        DB::transaction(function () use ($devices, &$deleted) {
            $deviceIds = $devices->pluck('id')->all();

            $ifaceIds = DB::table('ifaces')->whereIn('device_id', $deviceIds)->pluck('id')->all();

            if (!empty($ifaceIds)) {
                DB::table('ip_addresses')->whereIn('iface_id', $ifaceIds)->delete();
                DB::table('ip6_addresses')->whereIn('iface_id', $ifaceIds)->delete();
                DB::table('ifaces_vlans')->whereIn('iface_id', $ifaceIds)->delete();
                DB::table('ifaces_relationships')
                    ->whereIn('iface_id', $ifaceIds)
                    ->orWhereIn('parent_iface_id', $ifaceIds)
                    ->delete();
                DB::table('ifaces')->whereIn('id', $ifaceIds)->delete();
            }

            DB::table('device_engineers')->whereIn('device_id', $deviceIds)->delete();
            DB::table('device_admins')->whereIn('device_id', $deviceIds)->delete();
            DB::table('connection_requests')->whereIn('device_id', $deviceIds)->delete();
            DB::table('monitor_hosts')->whereIn('device_id', $deviceIds)->delete();
            DB::table('onts')->whereIn('device_id', $deviceIds)->delete();
            DB::table('device_active_links_map')->whereIn('device_id', $deviceIds)->delete();

            $deleted = DB::table('devices')->whereIn('id', $deviceIds)->delete();
        });

        $this->info("Hotovo: smazáno {$deleted} zařízení.");
        return self::SUCCESS;
    }
}
