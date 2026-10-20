<?php

namespace App\Http\Controllers\Members;

use App\Domains\Members\Actions\EmailRegistrationDocumentAction;
use App\Domains\Members\Documents\RegistrationDocument;
use App\Http\Controllers\Controller;

/** Dokumenty člena: přihláška, ukončení členství, výpověď smlouvy (PDF, e-mailem). */
class MemberDocumentController extends Controller
{
    /**
     * Export přihlášky nebo ukončení členství jako PDF (inline v prohlížeči).
     * type: 'registration' = Přihláška, 'end' = Ukončení členství, 'contract_end' = Výpověď smlouvy
     */
    public function show(int $id, string $type, RegistrationDocument $document)
    {
        abort_unless(in_array($type, RegistrationDocument::TYPES, true), 404);
        // Staff (view_all) → jakýkoli člen i typ. Řádný člen (view_own) → jen SVOJI
        // přihlášku (own + type=registration); ukončení/výpověď zůstává staff-only.
        $isOwn  = ($id === (int) (auth()->user()?->member_id ?? 0));
        $allowed = $this->aclCheck('view_all', 'Members_Controller', 'registration_export')
            || ($isOwn && $type === 'registration'
                && $this->aclCheck('view_own', 'Members_Controller', 'registration_export'));
        abort_unless($allowed, 403);

        [$pdfString, $filename] = $document->render($id, $type);

        return response($pdfString, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * Odeslat stejné PDF (přihláška / ukončení / výpověď) e-mailem na první
     * e-mail kontakt hlavního uživatele. Jen staff (view_all).
     */
    public function email(int $id, string $type, EmailRegistrationDocumentAction $send)
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'registration_export'), 403);
        abort_unless(in_array($type, RegistrationDocument::TYPES, true), 404);

        $email = $send->handle($id, $type);
        if (!$email) {
            return back()->with('error', 'Člen nemá v kontaktech žádný e-mail.');
        }

        return back()->with('success', 'PDF „' . RegistrationDocument::SUBJECTS[$type] . '" zařazeno do fronty k odeslání na ' . $email . '.');
    }
}
