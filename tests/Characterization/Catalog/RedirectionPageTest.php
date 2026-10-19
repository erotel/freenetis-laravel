<?php

namespace Tests\Characterization\Catalog;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Charakterizace stránky přesměrovaného zákazníka (RedirectionController,
 * veřejná, podle IP klienta): aktivní přesměrování → nejnovější zpráva
 * s dosazenými placeholdery, člen, saldo, VS; neevidovaná IP → zpráva
 * „neznámé zařízení"; evidovaná bez přesměrování → obecná stránka; bez cache.
 */
class RedirectionPageTest extends CatalogTestCase
{
    private function visit(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/redirection');
    }

    public function test_aktivni_presmerovani(): void
    {
        $m = (int) DB::table('members')->insertGetId(['name' => "Char {$this->tag}", 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $m, 'account_attribute_id' => 221100, 'balance' => -150, 'name' => 'c']);
        do {
            $vs = (string) random_int(90000000, 99999999);
        } while (DB::table('variable_symbols')->where('variable_symbol', $vs)->exists());
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => $vs]);
        $ip = '10.244.' . random_int(1, 250) . '.' . random_int(1, 250);
        $ipId = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => $ip, 'member_id' => $m]);
        $old = (int) DB::table('messages')->insertGetId(['name' => 'Stara', 'text' => 'stara', 'type' => 0, 'self_cancel' => 0, 'ignore_whitelist' => 0]);
        $new = (int) DB::table('messages')->insertGetId(['name' => "Dluh {$this->tag}", 'text' => 'Zaplaťte na VS {variable_symbol}.', 'type' => 0, 'self_cancel' => 0, 'ignore_whitelist' => 0]);
        DB::table('messages_ip_addresses')->insert([
            ['message_id' => $old, 'ip_address_id' => $ipId, 'datetime' => '2099-01-01 10:00:00', 'comment' => 'a'],
            ['message_id' => $new, 'ip_address_id' => $ipId, 'datetime' => '2099-01-02 10:00:00', 'comment' => 'b'],
        ]);

        $res = $this->visit($ip)->assertOk();
        $this->assertSame('text/html; charset=utf-8', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $res->assertSee("Zaplaťte na VS {$vs}.", false)->assertSee("Char {$this->tag}")->assertSee('Připojení omezeno')->assertDontSee('stara');
    }

    public function test_nezname_a_bez_presmerovani(): void
    {
        $unknown = Message::where('type', Message::UNKNOWN_DEVICE_MESSAGE)->first();
        $res = $this->visit('10.243.' . random_int(1, 250) . '.' . random_int(1, 250))->assertOk();
        if ($unknown && trim((string) $unknown->text) !== '') {
            $res->assertSee('Připojení omezeno')->assertSee((string) $unknown->text, false);
        }
        $known = '10.242.' . random_int(1, 250) . '.' . random_int(1, 250);
        DB::table('ip_addresses')->insert(['ip_address' => $known]);
        $this->visit($known)->assertOk()->assertSee('Informace o připojení')->assertSee($known);
    }
}
