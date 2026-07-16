<?php

namespace App\Services;

use App\Models\EmailQueue;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

class EmailSenderService
{
    /**
     * Send a single queued email. Returns true on success, false on failure.
     * Does NOT update the model state — caller is responsible.
     */
    public function sendOne(EmailQueue $queued): bool
    {
        try {
            $mailer = $this->buildMailer();

            $email = (new Email())
                ->from($queued->from)
                ->to($queued->to)
                ->subject($queued->subject)
                ->html($queued->body)
                ->text($this->htmlToText($queued->body));

            foreach ($this->loadBccRules() as $rule) {
                if (str_contains($queued->subject, $rule['prefix'])) {
                    $email->addBcc($rule['address']);
                    break;
                }
            }

            if ($queued->relationLoaded('attachments')) {
                foreach ($queued->attachments as $attachment) {
                    $real = realpath($attachment->path);
                    if ($real === false || !$this->isPathAllowed($real)) {
                        Log::warning('EmailSenderService: skipping unsafe/missing attachment', ['path' => $attachment->path]);
                        continue;
                    }
                    $part = new DataPart(new File($real), $attachment->name, $attachment->mime ?? 'application/octet-stream');
                    // Inline (cid) přílohy — např. QR platba — musí být inline, aby je
                    // Symfony vložilo do multipart/related a přepsalo `cid:<name>` v HTML
                    // na Content-ID. Bez toho jde PNG jako běžná příloha a v těle e-mailu
                    // zůstane rozbitý obrázek.
                    if (!empty($attachment->inline)) {
                        $part->asInline();
                    }
                    $email->addPart($part);
                }
            }

            $mailer->send($email);
            return true;

        } catch (\Throwable $e) {
            Log::error('EmailSenderService: send failed', [
                'id'    => $queued->id,
                'to'    => $queued->to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function buildMailer(): Mailer
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
            return new Mailer(Transport::fromDsn('sendmail://default'));
        }

        $scheme = ($encryption === 'ssl') ? 'smtps' : 'smtp';
        $query  = ($encryption === 'tls') ? '?encryption=tls' : '';

        if ($user !== '') {
            $dsn = "{$scheme}://" . rawurlencode($user) . ':' . rawurlencode($pass) . "@{$host}:{$port}{$query}";
        } else {
            $dsn = "{$scheme}://{$host}:{$port}{$query}";
        }

        return new Mailer(Transport::fromDsn($dsn));
    }

    public function loadBccRules(): array
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

    public function isPathAllowed(string $realPath): bool
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

    public function htmlToText(string $html): string
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
