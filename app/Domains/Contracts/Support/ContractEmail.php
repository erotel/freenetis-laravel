<?php

namespace App\Domains\Contracts\Support;

use App\Models\ContractEvent;
use App\Models\EmailQueue;
use App\Models\EmailQueueAttachment;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/** E-maily zákazníkovi k podepsaným dokumentům (šablona ze Sdělení, jinak záložní text). */
final class ContractEmail
{
    /**
     * Předmět + tělo ze šablony zprávy daného typu; prázdná/chybějící šablona →
     * záložní closures (dostanou HTML-escapované číslo smlouvy). Šablona umí
     * {contract_no} i standardní placeholdery člena (Message::buildPlaceholders).
     *
     * @return array{0:string,1:string}
     */
    public static function render(int $type, int $memberId, string $contractNo, \Closure $subjectFallback, \Closure $bodyFallback): array
    {
        $contractNoHtml = htmlspecialchars($contractNo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tpl = Message::where('type', $type)->first();

        if ($tpl && trim((string) $tpl->email_text) !== '') {
            $prefix  = (string) Setting::get('email_subject_prefix', '');
            $subject = ($prefix !== '' ? $prefix . ' :: ' : '') . $tpl->name;
            $body    = Message::substitute($tpl->email_text, Message::buildPlaceholders($memberId, ['contract_no' => $contractNo]));
            return [$subject, $body];
        }

        return [$subjectFallback($contractNoHtml), $bodyFallback($contractNoHtml)];
    }

    /**
     * Zařadí e-mail s PDF přílohami a zapíše událost. Best-effort: chyba jen do
     * logu (`$logLabel … failed for contract #id`), podpis tím nespadne.
     *
     * @param array<int, array{path:string, name:string}> $attachments
     */
    public static function queue(int $contractId, string $to, string $subject, string $body, array $attachments,
                                 string $event, array $eventMeta, string $logLabel): void
    {
        try {
            $q = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $to,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);
            foreach ($attachments as $a) {
                EmailQueueAttachment::create([
                    'email_queue_id' => $q->id,
                    'path'           => $a['path'],
                    'name'           => $a['name'],
                    'mime'           => 'application/pdf',
                    'created_at'     => now(),
                ]);
            }
            ContractEvent::create([
                'contract_id' => $contractId,
                'event'       => $event,
                'meta_json'   => json_encode($eventMeta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            Log::warning($logLabel . ' failed for contract #' . $contractId . ': ' . $e->getMessage());
        }
    }
}
