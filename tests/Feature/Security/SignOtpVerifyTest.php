<?php

namespace Tests\Feature\Security;

use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Support\SignLinkKind;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Ověření SMS kódu při veřejném podpisu se skutečným OtpService (mimo
 * testovací režim): kód se vloží přímo do DB, SMS se neposílá.
 */
class SignOtpVerifyTest extends DatabaseTestCase
{
    use \Tests\Support\IssuesContractAddonLinks;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('otp_test_mode', 0);
        Setting::set('sms_driver', 999);   // žádný driver — nikdy skutečná SMS
        $memberId = (int) DB::table('members')->insertGetId(['name' => 'Char Otp', 'type' => 18, 'registration' => 0, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $this->contractId = (int) DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $memberId, 'contract_no' => 'SML-CHAR-OTP-' . uniqid(), 'status' => 'otp_sent', 'phone' => '+420777123456', 'created_at' => now(),
        ]);
    }

    private function token(?int $addonId = null): string
    {
        $this->actingAs(User::find((int) DB::table('users')->where('type', 1)->value('id')));
        $url = $addonId ? $this->issueAddonLink($addonId)['url'] : app(IssueSignLinkAction::class)->handle($this->contractId, SignLinkKind::Contract)['url'];
        auth()->logout();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q['t'];
    }

    private function otp(string $table, string $code, array $extra = []): int
    {
        $pepper = (string) (Setting::get('otp_pepper') ?: config('services.contracts.otp_pepper', ''));
        return (int) DB::connection('contracts')->table($table)->insertGetId($extra + [
            'contract_id' => $this->contractId, 'otp_hash' => hash('sha256', $code . $pepper), 'attempts_left' => 5,
            'expires_at' => now()->addMinutes(5), 'created_at' => now(),
        ]);
    }

    public function test_kod_ke_smlouve_je_jednorazovy(): void
    {
        $t  = $this->token();
        $id = $this->otp('contract_otps', '482913');

        $this->postJson(route('sign.otp.verify'), ['t' => $t, 'otp' => '482913'])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(0, (int) DB::connection('contracts')->table('contract_otps')->where('id', $id)->value('attempts_left'));

        $this->postJson(route('sign.otp.verify'), ['t' => $t, 'otp' => '482913'])->assertStatus(410);
    }

    public function test_overeni_zamyka_radek_kodu(): void
    {
        $t = $this->token();
        $this->otp('contract_otps', '482913');
        $this->otp('contract_addon_otps', '482913');
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['addon' => 1]);

        DB::connection('contracts')->enableQueryLog();
        $this->postJson(route('sign.otp.verify'), ['t' => $t, 'otp' => '111222'])->assertStatus(401);
        $this->postJson(route('sign.addon.otp.verify'), ['t' => $t, 'otp' => '111222'])->assertStatus(401);
        $selects = collect(DB::connection('contracts')->getQueryLog())->pluck('query')
            ->filter(fn ($q) => preg_match('/^select .* from `contract(_addon)?_otps`/i', $q))->values();

        $this->assertCount(2, $selects);
        foreach ($selects as $q) {
            $this->assertStringEndsWith('for update', strtolower($q));
        }
    }
}
