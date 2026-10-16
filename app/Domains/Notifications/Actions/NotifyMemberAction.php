<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Domains\Notifications\Support\NotificationResult;
use App\Domains\Notifications\Support\Outbox;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Upozornění jednoho člena zprávou (karta člena): přesměrování všech jeho IP
 * (aktivace upsertem / deaktivace), e-mail a SMS na jeho kontakty. Whitelist
 * se tu NEuplatňuje a text jde doslova (bez {comment}) — na rozdíl od
 * hromadného {@see NotifyMembersAction}. Oprávnění kontroluje volající.
 */
class NotifyMemberAction
{
    public const ACTIVATE   = 1;
    public const KEEP       = 2;
    public const DEACTIVATE = 3;

    public function __construct(private RedirectionRepository $redirects)
    {
    }

    public function handle(int $memberId, object $message, string $comment, int $redirection, int $email, int $sms, ?int $userId): NotificationResult
    {
        $result = new NotificationResult();

        if ($redirection !== self::KEEP) {
            $ipIds = $this->redirects->memberIpIds($memberId);
            if ($redirection === self::ACTIVATE) {
                $result->redirectsActivated = $this->redirects->upsert($message->id, $ipIds, $userId, $comment ?: null, now()->format('Y-m-d H:i:s'));
            } elseif ($redirection === self::DEACTIVATE) {
                $result->redirectsDeactivated = $this->redirects->remove($message->id, $ipIds);
            }
        }

        if ($email === self::ACTIVATE && $message->email_text) {
            foreach ($this->redirects->contacts([$memberId], Outbox::CONTACT_EMAIL) as $to) {
                DB::table('email_queues')->insert([
                    'from' => Outbox::from(), 'to' => $to, 'subject' => Outbox::subject($message->name), 'body' => $message->email_text, 'state' => 0,
                ]);
                $result->emails++;
            }
        }

        if ($sms === self::ACTIVATE && $message->sms_text && ($gateway = Outbox::smsGateway())) {
            $phones = $this->redirects->contacts([$memberId], Outbox::CONTACT_PHONE);
            $now    = now()->format('Y-m-d H:i:s');
            DB::transaction(function () use ($phones, $message, $userId, $now, $gateway, $result) {
                foreach ($phones as $phone) {
                    DB::table('sms_messages')->insert(Outbox::smsRow($userId, $now, $message->sms_text, $phone, $gateway));
                    $result->sms++;
                }
            });
        }

        if ($result->redirectsActivated || $result->redirectsDeactivated) {
            AuditLogger::log('notified', 'messages_ip_addresses', $memberId, null, [
                'member_id'         => $memberId,
                'redir_activated'   => $result->redirectsActivated,
                'redir_deactivated' => $result->redirectsDeactivated,
            ]);
        }

        return $result;
    }
}
