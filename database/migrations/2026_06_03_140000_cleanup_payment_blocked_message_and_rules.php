<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrace prepaid přesměrování z vlastní zprávy type=33 zpět na
 * standardní DEBTOR_MESSAGE (5) a DEBTOR_MESSAGE_CLEN (25), přejmenované
 * v PVfree na "Nedostatečná výše konta (zákazník/členové)".
 *
 *  • Smaž zprávu type=33 (PAYMENT_BLOCKED_MESSAGE) — už ji nepoužíváme,
 *    PaymentBlockedRedirectService nyní vybírá podle typu člena 5/114.
 *  • Smaž z messages_automatical_activations hourly redirection rule
 *    pro DEBTOR_MESSAGE (rule id=48 v PVfree). Přesměrování řídí cron
 *    members:redirect-blocked podle payment_blocked flagu (přesnější
 *    než predikát balance < debtor_boundary). Email rules ponecháme.
 */
return new class extends Migration {
    public function up(): void
    {
        $msg33 = DB::table('messages')->where('type', 33)->first();
        if ($msg33) {
            DB::table('messages_ip_addresses')->where('message_id', $msg33->id)->delete();
            DB::table('messages_automatical_activations')->where('message_id', $msg33->id)->delete();
            DB::table('messages')->where('id', $msg33->id)->delete();
        }

        // HOURLY (type=5) redirection_enabled rules pro DEBTOR messages — smazat,
        // přesměrování řídí members:redirect-blocked cron přes flag (přesnější
        // než balance check, který filtruje na balance < debtor_boundary a tím
        // by vynechal členy s balance ≥ 0 ale payment_blocked=1).
        DB::table('messages_automatical_activations')
            ->whereIn('message_id', function ($q) {
                $q->select('id')->from('messages')->whereIn('type', [5, 25]);
            })
            ->where('type', 5) // HOURLY
            ->where('redirection_enabled', 1)
            ->delete();
    }

    public function down(): void
    {
        // Down() záměrně nevrací message type=33 — případně přidat ručně, ale
        // konstanta PAYMENT_BLOCKED_MESSAGE už není v Message.php.
    }
};
