<?php

namespace Tests\Characterization\Members;

use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace: ukončení členství (MemberController::endMembership) před
 * přesunem do domény Members. Výpovědní lhůta (budoucí datum) je pokrytá
 * v Feature/Members/EndMembershipNoticePeriodTest + EndMembershipFutureSpeedTest;
 * tady odchod DNES a vedlejší efekty:
 *
 *   mód 1  bez e-mailu           mód 2  e-mail 94 (neplatil)
 *   mód 3  vratka + e-mail 96    mód 4  e-mail 34 (vlastní žádost)
 *
 * Vratka: outgoing_payments (draft) + číslo dobropisu (zákazník YY###, člen
 * YYČL####) + PDF + pohoda_refund_queue + převod celého kreditu (type 0) na
 * provozní účet 221101. Auto-odebrání zařízení (setting) jen při odchodu dnes,
 * IP adresy do komentáře. Podepsané smlouvy → terminated, nepodepsané smazat.
 */
class EndMembershipTest extends DatabaseTestCase
{
    private int $adminId;
    private int $apId;
    private int $subnetId;
    /** @var string[] PDF dobropisů před testem — vratka je zapisuje na disk (rollback DB je nesmaže) */
    private array $refundFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->apId    = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $orgOk = DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->exists();
        if (!$this->adminId || !$this->apId || !$orgOk) {
            $this->markTestSkipped('chybí admin / adresní bod / účty organizace');
        }
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true)->byDefault();
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $this->refundFiles = glob(storage_path('app/private/refunds/*/*.pdf')) ?: [];

        Setting::set('former_member_auto_device_remove', 0);
        Setting::set('email_subject_prefix', 'CHAR');
        Setting::set('bank_account_member_type_2', (int) DB::table('bank_accounts')->value('id'));
        Setting::set('bank_account_member_type_90', (int) DB::table('bank_accounts')->value('id'));
        $this->subnetId = (int) DB::table('subnets')->insertGetId(['name' => 'char end', 'network_address' => '10.250.9.0', 'netmask' => '255.255.255.0']);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(glob(storage_path('app/private/refunds/*/*.pdf')) ?: [], $this->refundFiles) as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @return array{member:int, account:int, user:int, email:string} */
    private function member(int $type, float $balance = 0, array $m = []): array
    {
        $id = (int) DB::table('members')->insertGetId($m + [
            'name' => 'Char End', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $this->apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
            'payment_blocked' => 1, 'payment_blocked_since' => '2026-01-01', 'pending_termination' => 1,
        ]);
        $acc = (int) DB::table('accounts')->insertGetId([
            'member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char',
        ]);
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => (string) $id]);
        $uid = (int) DB::table('users')->insertGetId([
            'member_id' => $id, 'login' => 'charend_' . uniqid(), 'password' => Hash::make('x'),
            'type' => 1, 'application_password' => 'xxxxxxxx', 'settings' => '',
            'name' => 'Char', 'surname' => 'End', 'comment' => '',
        ]);
        $email = 'charend_' . uniqid() . '@example.invalid';
        $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => $email]);
        DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $cid]);
        return ['member' => $id, 'account' => $acc, 'user' => $uid, 'email' => $email];
    }

    private function end(int $member, int $mode = 1, ?float $refund = null, ?string $leaving = null)
    {
        $data = ['leaving_date' => $leaving ?? now()->format('Y-m-d'), 'end_mode' => $mode];
        if ($refund !== null) {
            $data += ['refund_account' => '123456789/0100', 'refund_amount' => $refund];
        }
        return $this->actingAs(User::find($this->adminId))
            ->from(route('members.show', $member))
            ->post(route('members.end-membership.store', $member), $data);
    }

    private function row(int $id): object
    {
        return DB::table('members')->where('id', $id)->first();
    }

    private function emails(string $to)
    {
        return DB::table('email_queues')->where('to', $to)->get();
    }

    private function balance(int $account): float
    {
        return (float) DB::table('accounts')->where('id', $account)->value('balance');
    }

    private function nextDoc(bool $customer): string
    {
        $y = date('y');
        if ($customer) {
            $max = (int) DB::table('pohoda_refund_queue')->where('doc_number', 'like', $y . '%')
                ->where('doc_number', 'not like', $y . 'ČL%')->selectRaw('MAX(CAST(RIGHT(doc_number, 3) AS UNSIGNED)) m')->value('m');
            return $y . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
        }
        $max = (int) DB::table('pohoda_refund_queue')->where('doc_number', 'like', $y . 'ČL%')
            ->selectRaw('MAX(CAST(RIGHT(doc_number, 4) AS UNSIGNED)) m')->value('m');
        return $y . 'ČL' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    // ── základ: odchod dnes ──────────────────────────────────────────────────

    public function test_rad_clen_dnes_typ_15_zamek_vs_flagy_poplatky_audit(): void
    {
        $sc = (int) DB::table('speed_classes')->insertGetId(['name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 100]);
        $x = $this->member(90, 0, ['speed_class_id' => $sc]);
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 100, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char', 'special_type_id' => null]);
        DB::table('members_fees')->insert(['fee_id' => $fee, 'member_id' => $x['member'], 'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'char']);

        $this->end($x['member'])->assertRedirect(route('members.show', $x['member']))
            ->assertSessionHas('success', 'Členství bylo ukončeno. Char End označen jako bývalý člen.');

        $m = $this->row($x['member']);
        $today = now()->format('Y-m-d');
        $this->assertSame([15, 1, $today, 0, null, 0, null],
            [(int) $m->type, (int) $m->locked, $m->leaving_date, (int) $m->payment_blocked, $m->payment_blocked_since, (int) $m->pending_termination, $m->speed_class_id]);
        $this->assertSame($x['member'] . '+U', DB::table('variable_symbols')->where('account_id', $x['account'])->value('variable_symbol'));
        $this->assertSame($today, DB::table('members_fees')->where('member_id', $x['member'])->value('deactivation_date'));

        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $x['member'])->where('action', 'updated')->first();
        $this->assertNotNull($audit);
        $this->assertSame(['type' => 15, 'locked' => 1, 'leaving_date' => $today, 'end_mode' => 1], json_decode($audit->new_values, true));
        $this->assertCount(0, $this->emails($x['email']), 'mód 1 bez e-mailu');
    }

    public function test_zakaznik_dnes_typ_16(): void
    {
        $x = $this->member(2);
        $this->end($x['member'])->assertSessionHas('success', 'Členství bylo ukončeno. Char End označen jako bývalý zákazník.');
        $this->assertSame(16, (int) $this->row($x['member'])->type);
    }

    public function test_bez_prava_403(): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(false);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $x = $this->member(2);
        $this->end($x['member'])->assertForbidden();
        $this->assertSame(2, (int) $this->row($x['member'])->type);
    }

    public function test_uz_byvaly_400(): void
    {
        $x = $this->member(16);
        $this->end($x['member'])->assertStatus(400);
    }

    // ── e-maily dle módu ─────────────────────────────────────────────────────

    public function test_mod_2_a_4_email_dle_sablony(): void
    {
        foreach ([2 => 94, 4 => 34] as $mode => $messageId) {
            $x = $this->member(2);
            $this->end($x['member'], $mode)->assertRedirect();
            $mails = $this->emails($x['email']);
            $this->assertCount(1, $mails, "mód {$mode}");
            $name = DB::table('messages')->where('id', $messageId)->value('name');
            $this->assertSame('CHAR :: ' . $name, $mails[0]->subject);
            $this->assertSame(0, (int) $mails[0]->state);
            $this->assertSame(0, DB::table('email_queue_attachments')->where('email_queue_id', $mails[0]->id)->count());
        }
    }

    // ── mód 3: vratka ────────────────────────────────────────────────────────

    public function test_vratka_zakaznik_dnes(): void
    {
        $x = $this->member(2, 1640);
        $doc = $this->nextDoc(true);
        $org = DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->first();

        $this->end($x['member'], 3, 1500)->assertRedirect()->assertSessionHasNoErrors();

        $q = DB::table('pohoda_refund_queue')->where('member_id', $x['member'])->first();
        $this->assertSame([$doc, 2, '123456789/0100', 1500.0, 'CZK', 'termination_refund', 'Vratka při ukončení smlouvy', 'new'],
            [$q->doc_number, (int) $q->member_type, $q->refund_account, (float) $q->amount, $q->currency, $q->reason, $q->note, $q->status]);

        $op = DB::table('outgoing_payments')->where('id', $q->outgoing_payment_id)->first();
        $this->assertSame([(int) Setting::get('bank_account_member_type_2'), '123456789/0100', 1500.0, 'CZK', 'Vratka při ukončení členství', 'termination_refund', 'draft', $this->adminId],
            [(int) $op->bank_account_id, $op->target_account, (float) $op->amount, $op->currency, $op->message, $op->reason, $op->status, (int) $op->created_by]);
        $this->assertTrue(DB::table('audit_logs')->where('auditable_type', 'outgoing_payments')->where('auditable_id', $op->id)->where('action', 'created')->exists());

        // mimo lhůtu odejde z kreditu CELÝ zůstatek, ne jen vratka
        $t = DB::table('transfers')->where('origin_id', $x['account'])->where('type', 0)->first();
        $this->assertSame([(int) $org->id, 1640.0, 'Vymazání kreditu při ukončení členství s vratkou', $x['member']],
            [(int) $t->destination_id, (float) $t->amount, $t->text, (int) $t->member_id]);
        $this->assertEqualsWithDelta(0, $this->balance($x['account']), 0.001);
        $this->assertEqualsWithDelta((float) $org->balance + 1640, $this->balance((int) $org->id), 0.001);

        $mails = $this->emails($x['email']);
        $this->assertCount(1, $mails);
        $this->assertSame('CHAR :: ' . DB::table('messages')->where('id', 96)->value('name'), $mails[0]->subject);
        $att = DB::table('email_queue_attachments')->where('email_queue_id', $mails[0]->id)->first();
        $this->assertNotNull($att, 'PDF dobropisu v příloze');
        $this->assertSame(['dobropis_' . $doc . '.pdf', 'application/pdf'], [$att->name, $att->mime]);
        $this->assertFileExists($att->path);
    }

    public function test_vratka_rad_clen_cislo_cl(): void
    {
        $x = $this->member(90, 300);
        $doc = $this->nextDoc(false);
        $this->end($x['member'], 3, 300)->assertSessionHasNoErrors();
        $q = DB::table('pohoda_refund_queue')->where('member_id', $x['member'])->first();
        $this->assertSame([$doc, 90, 'Vratka při ukončení členství'], [$q->doc_number, (int) $q->member_type, $q->note]);
    }

    public function test_vratka_bez_bankovniho_uctu_nic_nezmeni(): void
    {
        Setting::set('bank_account_member_type_2', 0);
        $x = $this->member(2, 500);
        $this->end($x['member'], 3, 500)->assertSessionHasErrors('refund_account');
        $this->assertSame(2, (int) $this->row($x['member'])->type);
        $this->assertEqualsWithDelta(500, $this->balance($x['account']), 0.001);
        $this->assertFalse(DB::table('pohoda_refund_queue')->where('member_id', $x['member'])->exists());
    }

    public function test_vratka_vyzaduje_ucet_a_castku(): void
    {
        $x = $this->member(2, 500);
        $this->actingAs(User::find($this->adminId))->from(route('members.show', $x['member']))
            ->post(route('members.end-membership.store', $x['member']), ['leaving_date' => now()->format('Y-m-d'), 'end_mode' => 3])
            ->assertSessionHasErrors(['refund_account', 'refund_amount']);
    }

    // ── auto-odebrání zařízení ───────────────────────────────────────────────

    /** @return int[] device ids */
    private function devices(array $x, array $ips): array
    {
        $dev = (int) DB::table('devices')->insertGetId(['name' => 'char-end', 'type' => 1, 'user_id' => $x['user']]);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'device_id' => $dev, 'name' => 'char']);
        foreach ($ips as $ip) {
            DB::table('ip_addresses')->insert(['iface_id' => $iface, 'subnet_id' => $this->subnetId, 'ip_address' => $ip]);
        }
        DB::table('ip_addresses')->insert(['member_id' => $x['member'], 'subnet_id' => $this->subnetId, 'ip_address' => '10.250.9.1']);
        DB::table('subnets_owners')->insert(['subnet_id' => $this->subnetId, 'member_id' => $x['member']]);
        return [$dev, $iface];
    }

    public function test_auto_odebrani_zarizeni_dnes_ip_do_komentare(): void
    {
        Setting::set('former_member_auto_device_remove', 1);
        $x = $this->member(2, 0, ['comment' => 'puvodni']);
        [$dev, $iface] = $this->devices($x, ['10.250.9.20', '10.250.9.10']);

        $this->end($x['member'])->assertRedirect();

        $today = now()->format('Y-m-d');
        $this->assertSame("[{$today}] 10.250.9.1,10.250.9.10,10.250.9.20\npuvodni", $this->row($x['member'])->comment);
        $this->assertFalse(DB::table('devices')->where('id', $dev)->exists());
        $this->assertFalse(DB::table('ifaces')->where('id', $iface)->exists());
        $this->assertSame(0, DB::table('ip_addresses')->where('subnet_id', $this->subnetId)->count());
        $this->assertFalse(DB::table('subnets_owners')->where('member_id', $x['member'])->exists());
    }

    public function test_auto_odebrani_bez_ip_zapise_zadne(): void
    {
        Setting::set('former_member_auto_device_remove', 1);
        $x = $this->member(2);
        $this->end($x['member'])->assertRedirect();
        $this->assertSame('[' . now()->format('Y-m-d') . '] (žádné)', $this->row($x['member'])->comment);
    }

    public function test_auto_odebrani_budouci_datum_nesahne(): void
    {
        Setting::set('former_member_auto_device_remove', 1);
        $x = $this->member(2);
        [$dev] = $this->devices($x, ['10.250.9.10']);
        $this->end($x['member'], 1, null, now()->addDays(10)->format('Y-m-d'))->assertRedirect();
        $this->assertTrue(DB::table('devices')->where('id', $dev)->exists());
    }

    public function test_vypnute_auto_odebrani_nesahne(): void
    {
        $x = $this->member(2);
        [$dev] = $this->devices($x, ['10.250.9.10']);
        $this->end($x['member'])->assertRedirect();
        $this->assertTrue(DB::table('devices')->where('id', $dev)->exists());
        $this->assertNull($this->row($x['member'])->comment);
    }

    /** Cron v den odchodu: stejné odebrání, ale bez IP komentář NEzapisuje. */
    public function test_cron_den_odchodu_odebere_zarizeni(): void
    {
        Setting::set('former_member_auto_device_remove', 1);
        $yesterday = now()->subDay()->format('Y-m-d');
        $x = $this->member(16, 0, ['leaving_date' => $yesterday, 'locked' => 1, 'comment' => 'puvodni']);
        [$dev] = $this->devices($x, ['10.250.9.10']);
        $bare = $this->member(16, 0, ['leaving_date' => $yesterday, 'locked' => 1]);

        $this->artisan('members:redirect-former', ['--force' => true]);

        $this->assertSame('[' . now()->format('Y-m-d') . "] 10.250.9.1,10.250.9.10\npuvodni", $this->row($x['member'])->comment);
        $this->assertFalse(DB::table('devices')->where('id', $dev)->exists());
        $this->assertFalse(DB::table('subnets_owners')->where('member_id', $x['member'])->exists());
        $this->assertNull($this->row($bare['member'])->comment);
    }

    // ── smlouvy ──────────────────────────────────────────────────────────────

    public function test_smlouvy_podepsane_ukoncit_nepodepsane_smazat(): void
    {
        $x = $this->member(2);
        $c = DB::connection('contracts');
        $signed = (int) $c->table('contracts')->insertGetId(['member_id' => $x['member'], 'contract_no' => 'CHAR-S-' . uniqid(), 'status' => 'signed']);
        $draft  = (int) $c->table('contracts')->insertGetId(['member_id' => $x['member'], 'contract_no' => 'CHAR-D-' . uniqid(), 'status' => 'draft']);
        $cancel = (int) $c->table('contracts')->insertGetId(['member_id' => $x['member'], 'contract_no' => 'CHAR-C-' . uniqid(), 'status' => 'canceled']);

        $this->end($x['member'])->assertSessionHas('success',
            'Členství bylo ukončeno. Char End označen jako bývalý zákazník. Smlouva byla označena jako ukončená.');

        $this->assertSame('terminated', $c->table('contracts')->where('id', $signed)->value('status'));
        $this->assertFalse($c->table('contracts')->where('id', $draft)->exists());
        $this->assertFalse($c->table('contracts')->where('id', $cancel)->exists(), 'i zrušené (ne signed/terminated) se maže');
        $ev = $c->table('contract_events')->where('contract_id', $signed)->first();
        $this->assertSame('terminated', $ev->event);
        $meta = json_decode($ev->meta_json, true);
        $this->assertSame(['reason' => 'Ukončení zákazníka', 'date' => now()->format('Y-m-d')], ['reason' => $meta['reason'], 'date' => $meta['date']]);
        $this->assertSame(User::find($this->adminId)->login, $meta['by']);
    }
}
