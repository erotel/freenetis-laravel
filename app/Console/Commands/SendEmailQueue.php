<?php

namespace App\Console\Commands;

use App\Models\EmailQueue;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

class SendEmailQueue extends Command
{
    protected $signature   = 'email:send-queue {--limit= : Max emails to send this run (overrides email_send_batch_size)}';
    protected $description = 'Send queued emails from email_queues table';

    public function handle(): int
    {
        // Dávkování — při hromadné rozesílce 2000+ mailů by jeden běh zatlačil SMTP
        // a dál si držel paměť pro celou kolekci. Per-run limit drží rate < limit/min.
        // Setting `email_send_batch_size` default 100 lze přebít CLI flagem --limit.
        $limit = (int) ($this->option('limit') ?? Setting::get('email_send_batch_size', 100));
        if ($limit < 1) {
            $limit = 100;
        }

        $emails = EmailQueue::with('attachments')
            ->where('state', EmailQueue::STATE_NEW)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($emails->isEmpty()) {
            $this->info('No emails to send.');
            return 0;
        }

        try {
            $mailer = $this->buildMailer();
        } catch (\Throwable $e) {
            $this->error('Cannot build mailer: ' . $e->getMessage());
            Log::error('SendEmailQueue: cannot build mailer', ['error' => $e->getMessage()]);
            return 1;
        }

        $sent = 0;
        $failed = 0;

        foreach ($emails as $queued) {
            try {
                $email = (new Email())
                    ->from($queued->from)
                    ->to($queued->to)
                    ->subject($queued->subject)
                    ->html($queued->body)
                    ->text($this->htmlToText($queued->body));

                // BCC rules — loaded dynamically from config table
                foreach ($this->loadBccRules() as $rule) {
                    if (str_contains($queued->subject, $rule['prefix'])) {
                        $email->addBcc($rule['address']);
                        break;
                    }
                }

                foreach ($queued->attachments as $attachment) {
                    $real = realpath($attachment->path);
                    if ($real === false || !$this->isPathAllowed($real)) {
                        Log::warning('SendEmailQueue: skipping unsafe/missing attachment', ['path' => $attachment->path]);
                        continue;
                    }
                    $part = new DataPart(new File($real), $attachment->name, $attachment->mime ?? 'application/octet-stream');
                    // Inline (cid) přílohy — např. QR platba — se vloží do těla e-mailu;
                    // Symfony přepíše `cid:<name>` v HTML na vygenerované Content-ID.
                    if (!empty($attachment->inline)) {
                        $part->asInline();
                    }
                    $email->addPart($part);
                }

                $mailer->send($email);
                $queued->update(['state' => EmailQueue::STATE_SENT]);
                $sent++;
                $this->info("Sent [{$queued->id}]: {$queued->subject} → {$queued->to}");

            } catch (\Throwable $e) {
                $queued->update(['state' => EmailQueue::STATE_FAILED]);
                $failed++;
                $this->error("Failed [{$queued->id}]: " . $e->getMessage());
                Log::error('SendEmailQueue: send failed', [
                    'id'    => $queued->id,
                    'to'    => $queued->to,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done: {$sent} sent, {$failed} failed.");
        return $failed > 0 ? 1 : 0;
    }

    /** Load BCC rules from config table (set via Settings → Email tab). */
    private function loadBccRules(): array
    {
        $rules = [];
        for ($i = 0; $i < 10; $i++) {
            $prefix  = Setting::get('email_bcc_rule_' . $i . '_subject_prefix', '');
            $address = Setting::get('email_bcc_rule_' . $i . '_address', '');
            if ($prefix !== '' && $address !== '') {
                $rules[] = ['prefix' => $prefix, 'address' => $address];
            }
        }
        return $rules;
    }

    private function buildMailer(): Mailer
    {
        $driver     = Setting::get('email_driver',     'smtp');
        $host       = Setting::get('email_hostname',   'localhost');
        $port       = (int) Setting::get('email_port', '25');
        $user       = Setting::get('email_username',   '');
        $pass       = Setting::get('email_password',   '');
        $encryption = strtolower(trim(Setting::get('email_encryption', '')));

        if ($encryption === 'tsl') {
            $encryption = 'tls';
        }

        if ($driver === 'sendmail') {
            $transport = Transport::fromDsn('sendmail://default');
            return new Mailer($transport);
        }

        $scheme = ($encryption === 'ssl') ? 'smtps' : 'smtp';
        $query  = ($encryption === 'tls') ? '?encryption=tls' : '';

        if ($user !== '') {
            $dsn = "{$scheme}://" . rawurlencode($user) . ':' . rawurlencode($pass) . "@{$host}:{$port}{$query}";
        } else {
            $dsn = "{$scheme}://{$host}:{$port}{$query}";
        }

        $transport = Transport::fromDsn($dsn);
        return new Mailer($transport);
    }

    private function isPathAllowed(string $realPath): bool
    {
        $allowed = [
            '/var/www/html/freenetis/data/',
            '/var/www/html/freenetis-laravel/storage/',
        ];
        foreach ($allowed as $dir) {
            if (str_starts_with($realPath, $dir)) {
                return true;
            }
        }
        return false;
    }

    private function htmlToText(string $html): string
    {
        $text = str_ireplace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>', '</tr>', '</li>'],
            "\n",
            $html
        );
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}
