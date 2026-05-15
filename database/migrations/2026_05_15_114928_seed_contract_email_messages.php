<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            28 => [
                'name'       => 'Smlouva — odkaz pro podpis',
                'email_text' => '<p>Dobrý den,</p>'
                    . '<p>zasíláme Vám odkaz pro elektronický podpis smlouvy:</p>'
                    . '<p><a href="{url}">{url}</a></p>'
                    . '<p>Odkaz je platný 7 dní.</p>'
                    . '<p>S pozdravem<br>PVfree.net</p>',
            ],
            29 => [
                'name'       => 'Dodatek smlouvy — odkaz pro podpis',
                'email_text' => '<p>Dobrý den,</p>'
                    . '<p>zasíláme Vám odkaz pro elektronický podpis dodatku smlouvy:</p>'
                    . '<p><a href="{url}">{url}</a></p>'
                    . '<p>Odkaz je platný 7 dní.</p>'
                    . '<p>S pozdravem<br>PVfree.net</p>',
            ],
            30 => [
                'name'       => 'Smlouva — po podpisu',
                'email_text' => '<p>Dobrý den,</p>'
                    . '<p>v příloze Vám zasíláme podepsanou smlouvu <strong>{contract_no}</strong>, '
                    . 'ceník služeb a Všeobecné obchodní podmínky.</p>'
                    . '<p>Dokumenty si prosím uschovejte pro svoji evidenci.</p>'
                    . '<p>S pozdravem,<br>PVfree.net, z.s.</p>',
            ],
            31 => [
                'name'       => 'Dodatek smlouvy — po podpisu',
                'email_text' => '<p>Dobrý den,</p>'
                    . '<p>v příloze Vám zasíláme podepsaný dodatek ke smlouvě <strong>{contract_no}</strong>.</p>'
                    . '<p>Dokument si prosím uschovejte pro svoji evidenci.</p>'
                    . '<p>S pozdravem,<br>PVfree.net, z.s.</p>',
            ],
        ];

        foreach ($rows as $type => $data) {
            $exists = DB::table('messages')->where('type', $type)->exists();
            if ($exists) {
                continue;
            }
            DB::table('messages')->insert([
                'type'             => $type,
                'name'             => $data['name'],
                'text'             => '',
                'email_text'       => $data['email_text'],
                'sms_text'         => null,
                'self_cancel'      => 0,
                'ignore_whitelist' => 0,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('messages')->whereIn('type', [28, 29, 30, 31])->delete();
    }
};
