<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Type 32 = "Čekající zákazník" (PENDING_CUSTOMER_MESSAGE).
        // self_cancel=0 → příjemce si přesměrování sám neodklikne; zruší se
        // až změnou members.type z 18 na 2 (cron members:redirect-pending-customers).
        // ignore_whitelist=0 → respektuj whitelist (admin/technici se neredirectují).
        $existing = DB::table('messages')->where('type', 32)->first();
        if ($existing) {
            return;
        }

        DB::table('messages')->insert([
            'name'             => 'Čekající zákazník — nepodepsaná smlouva',
            'type'             => 32,
            'self_cancel'      => 0,
            'ignore_whitelist' => 0,
            'text'             => <<<'HTML'
<p><strong>Vážený zákazníku,</strong></p>
<p>internet je dočasně přesměrován, protože dosud nebyla podepsána smlouva s PVfree.net z.s.</p>
<p>Postupujte podle pokynů, které jste obdrželi e-mailem (odkaz pro elektronický podpis smlouvy).</p>
<p>Pokud e-mail nemáte, kontaktujte HelpLinku: <strong>588 207 234</strong> nebo navštivte
<a href="https://www.pvfree.net">www.pvfree.net</a>.</p>
<p>Po podpisu smlouvy bude přístup automaticky obnoven (nejpozději do hodiny).</p>
HTML,
            'email_text'       => null,
            'sms_text'          => 'PVfree.net: Internet přesměrován — chybí podpis smlouvy. Postupujte podle pokynů v emailu nebo volejte 588 207 234.',
        ]);
    }

    public function down(): void
    {
        $msg = DB::table('messages')->where('type', 32)->first();
        if (!$msg) return;

        // Smaž navázané redirecty + samotnou zprávu (nemělo by se v praxi
        // používat — migrace je idempotentní vůči up()).
        DB::table('messages_ip_addresses')->where('message_id', $msg->id)->delete();
        DB::table('messages')->where('id', $msg->id)->delete();
    }
};
