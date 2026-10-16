<?php

namespace Tests\Characterization\Contracts;

use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Services\ContractService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace podpisových odkazů a tokenů (ContractService) před přesunem
 * do domény Contracts. Pět kopií téhož: smlouva (issueAccessLink), starý
 * dodatek (sendAddonLink), dodatek tarifu / služby / přípojného místa
 * (issue*AddonLink) + ověření tokenů (verify*Token).
 *
 * Token = b64url(JSON {cid[, aid], exp = +7 dní, rnd}) . b64url(HMAC-SHA256).
 * Token dodatku nese i cid → otevře i stránku smlouvy (záměr: /sign/info +
 * OTP); token smlouvy (bez aid) na dodatek neprojde. Typ dodatku hlídá až
 * get*Addon(), ne token.
 */
class SignLinkTest extends DatabaseTestCase
{
    private ContractService $svc;
    private int $memberId;
    private int $contractId;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ContractService::class);
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char Sign', 'type' => 2, 'registration' => 1, 'locked' => 0, 'address_point_id' => $apId,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
        ]);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $this->memberId, 'login' => 'charsign_' . uniqid(), 'password' => Hash::make('x'),
            'type' => User::MAIN_USER, 'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'C', 'surname' => 'S', 'comment' => '']);
        $this->email = 'charsign_' . uniqid() . '@example.invalid';
        $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => $this->email]);
        DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $cid]);

        $this->contractId = (int) DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $this->memberId, 'contract_no' => 'SML-CHAR-' . uniqid(), 'status' => 'signed', 'created_at' => now(),
        ]);
        Setting::set('email_subject_prefix', 'CHAR');
        $this->actingAs(User::find((int) DB::table('users')->where('type', 1)->where('id', '<>', $uid)->value('id')));
    }

    private function addon(string $type, array $a = []): int
    {
        return (int) DB::connection('contracts')->table('contract_addons')->insertGetId($a + [
            'contract_id' => $this->contractId, 'type' => $type, 'effective_date' => now()->toDateString(), 'status' => 'draft',
            'service_action' => $type === 'tariff_change' ? null : 'add',
        ]);
    }

    private function events(string $event)
    {
        return DB::connection('contracts')->table('contract_events')->where('contract_id', $this->contractId)->where('event', $event)->get();
    }

    private function tokenOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q['t'];
    }

    /** Payload tokenu (bez ověření). */
    private function payload(string $token): array
    {
        [$p] = explode('.', $token);
        return json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    }

    private function forge(array $payload): string
    {
        $b64  = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return $b64($json) . '.' . $b64(hash_hmac('sha256', $json, (string) config('services.contracts.token_secret'), true));
    }

    // ── vydání odkazů ────────────────────────────────────────────────────────

    public function test_odkaz_smlouvy(): void
    {
        $res = $this->svc->issueAccessLink($this->contractId);
        $this->assertSame([true, $this->email], [$res['email_sent'], $res['email']]);
        $this->assertStringStartsWith(route('sign.show') . '?t=', $res['url']);

        $p = $this->payload($this->tokenOf($res['url']));
        $this->assertSame(['cid', 'exp', 'rnd'], array_keys($p));
        $this->assertSame($this->contractId, $p['cid']);
        $this->assertEqualsWithDelta(time() + 7 * 86400, $p['exp'], 5);

        $ev = $this->events('link_issued')->first();
        $meta = json_decode($ev->meta_json, true);
        $this->assertSame(auth()->user()->login, $meta['by']);
        $this->assertSame(substr($this->tokenOf($res['url']), 0, 20) . '…', $meta['token']);

        $mail = DB::table('email_queues')->where('to', $this->email)->orderByDesc('id')->first();
        $tpl  = Message::where('type', Message::CONTRACT_SIGN_LINK)->first();
        $expectedSubject = 'CHAR :: ' . (($tpl && trim((string) $tpl->email_text) !== '') ? $tpl->name : 'Odkaz pro podpis smlouvy');
        $this->assertSame([$expectedSubject, 0], [$mail->subject, (int) $mail->state]);
        $this->assertStringContainsString(htmlspecialchars($res['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $mail->body);
        $this->assertSame(['to' => $this->email, 'addon' => false], array_intersect_key(json_decode($this->events('email_sent')->first()->meta_json, true), ['to' => 1, 'addon' => 1]));
    }

    public function test_odkaz_bez_emailu_a_zalozni_sablona(): void
    {
        Message::where('type', Message::CONTRACT_ADDON_SIGN_LINK)->update(['email_text' => '']);
        $res = $this->svc->sendAddonLink($this->contractId);
        $this->assertStringStartsWith(route('sign.addon.show') . '?t=', $res['url']);
        $this->assertSame(1, $this->events('addon_link_issued')->count());
        $mail = DB::table('email_queues')->where('to', $this->email)->orderByDesc('id')->first();
        $this->assertSame('CHAR :: Odkaz pro podpis dodatku smlouvy', $mail->subject);
        $this->assertStringContainsString('Odkaz je platný 7 dní.', $mail->body);
        $this->assertTrue((bool) json_decode($this->events('email_sent')->first()->meta_json, true)['addon']);

        DB::table('contacts')->where('value', $this->email)->delete();
        $res = $this->svc->issueAccessLink($this->contractId);
        $this->assertSame([false, null], [$res['email_sent'], $res['email']]);
        $this->assertNotNull($res['url'], 'odkaz vznikne i bez e-mailu');
    }

    public function test_odkazy_dodatku_tri_typy(): void
    {
        $cases = [
            ['tariff_change', 'issueTariffAddonLink', 'sign.tariff_addon.show', 'tariff_addon_link_issued'],
            ['additional_service', 'issueServiceAddonLink', 'sign.service_addon.show', 'service_addon_link_issued'],
            ['connection_point', 'issueConnectionPointAddonLink', 'sign.connection_point.show', 'connection_point_addon_link_issued'],
        ];
        foreach ($cases as [$type, $method, $route, $event]) {
            $aid = $this->addon($type);
            $res = $this->svc->{$method}($aid);
            $this->assertStringStartsWith(route($route) . '?t=', $res['url'], $type);
            $this->assertSame(['cid' => $this->contractId, 'aid' => $aid], array_intersect_key($this->payload($this->tokenOf($res['url'])), ['cid' => 1, 'aid' => 1]));
            $this->assertSame('otp_sent', DB::connection('contracts')->table('contract_addons')->where('id', $aid)->value('status'), 'draft → otp_sent');
            $this->assertSame($aid, json_decode($this->events($event)->last()->meta_json, true)['addon_id']);
            $this->assertTrue($res['email_sent']);

            // už odeslaný dodatek status nemění
            DB::connection('contracts')->table('contract_addons')->where('id', $aid)->update(['status' => 'otp_verified']);
            $this->svc->{$method}($aid);
            $this->assertSame('otp_verified', DB::connection('contracts')->table('contract_addons')->where('id', $aid)->value('status'));
        }
    }

    public function test_odkaz_dodatku_neexistujici_nebo_spatny(): void
    {
        $none = ['url' => null, 'email_sent' => false, 'email' => null];
        $this->assertSame($none, $this->svc->issueTariffAddonLink(999999999));
        $this->assertSame($none, $this->svc->issueServiceAddonLink($this->addon('connection_point')), 'jiný typ');
        $this->assertSame($none, $this->svc->issueServiceAddonLink($this->addon('additional_service', ['service_action' => 'remove'])), 'odebrání se nepodepisuje');
        $this->assertSame($none, $this->svc->issueConnectionPointAddonLink($this->addon('connection_point', ['service_action' => 'remove'])));
        $this->assertNotNull($this->svc->issueTariffAddonLink($this->addon('connection_point'))['url'], 'tarif typ nekontroluje');
    }

    // ── ověření tokenů ───────────────────────────────────────────────────────

    public function test_overeni_tokenu(): void
    {
        $aid = $this->addon('tariff_change');
        $contractToken = $this->tokenOf($this->svc->issueAccessLink($this->contractId)['url']);
        $addonToken    = $this->tokenOf($this->svc->issueTariffAddonLink($aid)['url']);

        $this->assertSame($this->contractId, $this->svc->verifyAccessToken($contractToken));
        $this->assertSame($this->contractId, $this->svc->verifyAccessToken($addonToken), 'token dodatku otevře i smlouvu');
        foreach (['verifyTariffAddonToken', 'verifyServiceAddonToken', 'verifyConnectionPointAddonToken'] as $m) {
            $this->assertSame($aid, $this->svc->{$m}($addonToken), "{$m}: typ se v tokenu nerozlišuje");
            $this->assertNull($this->svc->{$m}($contractToken), "{$m}: token smlouvy nemá aid");
        }

        [$p, $s] = explode('.', $contractToken);
        $tampered = rtrim(strtr(base64_encode(json_encode(['cid' => $this->contractId + 1] + $this->payload($contractToken))), '+/', '-_'), '=') . '.' . $s;
        foreach (['', 'abc', 'a.b', $tampered, $p . '.', '.' . $s] as $bad) {
            $this->assertNull($this->svc->verifyAccessToken($bad), "neplatný: {$bad}");
            $this->assertNull($this->svc->verifyTariffAddonToken($bad));
        }

        $this->assertNull($this->svc->verifyAccessToken($this->forge(['cid' => $this->contractId, 'exp' => time() - 1, 'rnd' => 'x'])), 'expirovaný');
        $this->assertNull($this->svc->verifyAccessToken($this->forge(['cid' => 0, 'exp' => time() + 60])), 'cid 0');
        $this->assertNull($this->svc->verifyTariffAddonToken($this->forge(['cid' => 1, 'aid' => 0, 'exp' => time() + 60])), 'aid 0');
        $this->assertSame(5, $this->svc->verifyTariffAddonToken($this->forge(['cid' => 1, 'aid' => '5', 'exp' => time() + 60])), 'aid jako řetězec');
    }
}
