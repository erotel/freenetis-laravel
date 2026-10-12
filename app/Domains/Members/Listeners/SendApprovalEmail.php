<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Members\Events\MemberApproved;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Notifications\Repositories\EmailQueueRepository;
use App\Helpers\MemberType;
use App\Models\Message;
use App\Models\Setting;

/** Po schválení zařadí uvítací e-mail ze šablony podle původního typu čekatele. */
class SendApprovalEmail
{
    private const TEMPLATE_MEMBER   = 10;   // Žádost o členství schválena
    private const TEMPLATE_CUSTOMER = 116;  // schválení zákazníka

    public function __construct(
        private MemberRepository $members,
        private EmailQueueRepository $emails,
    ) {
    }

    public function handle(MemberApproved $event): void
    {
        $templateId = $event->previousType === MemberType::PENDING_MEMBER
            ? self::TEMPLATE_MEMBER
            : self::TEMPLATE_CUSTOMER;

        $message = $this->emails->emailTemplate($templateId);
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
