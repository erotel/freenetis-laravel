<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Members\Events\MembershipEnded;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\EndMode;
use App\Domains\Notifications\Repositories\EmailQueueRepository;
use App\Models\Message;
use App\Models\Setting;

/**
 * E-mail o ukončení dle módu (mód 1 bez e-mailu). U vratky placeholdery
 * {ucet}/{balance} = vracená částka a v příloze PDF dobropisu.
 */
class SendTerminationEmail
{
    private const TEMPLATES = [
        EndMode::NOT_PAYING  => 94, // Bývalý člen bez platby (neplacení)
        EndMode::REFUND      => 96, // Vrácení platby bývalému členovi
        EndMode::OWN_REQUEST => 34, // Ukončení na vlastní žádost
    ];

    public function __construct(
        private MemberRepository $members,
        private EmailQueueRepository $emails,
    ) {
    }

    public function handle(MembershipEnded $event): void
    {
        $templateId = self::TEMPLATES[$event->endMode] ?? null;
        if (!$templateId) {
            return;
        }
        $message = $this->emails->emailTemplate($templateId);
        if (!$message) {
            return;
        }
        $email = $this->members->primaryEmail($event->memberId);
        if (!$email) {
            return;
        }

        $extra = [];
        if ($event->endMode === EndMode::REFUND) {
            $extra['ucet']    = $event->refund?->targetAccount ?? '';
            $extra['balance'] = number_format($event->refund?->amount ?? 0, 2, ',', ' ');
        }
        $body   = Message::substitute($message->email_text, app(\App\Domains\Notifications\Support\MemberPlaceholders::class)->forMember($event->memberId, $extra));
        $prefix = Setting::get('email_subject_prefix', '');

        $emailId = $this->emails->enqueue(
            Setting::get('email_default_email', 'noreply@pvfree.net'),
            $email,
            ($prefix ? $prefix . ' :: ' : '') . $message->name,
            $body,
        );

        $pdf = $event->refund?->pdfPath;
        if ($event->endMode === EndMode::REFUND && $pdf && file_exists($pdf)) {
            $this->emails->attach($emailId, $pdf, 'dobropis_' . $event->refund->docNumber . '.pdf', 'application/pdf');
        }
    }
}
