<?php

namespace App\Services;

use App\Helpers\MemberType;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class PendingCustomerRedirectService
{
    /**
     * Sesynchronizuj přesměrování PENDING_CUSTOMER_MESSAGE u jednoho člena
     * podle jeho aktuálního members.type:
     *   - type == 18 (čekající zákazník)  → IP zařadit do messages_ip_addresses
     *   - cokoliv jiného                  → IP z messages_ip_addresses smazat
     *
     * Idempotentní — opakovaný běh nic nezpůsobí. Volá se z míst:
     *   • po podpisu smlouvy (PublicSignController::finalize)
     *   • při přidání IP zařízení (IpAddress/Iface/Device controllery)
     *
     * Vrací true pokud došlo k jakémukoliv zápisu/mazání, false pokud no-op.
     */
    public function refreshForMember(int $memberId): bool
    {
        if (!\App\Models\Setting::get('pending_customer_redirect_enabled', 1)) {
            return false;
        }

        $message = Message::where('type', Message::PENDING_CUSTOMER_MESSAGE)->first();
        if (!$message) {
            return false;
        }

        $member = DB::table('members')->where('id', $memberId)->first(['id', 'type']);
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

        if ((int) $member->type === MemberType::PENDING_CUSTOMER) {
            $now = now();
            $rows = $ipIds->map(fn ($ipId) => [
                'message_id'    => $message->id,
                'ip_address_id' => $ipId,
                'user_id'       => auth()->id() ?: 1,
                'comment'       => 'Auto: čekající zákazník (nepodepsaná smlouva)',
                'datetime'      => $now,
            ])->all();

            // insertOrIgnore — kompozitní PK (message_id, ip_address_id) brání duplicitám.
            DB::table('messages_ip_addresses')->insertOrIgnore($rows);
            return true;
        }

        // Member není (už) PENDING_CUSTOMER → smaž případné přesměrování.
        $deleted = DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->whereIn('ip_address_id', $ipIds)
            ->delete();

        return $deleted > 0;
    }
}
