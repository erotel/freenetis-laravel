<?php

namespace Tests\Characterization\Members;

use App\Domains\Acl\AclService;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace Field Mode (FieldController, mobilní UI techniků) před
 * přesunem do domény Members: živé hledání (jméno/tokeny, ID, VS, adresa,
 * kontakt, IP přes zařízení, zařízení jako samostatné výsledky nahoře,
 * relevance, stav tečkou), karta člena (VS, saldo, poplatek a jeho zdroj,
 * kontakty, zařízení dle ACL), poznámka k účtu (líné vlákno + audit), detail
 * zařízení. Přihlášení: Feature/Auth/FieldLoginParityTest.
 */
class FieldModeTest extends DatabaseTestCase
{
    private int $adminId;
    private int $townId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->townId  = (int) DB::table('towns')->value('id');
        if (!$this->adminId || !$this->townId) {
            $this->markTestSkipped('chybí admin / obec');
        }
        $this->acl();
        Setting::set('networks_enabled', 1);
        $this->tag = 'Fq' . strtolower(substr(md5(uniqid('', true)), 0, 8));
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    /** @return array{id:int, account:int, user:int} */
    private function member(string $name, array $m = [], ?float $balance = 0, ?string $street = null, array $contacts = []): array
    {
        $ap = (int) DB::table('address_points')->insertGetId(['town_id' => $this->townId,
            'street_id' => $street ? (int) DB::table('streets')->insertGetId(['street' => $street]) : null, 'street_number' => '167', 'country_id' => 1]);
        $id = (int) DB::table('members')->insertGetId($m + [
            'name' => $name, 'type' => 2, 'registration' => 1, 'locked' => 0, 'address_point_id' => $ap,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
        ]);
        $acc = 0;
        if ($balance !== null) {
            $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char']);
            DB::table('variable_symbols')->insert([['account_id' => $acc, 'variable_symbol' => '88' . $id], ['account_id' => $acc, 'variable_symbol' => '99' . $id]]);
        }
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charfield_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'Char', 'surname' => 'Field', 'comment' => '']);
        foreach ($contacts as $type => $value) {
            $c = (int) DB::table('contacts')->insertGetId(['type' => $type, 'value' => $value]);
            DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $c]);
        }
        return ['id' => $id, 'account' => $acc, 'user' => $uid];
    }

    private function device(int $userId, string $name, array $ips = [], array $ip6s = []): int
    {
        $d = (int) DB::table('devices')->insertGetId(['name' => $name, 'type' => 1, 'user_id' => $userId]);
        $i = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => $d]);
        foreach ($ips as $ip) {
            DB::table('ip_addresses')->insert(['iface_id' => $i, 'ip_address' => $ip]);
        }
        foreach ($ip6s as $ip) {
            DB::table('ip6_addresses')->insert(['iface_id' => $i, 'ip_address' => $ip]);
        }
        return $d;
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    private function search(string $q): array
    {
        return $this->as()->getJson(route('field.search.results', ['q' => $q]))->assertOk()->json();
    }

    // ── hledání ──────────────────────────────────────────────────────────────

    public function test_hledani_clenove_dle_jmena_id_vs_adresy_kontaktu(): void
    {
        $a = $this->member("Alfa {$this->tag}", [], 150, "Ulice{$this->tag}", [Contact::TYPE_PHONE => '+420777' . substr($this->tag, 2, 6), Contact::TYPE_EMAIL => "{$this->tag}@example.test"]);
        $b = $this->member("Beta {$this->tag}", ['type' => 90, 'locked' => 1], -20);
        $c = $this->member("Gama {$this->tag}", ['payment_blocked' => 1], -5);
        $d = $this->member("Delta {$this->tag}", ['pending_termination' => 1], null);
        $e = $this->member("Epsilon {$this->tag}", [], -1);

        $rows = collect($this->search($this->tag))->keyBy('id');
        $this->assertEqualsCanonicalizing([$a['id'], $b['id'], $c['id'], $d['id'], $e['id']], $rows->keys()->all());
        $this->assertSame([
            'kind' => 'member', 'id' => $a['id'], 'name' => "Alfa {$this->tag}", 'vs' => '88' . $a['id'],
            'type_label' => \App\Helpers\MemberType::label(2), 'address' => DB::table('towns')->where('id', $this->townId)->value('town') !== null
                ? "Ulice{$this->tag} 167, " . DB::table('towns')->where('id', $this->townId)->value('town') : "Ulice{$this->tag} 167",
            'status' => 'ok', 'balance' => 150,
        ], $rows[$a['id']]);
        $this->assertSame(['suspended', 'blocked', 'terminating', 'overdue'],
            [$rows[$b['id']]['status'], $rows[$c['id']]['status'], $rows[$d['id']]['status'], $rows[$e['id']]['status']]);
        $this->assertNull($rows[$d['id']]['balance'], 'bez kreditního účtu');
        $this->assertNull($rows[$d['id']]['vs']);

        $ids = fn (string $q) => collect($this->search($q))->where('kind', 'member')->pluck('id')->all();
        $this->assertSame([$a['id']], $ids("{$this->tag} alfa"), 'tokeny v libovolném pořadí');
        $this->assertSame([$a['id']], $ids('99' . $a['id']), 'VS (i jiný než zobrazený)');
        $this->assertSame([$a['id']], $ids("Ulice{$this->tag} 167"), 'adresa composite');
        $this->assertSame([$a['id']], $ids('777' . substr($this->tag, 2, 6)), 'telefon');
        $this->assertSame([$a['id']], $ids("{$this->tag}@example"), 'e-mail');
        $this->assertContains($b['id'], $ids((string) $b['id']), 'ID');
        $this->assertSame((int) $b['id'], $this->search((string) $b['id'])[0]['id'] ?? null, 'přesné ID nahoru');

        $this->assertSame([], $this->search('a'), 'méně než 2 znaky');
        $this->assertSame([], $this->search("nic{$this->tag}nic"));
    }

    public function test_hledani_zarizeni_nahore_a_ip_pres_zarizeni(): void
    {
        $a = $this->member("Alfa {$this->tag}");
        $dev = $this->device($a['user'], "ap-{$this->tag}", ['10.252.77.9', '10.252.77.10'], ['2a07:9c0:fc:4d00::1']);

        $res = $this->search('10.252.77.10');
        $this->assertSame(['kind' => 'device', 'id' => $dev, 'name' => "ap-{$this->tag}", 'detail' => "10.252.77.10 · Alfa {$this->tag}"], $res[0]);
        $this->assertSame([$a['id']], collect($res)->where('kind', 'member')->pluck('id')->all(), 'vlastník přes zařízení');

        $res = $this->search("ap-{$this->tag}");
        $this->assertSame("10.252.77.9 · Alfa {$this->tag}", $res[0]['detail'], 'podle jména → první IP');
        $this->assertSame(['device', 'member'], collect($res)->pluck('kind')->all());

        $res = $this->search('2a07:9c0:fc:4d00');
        $this->assertSame(['kind' => 'device', 'id' => $dev, 'name' => "ap-{$this->tag}", 'detail' => "2a07:9c0:fc:4d00::1 · Alfa {$this->tag}"], $res[0], 'IPv6');
        $this->assertSame([$a['id']], collect($res)->where('kind', 'member')->pluck('id')->all(), 'vlastník přes IPv6 zařízení');

        Setting::set('networks_enabled', 0);
        $this->assertSame([], $this->search('10.252.77.10'), 'bez sítí ani IP, ani zařízení');
    }

    public function test_hledani_a_stranky_vyzaduji_pravo(): void
    {
        $this->acl(['view_all/Members_Controller/members']);
        $this->as()->getJson(route('field.search.results', ['q' => 'xx']))->assertForbidden();
        $this->as()->get(route('field.search'))->assertForbidden();
        $this->as()->get(route('field.member', $this->adminId))->assertForbidden();
    }

    // ── karta člena ──────────────────────────────────────────────────────────

    public function test_karta_clena(): void
    {
        $a = $this->member("Alfa {$this->tag}", [], 150, "Ulice{$this->tag}", [Contact::TYPE_PHONE => '+420777000111', Contact::TYPE_EMAIL => 'a@example.test']);
        $this->device($a['user'], "zz-{$this->tag}", ['10.252.78.1']);
        $this->device($a['user'], "aa-{$this->tag}");

        $res = $this->as()->get(route('field.member', $a['id']))->assertOk()->assertViewIs('field.member');
        $v = fn ($k) => $res->viewData($k);
        $this->assertSame($a['id'], (int) $v('member')->id);
        $this->assertSame('ok', $v('status'));
        $this->assertSame(['88' . $a['id'], '99' . $a['id']], $v('variableSymbols')->map(fn ($x) => (string) $x)->sort()->values()->all());
        $this->assertSame(150.0, $v('balance'));
        $this->assertSame(['+420777000111'], $v('phones')->all());
        $this->assertSame(['a@example.test'], $v('emails')->all());
        $this->assertStringStartsWith("Ulice{$this->tag} 167", $v('address'));
        $this->assertSame(["aa-{$this->tag}", "zz-{$this->tag}"], $v('devices')->pluck('name')->all(), 'řazeno podle jména');
        $this->assertTrue($v('canAddNote'));

        $policy = app(\App\Domains\Billing\Fees\FeePolicy::class);
        $ind = $policy->individualFee($a['id'], now()->toDateString());
        $tar = $policy->tarifPrice($a['id']);
        $def = $policy->defaultFeeByType(2);
        [$fee, $src] = $ind !== null ? [$ind, 'individuální'] : ($tar !== null ? [$tar, 'tarif'] : ($def !== null ? [$def, 'základní'] : [null, null]));
        $this->assertSame([$fee, $src], [$v('monthlyFee'), $v('monthlyFeeSource')]);

        $this->acl(['view_all/Devices_Controller/devices', 'new_all/Members_Controller/comment']);
        $res = $this->as()->get(route('field.member', $a['id']))->assertOk();
        $this->assertCount(0, $res->viewData('devices'), 'bez práva na zařízení');
        $this->assertFalse($res->viewData('canAddNote'));

        $this->as()->get(route('field.member', 999999999))->assertNotFound();
    }

    public function test_karta_individualni_poplatek(): void
    {
        $a = $this->member("Alfa {$this->tag}", ['locked' => 1]);
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 321, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char']);
        DB::table('members_fees')->insert(['fee_id' => $fee, 'member_id' => $a['id'], 'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 0, 'comment' => 'c']);

        $res = $this->as()->get(route('field.member', $a['id']))->assertOk();
        $this->assertSame('suspended', $res->viewData('status'));
        $this->assertSame($fee, (int) $res->viewData('activeMemberFee')->fee_id);
        $this->assertSame('individuální', $res->viewData('monthlyFeeSource'));
    }

    // ── poznámka ─────────────────────────────────────────────────────────────

    public function test_poznamka_line_vlakno_a_audit(): void
    {
        $a = $this->member("Alfa {$this->tag}");
        $note = fn (string $t) => $this->as()->post(route('field.member.note', $a['id']), ['text' => $t]);

        $note("  první {$this->tag}  ")->assertRedirect(route('field.member', $a['id']))->assertSessionHas('success', 'Poznámka byla přidána.');
        $thread = (int) DB::table('accounts')->where('id', $a['account'])->value('comments_thread_id');
        $this->assertSame('account', DB::table('comments_threads')->where('id', $thread)->value('type'));
        $note("druhá {$this->tag}");
        $this->assertSame($thread, (int) DB::table('accounts')->where('id', $a['account'])->value('comments_thread_id'), 'vlákno jen jednou');

        $rows = DB::table('comments')->where('comments_thread_id', $thread)->orderBy('id')->get();
        $this->assertSame(["první {$this->tag}", "druhá {$this->tag}"], $rows->pluck('text')->all());
        $this->assertSame([$this->adminId, $this->adminId], $rows->pluck('user_id')->map(fn ($x) => (int) $x)->all());

        $audit = DB::table('audit_logs')->where('auditable_type', 'comments')->where('auditable_id', $rows[0]->id)->first();
        $this->assertSame('created', $audit->action);
        $this->assertSame(['thread_id' => $thread, 'member_id' => $a['id'], 'text' => "první {$this->tag}", 'via' => 'field'], json_decode($audit->new_values, true));

        $note('')->assertSessionHasErrors('text');
        $this->as()->post(route('field.member.note', $this->member("Bez {$this->tag}", [], null)['id']), ['text' => 'x'])->assertNotFound();
        $this->as()->post(route('field.member.note', 999999999), ['text' => 'x'])->assertNotFound();
        $this->acl(['new_all/Members_Controller/comment']);
        $note('x')->assertForbidden();
    }

    // ── zařízení ─────────────────────────────────────────────────────────────

    public function test_detail_zarizeni(): void
    {
        $a = $this->member("Alfa {$this->tag}");
        $dev = $this->device($a['user'], "ap-{$this->tag}", ['10.252.79.1', '10.252.79.1'], ['2a07:9c0:fc:4f00::1']);

        $res = $this->as()->get(route('field.device', $dev))->assertOk()->assertViewIs('field.device');
        $this->assertSame(['10.252.79.1'], $res->viewData('ips')->all(), 'unikátní');
        $this->assertSame(['2a07:9c0:fc:4f00::1'], $res->viewData('ip6s')->all());
        $this->assertSame($a['id'], (int) $res->viewData('member')->id);

        $this->as()->get(route('field.device', 999999999))->assertNotFound();
        $this->acl(['view_all/Devices_Controller/devices']);
        $this->as()->get(route('field.device', $dev))->assertForbidden();
    }
}
