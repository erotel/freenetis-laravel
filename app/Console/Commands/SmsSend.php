<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\SmsManagerDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes queued outgoing SMS messages (sms_messages.state = 1 SENT_UNSENT).
 *
 * Driver IDs (sms_messages.driver column, same as Kohana Sms class constants):
 *   2 = SOUNDWINV100 (not implemented)
 *   3 = KLIKNIAVOLEJ (not implemented)
 *   4 = ARTIO        (not implemented)
 *   5 = SMSMANAGER   (implemented via SmsManagerDriver)
 *
 * Config keys per driver:
 *   sms_driver_stateN  — 1=inactive, 2=active
 *   sms_passwordN      — API key / password
 *   sms_userN          — username (not used by SmsManager)
 */
class SmsSend extends Command
{
    protected $signature   = 'sms:send {--limit=50 : Max messages to process per run}';
    protected $description = 'Send queued outgoing SMS messages via configured drivers.';

    // sms_messages.state values (mirror of Kohana Sms_message_Model constants)
    const STATE_OK     = 0; // SENT_OK
    const STATE_UNSENT = 1; // SENT_UNSENT
    const STATE_FAILED = 2; // SENT_FAILED

    // sms_messages.driver values (mirror of Kohana Sms class constants)
    const DRIVER_SMSMANAGER = 5;

    // sms_driver_stateN values
    const DRIVER_INACTIVE = 1;
    const DRIVER_ACTIVE   = 2;

    public function handle(): int
    {
        if (!Setting::get('sms_enabled', 0)) {
            $this->info('SMS disabled (sms_enabled=0), skipping.');
            return 0;
        }

        $limit = max(1, (int) $this->option('limit'));

        $rows = DB::table('sms_messages')
            ->where('type', 1)              // type=1 = SENT (outgoing)
            ->where('state', self::STATE_UNSENT)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No pending SMS messages.');
            return 0;
        }

        $this->info("Processing {$rows->count()} pending SMS messages.");

        $sent   = 0;
        $failed = 0;

        foreach ($rows as $sms) {
            $driver = $this->resolveDriver((int) $sms->driver);

            if ($driver === null) {
                $msg = "No active driver available for driver_id={$sms->driver}";
                $this->warn("SMS #{$sms->id}: $msg, marking as failed.");
                DB::table('sms_messages')
                    ->where('id', $sms->id)
                    ->update(['state' => self::STATE_FAILED, 'message' => substr($msg, 0, 100)]);
                $failed++;
                continue;
            }

            $ok = $driver->send((string) $sms->sender, (string) $sms->receiver, (string) $sms->text);

            if ($ok) {
                $status = substr((string) $driver->getStatus(), 0, 100);
                DB::table('sms_messages')
                    ->where('id', $sms->id)
                    ->update(['state' => self::STATE_OK, 'message' => $status]);
                $this->info("SMS #{$sms->id} → {$sms->receiver}: OK.");
                $sent++;
            } else {
                $err = substr((string) ($driver->getError() ?? 'Unknown error'), 0, 100);
                DB::table('sms_messages')
                    ->where('id', $sms->id)
                    ->update(['state' => self::STATE_FAILED, 'message' => $err]);
                $this->warn("SMS #{$sms->id} → {$sms->receiver}: FAILED — $err");
                Log::warning("SmsSend: SMS #{$sms->id} to {$sms->receiver} failed: $err");
                $failed++;
            }
        }

        $this->info("Done. Sent: $sent, Failed: $failed.");
        return $failed > 0 ? 1 : 0;
    }

    /**
     * Resolve a driver instance for the given driver ID.
     * Falls back to any active driver if the requested one is not supported/active.
     */
    private function resolveDriver(int $driverId): ?SmsManagerDriver
    {
        // Try requested driver first, then all known drivers as fallback
        $candidates = array_unique(array_filter([$driverId, self::DRIVER_SMSMANAGER]));

        foreach ($candidates as $id) {
            $state = (int) Setting::get('sms_driver_state' . $id, self::DRIVER_INACTIVE);
            if ($state !== self::DRIVER_ACTIVE) continue;

            if ($id === self::DRIVER_SMSMANAGER) {
                $apiKey = (string) Setting::get('sms_password' . $id, '');
                if ($apiKey === '') continue;
                return new SmsManagerDriver($apiKey);
            }

            // Other drivers (KLIKNIAVOLEJ, ARTIO) not implemented yet
        }

        return null;
    }
}
