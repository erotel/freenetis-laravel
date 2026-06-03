<?php

namespace App\Services;

use App\Helpers\MemberType;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Sesynchronizuj přesměrování u jednoho člena podle payment_blocked.
 *
 * Pro zákazníka (type 2, 18) používá DEBTOR_MESSAGE (id 5, type 5),
 * pro člena (type 90, 3) DEBTOR_MESSAGE_CLEN (id 114, type 25). Tyto zprávy
 * jsou v PVfree.net pojmenovány "Nedostatečná výše konta (zákazník)" /
 * "(členové)" a jsou napojené na messages_automatical_activations pro
 * email + redirect cestou NotificationActivation cronu.
 *
 * Volá se:
 *   • po platbě (PaymentBackchargeService při unblock)
 *   • hodinově z cronu RedirectBlockedMembers (catch-up + prune)
 *
 * Idempotentní — opakovaný běh nic nezpůsobí. Vrací true pokud došlo
 * k jakémukoliv zápisu/mazání, false pokud no-op.
 */
class PaymentBlockedRedirectService
{
    public function refreshForMember(int $memberId): bool
    {
        if (!\App\Models\Setting::get('payment_blocked_redirect_enabled', 1)) {
            return false;
        }

        $member = DB::table('members')->where('id', $memberId)->first(['id', 'type', 'payment_blocked']);
        if (!$member) {
            return false;
        }

        $messageType = $this->messageTypeForMember((int) $member->type);
        if ($messageType === null) {
            return false; // bývalý / čekatel — flag i redirect by neměl být nastavený
        }

        $message = Message::where('type', $messageType)->first();
        if (!$message) {
            return false;
        }

        $ipIds = DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('u.member_id', $memberId)
            ->pluck('ip.id');

        if ($ipIds->isEmpty()) {
            return false;
        }

        if ((int) $member->payment_blocked === 1) {
            $now  = now();
            $rows = $ipIds->map(fn ($ipId) => [
                'message_id'    => $message->id,
                'ip_address_id' => $ipId,
                'user_id'       => auth()->id() ?: 1,
                'comment'       => 'Auto: nedostatečná výše konta (prepaid)',
                'datetime'      => $now,
            ])->all();
            DB::table('messages_ip_addresses')->insertOrIgnore($rows);
            return true;
        }

        // Není (už) payment_blocked → smaž případné přesměrování z OBOU zpráv
        // (zákaznická i členská), kdyby admin omylem přepnul typ člena.
        $deleted = DB::table('messages_ip_addresses')
            ->whereIn('message_id', $this->bothMessageIds())
            ->whereIn('ip_address_id', $ipIds)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Zákazník (2/18) → DEBTOR_MESSAGE (5),
     * Člen (90/3) → DEBTOR_MESSAGE_CLEN (25).
     * Cokoliv jiného (bývalí, čekatelé) → null, redirect nemá smysl.
     */
    private function messageTypeForMember(int $memberType): ?int
    {
        return match ($memberType) {
            MemberType::CUSTOMER, MemberType::PENDING_CUSTOMER => Message::DEBTOR_MESSAGE,
            MemberType::REGULAR,  3                            => Message::DEBTOR_MESSAGE_CLEN,
            default                                            => null,
        };
    }

    /**
     * IDs obou messages (5 + 114) pro prune při unblock.
     * Cachováno na request — ne příliš velký dotaz, ale stejně bezdůvodný spam.
     */
    private function bothMessageIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = DB::table('messages')
                ->whereIn('type', [Message::DEBTOR_MESSAGE, Message::DEBTOR_MESSAGE_CLEN])
                ->pluck('id')->all();
        }
        return $ids;
    }
}
