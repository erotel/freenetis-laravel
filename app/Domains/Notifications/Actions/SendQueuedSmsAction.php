<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Channels\SmsDriverResolver;
use App\Domains\Notifications\Repositories\SmsRepository;
use Illuminate\Support\Facades\Log;

/** Odešle čekající odchozí SMS přes nakonfigurovanou bránu. */
class SendQueuedSmsAction
{
    public function __construct(private SmsRepository $sms, private SmsDriverResolver $drivers)
    {
    }

    /**
     * @param callable(string $level, string $line): void $report průběh pro konzoli
     * @return array{sent:int, failed:int, total:int}
     */
    public function handle(int $limit, callable $report): array
    {
        $rows = $this->sms->pendingOutgoing($limit);
        $sent = 0;
        $failed = 0;
        if ($rows->isNotEmpty()) {
            $report('info', "Processing {$rows->count()} pending SMS messages.");
        }

        foreach ($rows as $sms) {
            $driver = $this->drivers->resolve((int) $sms->driver);
            if ($driver === null) {
                $msg = "No active driver available for driver_id={$sms->driver}";
                $report('warn', "SMS #{$sms->id}: $msg, marking as failed.");
                $this->sms->markDelivered((int) $sms->id, false, $msg);
                $failed++;
                continue;
            }

            if ($driver->send((string) $sms->sender, (string) $sms->receiver, (string) $sms->text)) {
                $this->sms->markDelivered((int) $sms->id, true, (string) $driver->getStatus());
                $report('info', "SMS #{$sms->id} → {$sms->receiver}: OK.");
                $sent++;
            } else {
                $err = substr((string) ($driver->getError() ?? 'Unknown error'), 0, 100);
                $this->sms->markDelivered((int) $sms->id, false, $err);
                $report('warn', "SMS #{$sms->id} → {$sms->receiver}: FAILED — $err");
                Log::warning("SmsSend: SMS #{$sms->id} to {$sms->receiver} failed: $err");
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'total' => $rows->count()];
    }
}
