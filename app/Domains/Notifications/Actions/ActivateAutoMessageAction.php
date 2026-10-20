<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Billing\Documents\PaymentQrService;
use App\Domains\Notifications\Queries\NotificationRecipientsQuery;
use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Domains\Notifications\Support\Outbox;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Automatická aktivace upozorňovací zprávy (dlužník / upomínka) pro aktuální
 * příjemce: e-mail (placeholdery, {payment_qr} jako inline obrázek), SMS,
 * přesměrování (čistý stav pro zprávu — kdo doplatil, vypadne; kdo si redirect
 * odklikl, dostane ho zpět, dokud dluží). Opt-out člena se tvrdě respektuje,
 * bílá listina (kromě ignore_whitelist) vyjme z přesměrování. Záznam do
 * log_queues, souhrn na adresy z pravidla (send_activation_to_email).
 */
class ActivateAutoMessageAction
{
    public function __construct(
        private NotificationRecipientsQuery $recipients,
        private RedirectionRepository $redirects,
    ) {
    }

    /**
     * @param string[] $reportTo e-maily pro souhrn aktivace
     * @return ?int počet příjemců, null = nikdo k upozornění (nic se nezapsalo)
     */
    public function handle(Message $message, bool $doEmail, bool $doSms, bool $doRedirect, array $reportTo, array $cfg): ?int
    {
        $members = $this->recipients->forMessageType($message->type, $cfg['debtor_boundary'], $cfg['big_debtor_boundary'], $cfg['immunity']);
        if ($members->isEmpty()) {
            return null;
        }

        $stats = ['emails' => 0, 'sms' => 0, 'ips' => 0, 'deleted' => 0, 'optEmail' => 0, 'optSms' => 0, 'optRedirect' => 0, 'whitelist' => 0];

        $memberIds = $members->pluck('id')->all();
        $optOut    = DB::table('members')->whereIn('id', $memberIds)
            ->get(['id', 'notification_by_redirection', 'notification_by_email', 'notification_by_sms'])->keyBy('id');

        $whitelisted = (int) ($message->ignore_whitelist ?? 0) === 1 ? [] : $this->redirects->whitelisted($memberIds);

        if ($doRedirect) {
            $stats['deleted'] = DB::table('messages_ip_addresses')->where('message_id', $message->id)->delete();
        }

        foreach ($members as $member) {
            $opt   = $optOut[$member->id] ?? null;
            $email = !$opt || (int) $opt->notification_by_email;
            $sms   = !$opt || (int) $opt->notification_by_sms;
            $redir = !$opt || (int) $opt->notification_by_redirection;

            if ($doEmail && !empty($member->email) && !$email) $stats['optEmail']++;
            if ($doSms && !empty($member->phone) && !$sms)     $stats['optSms']++;
            if ($doRedirect && !$redir)                        $stats['optRedirect']++;

            if ($doEmail && !empty($member->email) && $email) {
                $this->email($message, $member, $cfg);
                $stats['emails']++;
            }
            if ($doSms && !empty($member->phone) && $sms) {
                $text = Message::substitute($message->sms_text ?? $message->text ?? '', Message::buildPlaceholders((int) $member->id));
                DB::table('sms_messages')->insert(Outbox::smsRow(1, (string) now(), $text, $member->phone, [$cfg['sms_driver'], $cfg['sms_sender']]));
                $stats['sms']++;
            }
            if ($doRedirect && $redir) {
                if (isset($whitelisted[$member->id])) {
                    $stats['whitelist']++;
                    continue;
                }
                foreach ($this->redirects->memberIpIds((int) $member->id) as $ipId) {
                    DB::table('messages_ip_addresses')->insert([
                        'message_id' => $message->id, 'ip_address_id' => $ipId, 'user_id' => 1,
                        'comment' => 'Auto-aktivace: ' . $message->name, 'datetime' => now(),
                    ]);
                    $stats['ips']++;
                }
            }
        }

        $this->log($message, $doEmail, $doSms, $doRedirect, $stats);
        $this->report($message, $reportTo, $members->count(), $cfg);

        return $members->count();
    }

    private function email(Message $message, object $member, array $cfg): void
    {
        $body = Message::substitute($message->email_text ?? $message->text ?? '', Message::buildPlaceholders((int) $member->id));

        // QR platba podle tarifu člena jako inline (cid) obrázek — spolehlivě i v Gmailu;
        // když QR nejde sestavit (chybí účet/VS/tarif), placeholder zmizí.
        $qr = null;
        if (str_contains($body, '{payment_qr}')) {
            $qr   = app(PaymentQrService::class)->pngBytesForMember((int) $member->id);
            $body = str_replace('{payment_qr}', $qr
                ? '<img src="cid:payment_qr.png" alt="QR platba" width="200" height="200" style="width:200px;height:200px">'
                : '', $body);
        }

        $id = DB::table('email_queues')->insertGetId([
            'from' => $cfg['from'], 'to' => $member->email, 'subject' => self::subject($cfg, $message->name), 'body' => $body, 'state' => 0,
        ]);
        if ($qr !== null) {
            $this->attachQr($id, $qr);
        }
    }

    private function attachQr(int $emailQueueId, string $png): void
    {
        $dir = storage_path('app/private/qr');
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $path = $dir . '/qr_' . $emailQueueId . '.png';
        file_put_contents($path, $png);

        DB::table('email_queue_attachments')->insert([
            'email_queue_id' => $emailQueueId, 'path' => $path, 'name' => 'payment_qr.png', 'mime' => 'image/png', 'inline' => 1, 'created_at' => now(),
        ]);
    }

    /** log_queues (Kohana Log_queue_Model::info: type 3, state 0, statistika v exception_backtrace) — i při nulách. */
    private function log(Message $message, bool $doEmail, bool $doSms, bool $doRedirect, array $s): void
    {
        $lines = [];
        if ($doRedirect) {
            $lines[] = "Přesměrování bylo deaktivováno pro {$s['deleted']} IP adres.";
            $lines[] = "Přesměrování bylo aktivováno pro {$s['ips']} IP adres.";
        }
        if ($doEmail) $lines[] = "E-mail byl odeslán pro {$s['emails']} e-mailových adres.";
        if ($doSms)   $lines[] = "SMS zpráva byla odeslána pro {$s['sms']} telefonních čísel.";
        if ($s['optEmail'] + $s['optSms'] + $s['optRedirect'] > 0) {
            $lines[] = "Vynecháno kvůli vlastnímu odhlášení člena: {$s['optEmail']} e-mailů, {$s['optSms']} SMS, {$s['optRedirect']} přesměrování.";
        }
        if ($s['whitelist'] > 0) {
            $lines[] = "Přesměrování vynecháno kvůli bílé listině: {$s['whitelist']} členů.";
        }

        DB::table('log_queues')->insert([
            'type' => 3, 'state' => 0, 'created_at' => now(),
            'description'         => 'Upozorňovací zpráva "' . $message->name . '" byla automaticky aktivována',
            'exception_backtrace' => implode("\n", $lines),
        ]);
    }

    private function report(Message $message, array $reportTo, int $count, array $cfg): void
    {
        foreach ($reportTo as $email) {
            $body = "Aktivována zpráva: {$message->name}\nPočet členů: {$count}\nDatum: " . now()->format('d.m.Y H:i') . "\n";
            DB::table('email_queues')->insert([
                'from' => $cfg['from'], 'to' => $email, 'subject' => self::subject($cfg, 'Aktivace zprávy: ' . $message->name),
                'body' => nl2br(htmlspecialchars($body)), 'state' => 0,
            ]);
        }
    }

    private static function subject(array $cfg, string $name): string
    {
        return ($cfg['subject_prefix'] ? $cfg['subject_prefix'] . ' :: ' : '') . $name;
    }
}
