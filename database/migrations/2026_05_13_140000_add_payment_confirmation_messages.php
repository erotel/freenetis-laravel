<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vytvoří systémovou Message šablonu pro email s fakturou zákazníkovi.
 *
 *   - 'Payment confirmation - customer' — pro fakturační účty (payment_purpose=1),
 *     posílá InvoiceService::queueInvoiceEmail po vystavení faktury, s PDF přílohou.
 *
 * Pro člena (payment_purpose=0) používá InvoiceService::queuePaymentConfirmation
 * existující Message id=9 ("Received payment notice") — tu nezakládáme, je už v
 * seedu/dumpu Kohany.
 *
 * Lookup v kódu probíhá podle 'name', ne 'id', aby IDčka mohla být v různých
 * instalacích různá. Migrace je idempotentní — pokud řádek se stejným name už
 * existuje (čistá instalace s předinstalovaným seedem, opakované migrace), nic
 * nepřepisuje, aby case admin už šablonu upravil v UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = 'Payment confirmation - customer';
        if (DB::table('messages')->where('name', $name)->exists()) {
            return;
        }

        DB::table('messages')->insert([
            'name'       => $name,
            'text'       => null,
            'email_text' => <<<HTML
<p>Vážený zákazníku,</p>
<p>v příloze zasíláme fakturu č. <strong>{invoice_nr}</strong> na částku <strong>{payment_amount} Kč</strong>.</p>
<p>ID platby: {payment_ids}</p>
<p>Datum splatnosti: {date_due}</p>
<p>S pozdravem,<br>PVfree.net, z.s.</p>
HTML,
            'sms_text'         => null,
            'type'             => null,
            'self_cancel'      => 0,
            'ignore_whitelist' => 0,
        ]);
    }

    public function down(): void
    {
        DB::table('messages')->where('name', 'Payment confirmation - customer')->delete();
    }
};
