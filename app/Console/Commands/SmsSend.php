<?php

namespace App\Console\Commands;

use App\Domains\Notifications\Actions\SendQueuedSmsAction;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Odešle frontu odchozích SMS (sms_messages.state = 1). Výběr brány viz
 * Notifications\Channels\SmsDriverResolver (SmsManager 5, Klikniavolej 3).
 * Nastavení: sms_driver_stateN (1 neaktivní, 2 aktivní), sms_passwordN, sms_userN.
 */
class SmsSend extends Command
{
    protected $signature   = 'sms:send {--limit=50 : Max messages to process per run}';
    protected $description = 'Send queued outgoing SMS messages via configured drivers.';

    public function handle(SendQueuedSmsAction $send): int
    {
        if (!Setting::get('sms_enabled', 0)) {
            $this->info('SMS disabled (sms_enabled=0), skipping.');
            return 0;
        }

        $result = $send->handle(max(1, (int) $this->option('limit')), fn (string $level, string $line) => $level === 'warn' ? $this->warn($line) : $this->info($line));

        if ($result['total'] === 0) {
            $this->info('No pending SMS messages.');
            return 0;
        }
        $this->info("Done. Sent: {$result['sent']}, Failed: {$result['failed']}.");
        return $result['failed'] > 0 ? 1 : 0;
    }
}
