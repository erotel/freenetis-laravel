<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

class PaymentBlockedRedirectService
{
    /**
     * Sesynchronizuj přesměrování PAYMENT_BLOCKED_MESSAGE u jednoho člena
     * podle jeho aktuálního members.payment_blocked:
     *   - payment_blocked == 1 → IP zařadit do messages_ip_addresses (přesměrování)
     *   - payment_blocked == 0 → IP z messages_ip_addresses smazat
     *
     * Idempotentní — opakovaný běh nic nezpůsobí. Volá se z míst:
     *   • po každé přijaté platbě (PaymentBackchargeService / ImportController)
     *   • hodinově z cronu RedirectBlockedMembers (catch-up + prune)
     *
     * Vrací true pokud došlo k jakémukoliv zápisu/mazání, false pokud no-op.
     */
    public function refreshForMember(int $memberId): bool
    {
        if (!\App\Models\Setting::get('payment_blocked_redirect_enabled', 1)) {
            return false;
        }

        $message = Message::where('type', Message::PAYMENT_BLOCKED_MESSAGE)->first();
        if (!$message) {
            return false;
        }

        $member = DB::table('members')->where('id', $memberId)->first(['id', 'payment_blocked']);
        if (!$member) {
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
                'comment'       => 'Auto: nedostatečný kredit na měsíční poplatek',
                'datetime'      => $now,
            ])->all();

            // insertOrIgnore — kompozitní PK (message_id, ip_address_id) brání duplicitám.
            DB::table('messages_ip_addresses')->insertOrIgnore($rows);
            return true;
        }

        // Member není (už) payment_blocked → smaž případné přesměrování.
        $deleted = DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->whereIn('ip_address_id', $ipIds)
            ->delete();

        return $deleted > 0;
    }
}
