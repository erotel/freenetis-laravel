<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Members\Events\MemberApproved;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Notifications\Repositories\EmailQueueRepository;
use App\Helpers\MemberType;
use App\Models\Message;
use App\Models\Setting;

/**
 * Po schválení na řádného člena zařadí uvítací e-mail (šablona 10).
 * Zákazníkům se neposílá nic — ti dostávají e-mail se smlouvou po podpisu.
 */
class SendApprovalEmail
{
    private const TEMPLATE_MEMBER = 10;   // Žádost o členství schválena

    public function __construct(
        private MemberRepository $members,
        private EmailQueueRepository $emails,
    ) {
    }

    public function handle(MemberApproved $event): void
    {
        if ($event->newType !== MemberType::REGULAR) {
            return;
        }

        $message = $this->emails->emailTemplate(self::TEMPLATE_MEMBER);
        if (!$message) {
            return;
        }

        $email = $this->members->primaryEmail($event->memberId);
        if (!$email) {
            return;
        }

        $body   = Message::substitute($message->email_text, Message::buildPlaceholders($event->memberId));
        $prefix = Setting::get('email_subject_prefix', '');

        $this->emails->enqueue(
            Setting::get('email_default_email', 'noreply@pvfree.net'),
            $email,
            ($prefix ? $prefix . ' :: ' : '') . $message->name,
            $body,
        );
    }
}
