<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Documents\RegistrationDocument;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Notifications\Repositories\EmailQueueRepository;
use App\Models\Member;
use App\Models\Setting;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Odešle PDF dokument člena (přihláška / ukončení / výpověď) e-mailem na
 * nejstarší e-mail hlavního uživatele. PDF se uloží do storage/app/
 * email-attachments (allowlist fronty) a zůstává i po odeslání.
 * Oprávnění kontroluje volající.
 */
class EmailRegistrationDocumentAction
{
    public function __construct(
        private MemberRepository $members,
        private RegistrationDocument $document,
        private EmailQueueRepository $emails,
    ) {
    }

    /**
     * @return ?string adresa, na kterou se e-mail zařadil; null = člen nemá e-mail
     * @throws ModelNotFoundException člen bez hlavního uživatele
     */
    public function handle(int $memberId, string $type): ?string
    {
        $userId = $this->members->mainUserId($memberId)
            ?? throw (new ModelNotFoundException())->setModel(Member::class, [$memberId]);
        $email = $this->members->firstEmailOfUser($userId);
        if (!$email) {
            return null;
        }

        [$pdf, $filename] = $this->document->render($memberId, $type);

        $dir = storage_path('app/email-attachments');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . preg_replace('/\.pdf$/', '', $filename) . '-' . uniqid() . '.pdf';
        file_put_contents($path, $pdf);

        $subject   = RegistrationDocument::SUBJECTS[$type] ?? 'Dokument';
        $assocName = Setting::get('title', 'Sdružení');

        $emailId = $this->emails->enqueue(
            Setting::get('email_default_email', 'noreply@pvfree.net'),
            $email,
            $subject,
            "<p>Dobrý den,</p>"
                . "<p>v příloze posíláme dokument <strong>" . e($subject) . "</strong> ve formátu PDF.</p>"
                . "<p>S pozdravem,<br>" . e($assocName) . "</p>",
        );
        $this->emails->attach($emailId, $path, $filename, 'application/pdf');

        return $email;
    }
}
