<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Type 33 = PAYMENT_BLOCKED_MESSAGE — přesměrování pro členy, kteří
        // nemají dostatek kreditu na měsíční poplatek.
        // self_cancel=0 → přesměrování se zruší až po dorovnání kreditu
        // (cron redirect:blocked-members, PaymentBlockedRedirectService).
        // ignore_whitelist=0 → respektuj whitelist (admin/technici se neredirectují).
        $existing = DB::table('messages')->where('type', 33)->first();
        if ($existing) {
            return;
        }

        DB::table('messages')->insert([
            'name'             => 'Nedostatečný kredit',
            'type'             => 33,
            'self_cancel'      => 0,
            'ignore_whitelist' => 0,
            'text'             => <<<'HTML'
<p><strong>Vážený zákazníku,</strong></p>
<p>internet byl dočasně přesměrován, protože váš kredit nestačil na úhradu měsíčního poplatku.</p>
<p>Po připsání platby na váš účet bude přístup automaticky obnoven (nejpozději do hodiny).</p>
<p>V případě nejasností nás kontaktujte na HelpLince: <strong>588 207 234</strong>
nebo navštivte <a href="https://www.pvfree.net">www.pvfree.net</a>.</p>
HTML,
            'email_text'       => null,
            'sms_text'          => 'PVfree.net: Internet přesměrován — nedostatečný kredit. Po platbě bude přístup obnoven do hodiny.',
        ]);
    }

    public function down(): void
    {
        $msg = DB::table('messages')->where('type', 33)->first();
        if (!$msg) return;

        DB::table('messages_ip_addresses')->where('message_id', $msg->id)->delete();
        DB::table('messages')->where('id', $msg->id)->delete();
    }
};
