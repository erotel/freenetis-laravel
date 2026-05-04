<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\SledovaniTvService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SledovaniTvSync extends Command
{
    protected $signature   = 'sledovanitv:sync {--force : Spustit i když je modul vypnutý}';
    protected $description = 'Stáhne aktuální seznam zákazníků ze SledovaniTV API a aktualizuje members.tv_*';

    public function handle(SledovaniTvService $svc): int
    {
        if (!$svc->isEnabled() && !$this->option('force')) {
            $this->info('SledovaniTV modul je vypnutý (sledovanitv_enabled=0), skipping.');
            return 0;
        }

        $this->info('SledovaniTV sync: stahuju...');

        try {
            $stats = $svc->syncToMembers();
        } catch (\Throwable $e) {
            Log::error('SledovaniTV sync failed: ' . $e->getMessage(), ['exception' => $e]);
            Setting::set('sledovanitv_last_sync_status', 'CHYBA: ' . $e->getMessage());
            $this->error('Sync selhal: ' . $e->getMessage());
            return 1;
        }

        $this->info(sprintf(
            'Hotovo: %d total, %d matched, %d active, %d unmatched, %d bez partnerid',
            $stats['total'], $stats['matched'], $stats['active'],
            $stats['unmatched'], $stats['no_partnerid']
        ));

        return 0;
    }
}
