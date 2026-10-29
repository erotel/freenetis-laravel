<?php

namespace Tests\Feature\Security;

use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Support\SignLinkKind;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Veřejné podepisování posílá placené SMS bez přihlášení — platným (i starým)
 * odkazem šlo posílat SMS donekonečna a souběžné požadavky obešly resend okno.
 * Skutečný OtpService v testovacím režimu (bez odeslání SMS).
 */
class SignSmsLimitsTest extends DatabaseTestCase
{
    use \Tests\Support\IssuesContractAddonLinks;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('otp_test_mode', 1);
        Setting::set('otp_resend_window_sec', 60);
        Setting::set('otp_daily_limit', '');
        $memberId = (int) DB::table('members')->insertGetId(['name' => 'Char Sms', 'type' => 18, 'registration' => 0, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $this->contractId = (int) DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $memberId, 'contract_no' => 'SML-CHAR-SMS-' . uniqid(), 'status' => 'draft', 'phone' => '+420777123456', 'created_at' => now(),
        ]);
        DB::connection('contracts')->table('contract_parties')->insert(['contract_id' => $this->contractId, 'full_name' => 'Char Sms',
            'email' => 'charsms@example.invalid', 'created_at' => now()]);
    }

    private function token(?int $addonId = null): string
    {
        $this->actingAs(User::find((int) DB::table('users')->where('type', 1)->value('id')));
        $url = $addonId ? $this->issueAddonLink($addonId)['url'] : app(IssueSignLinkAction::class)->handle($this->contractId, SignLinkKind::Contract)['url'];
        auth()->logout();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q['t'];
    }

    private function otps(): int
    {
        return DB::connection('contracts')->table('contract_otps')->where('contract_id', $this->contractId)->count()
             + DB::connection('contracts')->table('contract_addon_otps')->where('contract_id', $this->contractId)->count();
    }

    private function send(string $t)
    {
        return $this->postJson(route('sign.otp.send'), ['t' => $t, 'phone' => '777123456']);
    }

    public function test_resend_okno_a_uvolneni_po_neodeslani(): void
    {
        $t = $this->token();
        $this->send($t)->assertOk();
        $this->send($t)->assertStatus(429)->assertJson(['error' => 'Požadavek odeslán, počkejte na sms.']);
        $this->assertSame(1, $this->otps());

        // SMS se nepodařilo odeslat (žádný driver) → okno se uvolní, klient zkusí hned znovu
        Cache::flush();
        Setting::set('otp_test_mode', 0);
        Setting::set('sms_driver', 999);   // žádný driver — nikdy skutečná SMS
        $this->send($t)->assertStatus(502);
        $this->send($t)->assertStatus(502);
    }

    public function test_denni_limit_na_smlouvu(): void
    {
        $t = $this->token();
        foreach ([3, 2] as $n) {
            foreach (range(1, $n) as $_) {
                DB::connection('contracts')->table($n === 3 ? 'contract_otps' : 'contract_addon_otps')->insert([
                    'contract_id' => $this->contractId, 'otp_hash' => str_repeat('0', 64), 'attempts_left' => 0,
                    'expires_at' => now()->subHour(), 'created_at' => now()->startOfDay()->addMinute(),
                ]);
            }
        }
        $this->send($t)->assertStatus(429)
            ->assertJson(['error' => 'Dosažen denní limit ověřovacích SMS. Zkuste to zítra nebo kontaktujte podporu.']);
        $this->assertSame(5, $this->otps());

        // včerejší se nepočítají
        DB::connection('contracts')->table('contract_otps')->where('contract_id', $this->contractId)->update(['created_at' => now()->subDay()]);
        $this->send($t)->assertOk();
    }

    public function test_sms_k_dodatku_jen_kdyz_ceka_na_podpis(): void
    {
        $t = $this->token();
        $this->postJson(route('sign.addon.otp.send'), ['t' => $t])->assertStatus(409)->assertExactJson(['error' => 'Žádný dodatek nečeká na podpis.']);
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['addon' => 1, 'addon_signed' => 1]);
        $this->postJson(route('sign.addon.otp.send'), ['t' => $t])->assertStatus(409);
        $this->assertSame(0, $this->otps());

        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['addon_signed' => 0]);
        $this->postJson(route('sign.addon.otp.send'), ['t' => $t])->assertOk();
        $this->assertSame(1, $this->otps());

        // dodatek tarif/služba/místo z odkazu s aid
        Cache::flush();
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['addon' => 0, 'status' => 'signed']);
        $aid = (int) DB::connection('contracts')->table('contract_addons')->insertGetId(['contract_id' => $this->contractId, 'type' => 'connection_point',
            'effective_date' => now()->toDateString(), 'status' => 'draft']);
        $ta = $this->token($aid);
        DB::connection('contracts')->table('contract_addons')->where('id', $aid)->update(['status' => 'signed']);
        $this->postJson(route('sign.addon.otp.send'), ['t' => $ta])->assertStatus(409);
        DB::connection('contracts')->table('contract_addons')->where('id', $aid)->update(['status' => 'otp_sent']);
        $this->postJson(route('sign.addon.otp.send'), ['t' => $ta])->assertOk();
        $this->assertSame(2, $this->otps());
    }

    public function test_throttle_sms_a_cele_sign(): void
    {
        $t = $this->token();
        $this->send($t)->assertOk();
        $this->send($t)->assertStatus(429);
        $this->send($t)->assertStatus(429);
        $this->send($t)->assertStatus(429)->assertExactJson(['error' => 'Příliš mnoho požadavků, zkuste to za chvíli.']);

        for ($i = 0; $i < 26; $i++) {
            $this->postJson(route('sign.info'), ['t' => $t])->assertOk();
        }
        $this->postJson(route('sign.info'), ['t' => $t])->assertStatus(429);
    }
}
