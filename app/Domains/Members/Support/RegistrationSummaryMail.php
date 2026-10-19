<?php

namespace App\Domains\Members\Support;

use App\Domains\Members\Repositories\MemberRepository;
use App\Models\EmailQueue;
use App\Models\EmailQueueAttachment;
use App\Models\Setting;

/**
 * Souhrn smlouvy (PDF z nastavení registration_summary_pdf) e-mailem novému
 * zákazníkovi po samoregistraci. Ne čekajícímu členovi (17) — ten dostává
 * přihlášku k podpisu jiným tokem. Tiše nic, když je vypnuto, chybí PDF
 * nebo e-mail; selhání fronty registraci nezastaví.
 */
class RegistrationSummaryMail
{
    public function __construct(private MemberRepository $members)
    {
    }

    public function send(int $userId, int $registrationType): void
    {
        if (!Setting::get('registration_summary_enabled', 0) || $registrationType === 17) {
            return;
        }

        $pdfSetting = trim((string) Setting::get('registration_summary_pdf', ''));
        if ($pdfSetting === '') {
            return;
        }
        $pdfPath = str_starts_with($pdfSetting, '/') ? $pdfSetting : storage_path('app/private/' . ltrim($pdfSetting, '/'));
        if (!is_file($pdfPath)) {
            return;
        }

        $email = $this->members->firstEmailOfUser($userId);
        if (!$email) {
            return;
        }

        try {
            $emailQueue = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => 'Shrnutí smlouvy - PVfree.net',
                'body'        => '<p>Dobrý den,</p>'
                    . '<p>děkujeme za registraci. V příloze posíláme shrnutí Vaší smlouvy ve formátu PDF.</p>'
                    . '<p>S pozdravem,<br>PVfree.net, z.s.</p>',
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);
            EmailQueueAttachment::create([
                'email_queue_id' => $emailQueue->id,
                'path'           => $pdfPath,
                'name'           => 'smlouva_shrnuti.pdf',
                'mime'           => 'application/pdf',
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // registrace musí projít, i když selže fronta e-mailů
        }
    }
}
