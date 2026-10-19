<?php

namespace Tests\Feature\Members;

use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Ukončení s výpovědní lhůtou (incident .54, zákazník 6235, 2026-10-15):
 *  - ve výpovědní lhůtě (leaving_date v budoucnu) zůstává člen svého typu
 *    (zákazník 2 / řádný 90) a platí běžné poplatky; typ bývalého (15/16)
 *    nastaví až cron members:redirect-former v den odchodu;
 *  - vratka se zkracuje o poplatky ve lhůtě → z kreditu se převede JEN vracená
 *    částka, zbytek strhne srážka jako členský poplatek;
 *  - cron dává řádnému členovi 15 a ostatním 16 (dřív všem 15).
 */
class EndMembershipNoticePeriodTest extends DatabaseTestCase
{
    private int $adminId;
    private int $apId;
    /** @var string[] PDF dobropisů před testem — vratka je zapisuje na disk (rollback DB je nesmaže) */
    private array $refundFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->apId    = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->adminId || !$this->apId) {
            $this->markTestSkipped('chybí admin / adresní bod');
        }
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $this->refundFiles = glob(storage_path('app/private/refunds/*/*.pdf')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach (array_diff(glob(storage_path('app/private/refunds/*/*.pdf')) ?: [], $this->refundFiles) as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @return array{member:int, account:int} */
    private function member(int $type, float $balance = 0, string $leaving = '9999-12-31'): array
    {
        $id = (int) DB::table('members')->insertGetId([
            'name' => 'Char End', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $this->apId, 'entrance_date' => '2020-01-01', 'leaving_date' => $leaving,
        ]);
        $acc = (int) DB::table('accounts')->insertGetId([
            'member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char',
        ]);
        do {   // VS = id by kolidoval s reálnými VS v kopii dat (auto-increment se rollbackem nevrací)
            $vs = (string) random_int(90000000, 99999999);
        } while (DB::table('variable_symbols')->where('variable_symbol', $vs)->orWhere('variable_symbol', $vs . '+U')->exists());
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => $vs]);
        return ['member' => $id, 'account' => $acc];
    }

    private function row(int $id): object
    {
        return DB::table('members')->where('id', $id)->first();
    }

    private function vs(int $account): string
    {
        return (string) DB::table('variable_symbols')->where('account_id', $account)->value('variable_symbol');
    }

    // ── F2: cron v den odchodu ───────────────────────────────────────────────

    public function test_cron_zakaznik_na_16_radny_na_15_a_vs_plus_u(): void
    {
        $yesterday = now()->subDay()->format('Y-m-d');
        $customer = $this->member(2, 0, $yesterday);
        $regular  = $this->member(90, 0, $yesterday);
        $pending  = $this->member(18, 0, $yesterday);

        $this->artisan('members:redirect-former', ['--force' => true])->assertExitCode(0);

        $this->assertSame(16, (int) $this->row($customer['member'])->type);
        $this->assertSame(15, (int) $this->row($regular['member'])->type);
        $this->assertSame(16, (int) $this->row($pending['member'])->type);
        $this->assertSame(1, (int) $this->row($customer['member'])->locked);
        $this->assertStringEndsWith('+U', $this->vs($customer['account']));
        $this->assertStringEndsWith('+U', $this->vs($regular['account']));
    }

    public function test_cron_budouci_odchod_nesahne(): void
    {
        $x = $this->member(2, 0, now()->addDays(10)->format('Y-m-d'));
        $this->artisan('members:redirect-former', ['--force' => true])->assertExitCode(0);
        $this->assertSame(2, (int) $this->row($x['member'])->type);
        $this->assertStringEndsNotWith('+U', $this->vs($x['account']));
    }

    // ── F1: ukončení ve výpovědní lhůtě ─────────────────────────────────────

    private function end(int $member, string $leaving, int $mode = 1, ?float $refund = null)
    {
        $data = ['leaving_date' => $leaving, 'end_mode' => $mode];
        if ($refund !== null) {
            $data += ['refund_account' => '123456789/0100', 'refund_amount' => $refund];
        }
        return $this->actingAs(User::find($this->adminId))
            ->from(route('members.show', $member))
            ->post(route('members.end-membership.store', $member), $data);
    }

    private function balance(int $account): float
    {
        return (float) DB::table('accounts')->where('id', $account)->value('balance');
    }

    public function test_vypoved_do_budoucna_typ_zustava_vs_beze_zmeny(): void
    {
        $x = $this->member(2, 500);
        $leaving = now()->addDays(20)->format('Y-m-d');
        $this->end($x['member'], $leaving)->assertRedirect();

        $m = $this->row($x['member']);
        $this->assertSame(2, (int) $m->type, 've lhůtě zůstává zákazníkem');
        $this->assertSame($leaving, $m->leaving_date);
        $this->assertSame(0, (int) $m->locked);
        $this->assertStringEndsNotWith('+U', $this->vs($x['account']));
        $this->assertEqualsWithDelta(500, $this->balance($x['account']), 0.001);
    }

    /** Přesně scénář 6235: vratka zkrácená o poplatek ve lhůtě, srážka ho pak strhne. */
    public function test_vratka_ve_lhute_odecte_jen_vracenou_castku_a_srazka_projde(): void
    {
        Setting::set('default_fee_member_type_2', (int) DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => 320, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char', 'special_type_id' => null,
        ]));
        $x = $this->member(2, 1640);
        $leaving = now()->addDays(20)->format('Y-m-d');
        $this->end($x['member'], $leaving, 3, 1320)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $this->row($x['member'])->type);
        $this->assertEqualsWithDelta(320, $this->balance($x['account']), 0.001, 'v kreditu zůstal poplatek do odchodu');
        $this->assertEqualsWithDelta(1320, (float) DB::table('transfers')->where('origin_id', $x['account'])->where('type', 0)->value('amount'), 0.001);

        // srážka ve lhůtě (den před odchodem) strhne poplatek, žádná blokace
        $deductDate = now()->addDays(19)->format('Y-m-d');
        $this->artisan('fees:deduct', ['--force' => true, '--date' => $deductDate, '--member' => $x['member']])->assertExitCode(0);
        $this->assertEqualsWithDelta(320, (float) DB::table('transfers')->where('origin_id', $x['account'])->where('type', 1)->value('amount'), 0.001);
        $this->assertEqualsWithDelta(0, $this->balance($x['account']), 0.001);
        $this->assertSame(0, (int) $this->row($x['member'])->payment_blocked);
    }

    public function test_vratka_ve_lhute_nad_kredit_odmitnuta(): void
    {
        $x = $this->member(2, 100);
        $this->end($x['member'], now()->addDays(20)->format('Y-m-d'), 3, 150)->assertSessionHasErrors('refund_amount');
        $this->assertSame(2, (int) $this->row($x['member'])->type);
        $this->assertSame('9999-12-31', $this->row($x['member'])->leaving_date);
    }

    public function test_druhe_ukonceni_ve_lhute_odmitnuto(): void
    {
        $x = $this->member(2, 500, now()->addDays(20)->format('Y-m-d'));
        $this->end($x['member'], now()->addDays(25)->format('Y-m-d'))->assertStatus(400);
        $this->actingAs(User::find($this->adminId))->get(route('members.end-membership', $x['member']))->assertStatus(400);
    }

    /** Odchod dnes: beze změny — bývalý typ hned, celý kredit pryč, +U. */
    public function test_odchod_dnes_jako_dosud(): void
    {
        $x = $this->member(2, 1640);
        $this->end($x['member'], now()->format('Y-m-d'), 3, 1640)->assertRedirect()->assertSessionHasNoErrors();

        $m = $this->row($x['member']);
        $this->assertSame(16, (int) $m->type);
        $this->assertSame(1, (int) $m->locked);
        $this->assertEqualsWithDelta(0, $this->balance($x['account']), 0.001);
        $this->assertStringEndsWith('+U', $this->vs($x['account']));
    }

    public function test_detail_ve_lhute_ukaze_vypoved_misto_tlacitka(): void
    {
        $leaving = now()->addDays(20);
        $x = $this->member(2, 0, $leaving->format('Y-m-d'));
        $this->actingAs(User::find($this->adminId))->get(route('members.show', $x['member']))
            ->assertOk()
            ->assertSee('Výpověď, odchod ' . $leaving->format('d.m.Y'))
            ->assertDontSee(route('members.end-membership', $x['member']), false);
    }

    // ── F3: formulář počítá poplatek přes FeePolicy ─────────────────────────

    /** Tarif + dodatečná služba (dřív jen individuální/základní → špatná vratka u tarifu). */
    public function test_formular_mesicni_poplatek_tarif_plus_sluzby(): void
    {
        $x = $this->member(2, 1000);
        $sc = (int) DB::table('speed_classes')->insertGetId(['name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 199]);
        DB::table('members')->where('id', $x['member'])->update(['speed_class_id' => $sc]);
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 50, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 39, 'name' => 'char svc', 'special_type_id' => null]);
        DB::table('members_fees')->insert(['fee_id' => $fee, 'member_id' => $x['member'], 'activation_date' => '2000-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'char']);

        $this->actingAs(User::find($this->adminId))->get(route('members.end-membership', $x['member']))
            ->assertOk()
            ->assertSee('const RF_MONTHLY_FEE   = 249;', false);
    }

    // ── F4: pending termination nebere už ukončené ──────────────────────────

    public function test_k_ukonceni_neoznaci_byvale_ani_clena_ve_lhute(): void
    {
        $since = now()->subMonths(2)->startOfMonth()->format('Y-m-d');
        $former = $this->member(16, 0, now()->subDays(5)->format('Y-m-d'));
        $notice = $this->member(2, 0, now()->addDays(10)->format('Y-m-d'));
        $active = $this->member(2, 0);
        DB::table('members')->whereIn('id', [$former['member'], $notice['member'], $active['member']])
            ->update(['payment_blocked' => 1, 'payment_blocked_since' => $since]);

        $this->artisan('members:mark-pending-termination', ['--force' => true]);

        $this->assertSame(0, (int) $this->row($former['member'])->pending_termination);
        $this->assertSame(0, (int) $this->row($notice['member'])->pending_termination);
        $this->assertSame(1, (int) $this->row($active['member'])->pending_termination);
    }

    // ── Trvale smazat (krok 1 = označit jako bývalého) ──────────────────────

    public function test_trvale_smazat_krok1_radny_15_zakaznik_16(): void
    {
        $regular  = $this->member(90);
        $customer = $this->member(2);

        foreach ([$regular['member'], $customer['member']] as $id) {
            $this->actingAs(User::find($this->adminId))->delete(route('members.destroy', $id))->assertRedirect();
        }

        $this->assertSame(15, (int) $this->row($regular['member'])->type);
        $this->assertSame(16, (int) $this->row($customer['member'])->type);
    }
}
