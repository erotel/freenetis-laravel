<?php

namespace App\Console\Commands;

use App\Models\LogQueue;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors Kohana Scheduler_Controller::run() CRON failure detection (#754).
 * Runs every minute. If the gap since last run > 110 seconds, logs a fatal error to log_queues.
 * Always updates cron_last_active and cron_state at the end.
 */
class CronHeartbeat extends Command
{
    protected $signature   = 'cron:heartbeat';
    protected $description = 'CRON failure detection: log to log_queues if scheduler was offline, update cron_last_active';

    public function handle(): int
    {
        $now        = time();
        $lastActive = (int) Setting::get('cron_last_active', 0);

        if ($lastActive > 0) {
            $gap = $now - $lastActive;

            if ($gap > 110) {
                $from = date('Y-m-d H:i:s', $lastActive);
                $to   = date('Y-m-d H:i:s', $now);

                $desc = "Výpadek CRONu (scheduler byl offline od {$from} do {$to}, {$gap} sekund). "
                      . "Některé automatické operace nemusely proběhnout.";

                $this->warn("CRON failure detected: offline for {$gap}s ({$from} → {$to})");
                Log::warning($desc);

                DB::table('log_queues')->insert([
                    'type'        => LogQueue::TYPE_FATAL_ERROR,
                    'state'       => LogQueue::STATE_NEW,
                    'created_at'  => date('Y-m-d H:i:s', $now),
                    'description' => 'Výpadek CRONu',
                    'exception_backtrace' => $desc,
                ]);
            }
        }

        // Update heartbeat timestamps
        Setting::set('cron_last_active', (string) $now);
        Setting::set('cron_state', date('Y-m-d H:i:s', $now));

        $this->line('Heartbeat updated: ' . date('Y-m-d H:i:s', $now));
        return 0;
    }
}
