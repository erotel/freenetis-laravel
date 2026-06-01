<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupOrphanAddressPoints extends Command
{
    protected $signature   = 'address-points:cleanup-orphans
                                {--dry-run : Pouze vypíše, nic nesmaže (default)}
                                {--apply : Skutečně smaže orphan AP}
                                {--town= : Omezit jen na konkrétní town_id}
                                {--street= : Omezit jen na konkrétní street_id}';
    protected $description = 'Najde a (volitelně) smaže address_points, na které neukazuje žádný member/device/members_domicile.';

    public function handle(): int
    {
        $dryRun = !$this->option('apply');

        $qb = DB::table('address_points')
            ->whereNotExists(fn($q) => $q->from('members')->whereColumn('members.address_point_id', 'address_points.id'))
            ->whereNotExists(fn($q) => $q->from('devices')->whereColumn('devices.address_point_id', 'address_points.id'))
            ->whereNotExists(fn($q) => $q->from('members_domiciles')->whereColumn('members_domiciles.address_point_id', 'address_points.id'));

        if ($townId = $this->option('town')) {
            $qb->where('address_points.town_id', (int) $townId);
        }
        if ($streetId = $this->option('street')) {
            $qb->where('address_points.street_id', (int) $streetId);
        }

        $count = (clone $qb)->count();

        if ($count === 0) {
            $this->info('Žádné orphan adresní body nenalezeny.');
            return 0;
        }

        if ($dryRun) {
            $this->info("Nalezeno {$count} orphan AP (DRY RUN — nic se nesmaže).");
            $this->info('Ukázka (max 10):');
            $sample = (clone $qb)
                ->leftJoin('towns as t', 't.id', '=', 'address_points.town_id')
                ->leftJoin('streets as s', 's.id', '=', 'address_points.street_id')
                ->select('address_points.id', 'address_points.street_number', 's.street', 't.town')
                ->limit(10)->get();
            foreach ($sample as $r) {
                $addr = trim(($r->street ?? '—') . ' ' . ($r->street_number ?? '')) . ', ' . ($r->town ?? '—');
                $this->line("  ap_id={$r->id}  {$addr}");
            }
            $this->warn('Spusť znovu s --apply pro skutečné smazání.');
            return 0;
        }

        $deleted = $qb->delete();
        $this->info("Smazáno {$deleted} orphan adresních bodů.");
        return 0;
    }
}
