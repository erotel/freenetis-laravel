<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Domains\Notifications\Support\NotificationResult;
use App\Domains\Notifications\Support\Outbox;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Hromadné upozornění zprávou: per člen přesměrování (aktivace nahradí /
 * ponechat / deaktivace — v jedné transakci kvůli souběhu s cronem; whitelist
 * se respektuje, pokud zpráva nemá ignore_whitelist), e-mail a SMS s {comment}
 * po dávkách mimo transakci (rozeslané dávky zůstanou ve frontě i při chybě).
 * Oprávnění kontroluje volající.
 */
class NotifyMembersAction
{
    public function __construct(private RedirectionRepository $redirects)
    {
    }

    /**
     * @param array<int,int> $redirections member_id => akce (NotifyMemberAction::ACTIVATE|KEEP|DEACTIVATE)
     * @param int[] $emailMemberIds
     * @param int[] $smsMemberIds
     */
    public function handle(object $message, string $comment, array $redirections, array $emailMemberIds, array $smsMemberIds, ?int $userId): NotificationResult
    {
        $result = new NotificationResult();
        $now    = now()->format('Y-m-d H:i:s');

        $all = array_unique(array_map('intval', array_merge(array_keys($redirections), $emailMemberIds, $smsMemberIds)));
        $whitelisted = $this->redirects->whitelisted($all);

        DB::transaction(function () use ($message, $comment, $userId, $now, $redirections, $whitelisted, $result) {
            foreach ($redirections as $memberId => $action) {
                $memberId = (int) $memberId;
                $action   = (int) $action;
                if ($action === NotifyMemberAction::KEEP) continue;

                $ipIds = $this->redirects->memberIpIds($memberId);
                if ($ipIds->isEmpty()) continue;

                if ($action === NotifyMemberAction::DEACTIVATE) {
                    $result->redirectsDeactivated += $this->redirects->remove($message->id, $ipIds);
                } elseif ($action === NotifyMemberAction::ACTIVATE) {
                    if (!$message->ignore_whitelist && isset($whitelisted[$memberId])) continue;
                    $result->redirectsActivated += $this->redirects->replace($message->id, $ipIds, $userId, $comment ?: null, $now);
                }
            }
        });

        if ($message->email_text && $emailMemberIds) {
            $subject = Outbox::subject($message->name);
            $body    = str_replace('{comment}', $comment, $message->email_text);
            $result->emails = Outbox::insertChunked('email_queues', $this->redirects->contacts($emailMemberIds, Outbox::CONTACT_EMAIL)->map(fn ($to) => [
                'from' => Outbox::from(), 'to' => $to, 'subject' => $subject, 'body' => $body, 'state' => 0, 'access_time' => $now,
            ]));
        }

        if ($message->sms_text && ($gateway = Outbox::smsGateway()) && $smsMemberIds) {
            $text = str_replace('{comment}', $comment, $message->sms_text);
            $result->sms = Outbox::insertChunked('sms_messages', $this->redirects->contacts($smsMemberIds, Outbox::CONTACT_PHONE)
                ->map(fn ($phone) => Outbox::smsRow($userId, $now, $text, $phone, $gateway)));
        }

        if ($result->redirectsActivated || $result->redirectsDeactivated) {
            AuditLogger::log('notified', 'messages_ip_addresses', $message->id, null, [
                'message_id'        => $message->id,
                'redir_activated'   => $result->redirectsActivated,
                'redir_deactivated' => $result->redirectsDeactivated,
            ]);
        }

        return $result;
    }
}
