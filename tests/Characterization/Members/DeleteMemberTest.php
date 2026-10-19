<?php

namespace Tests\Characterization\Members;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace: Trvale smazat (MemberController::destroy) před přesunem do
 * domény Members. Tři větve:
 *
 *   čekatel (17/18)   smazat hned vše
 *   aktivní           krok 1 = označit jako bývalého k dnešku (90→15, jinak 16)
 *   bývalý (15/16)    krok 2 = smazat vše
 *
 * Mazání: kontakty, zařízení (ifaces, ip/ip6 adresy), uživatelé, účty
 * + převody (obě strany) + VS, poplatky, povolené podsítě, člen. Nepodepsané
 * smlouvy se smažou, podepsané/ukončené zůstávají.
 *
 * Podivnosti zachycené testem: IP přiřazené přímo členovi (ip_addresses.member_id)
 * se nemažou — FK SET NULL je nechá viset bez vlastníka; krok 1 neresetuje
 * prepaid flagy ani nepřidá +U k VS (na rozdíl od Ukončit).
 */
class DeleteMemberTest extends DatabaseTestCase
{
    private int $adminId;
    private int $apId;
    private int $subnetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->apId    = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->adminId || !$this->apId) {
            $this->markTestSkipped('chybí admin / adresní bod');
        }
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true)->byDefault();
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $this->subnetId = (int) DB::table('subnets')->insertGetId(['name' => 'char del', 'network_address' => '10.250.10.0', 'netmask' => '255.255.255.0']);
    }

    /** Člen se vším, co se maže. @return array<string,int> */
    private function member(int $type, array $m = []): array
    {
        $id = (int) DB::table('members')->insertGetId($m + [
            'name' => 'Char Del', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $this->apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
            'payment_blocked' => 1, 'pending_termination' => 1,
        ]);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => 50, 'name' => 'char']);
        do {   // VS = id by kolidoval s reálnými VS v kopii dat
            $vsValue = (string) random_int(90000000, 99999999);
        } while (DB::table('variable_symbols')->where('variable_symbol', $vsValue)->exists());
        $vs  = (int) DB::table('variable_symbols')->insertGetId(['account_id' => $acc, 'variable_symbol' => $vsValue]);
        $org = (int) DB::table('accounts')->where('member_id', 1)->value('id');
        $tIn  = (int) DB::table('transfers')->insertGetId(['origin_id' => $org, 'destination_id' => $acc, 'type' => 0, 'amount' => 50, 'datetime' => '2026-01-01', 'creation_datetime' => '2026-01-01', 'text' => 'char']);
        $tOut = (int) DB::table('transfers')->insertGetId(['origin_id' => $acc, 'destination_id' => $org, 'type' => 1, 'amount' => 10, 'datetime' => '2026-01-02', 'creation_datetime' => '2026-01-02', 'text' => 'char']);
        $uid = (int) DB::table('users')->insertGetId([
            'member_id' => $id, 'login' => 'chardel_' . uniqid(), 'password' => Hash::make('x'),
            'type' => 1, 'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'Char', 'surname' => 'Del', 'comment' => '',
        ]);
        $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => 'chardel@example.invalid']);
        DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $cid]);
        $dev   = (int) DB::table('devices')->insertGetId(['name' => 'char-del', 'type' => 1, 'user_id' => $uid]);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'device_id' => $dev, 'name' => 'char']);
        $ip    = (int) DB::table('ip_addresses')->insertGetId(['iface_id' => $iface, 'subnet_id' => $this->subnetId, 'ip_address' => '10.250.10.' . random_int(10, 250)]);
        $ip6   = (int) DB::table('ip6_addresses')->insertGetId(['iface_id' => $iface, 'ip_address' => '2a07:9c0::' . dechex(random_int(1, 65000))]);
        $ipDirect = (int) DB::table('ip_addresses')->insertGetId(['member_id' => $id, 'subnet_id' => $this->subnetId, 'ip_address' => '10.250.10.5']);
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 100, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char', 'special_type_id' => null]);
        $mf  = (int) DB::table('members_fees')->insertGetId(['fee_id' => $fee, 'member_id' => $id, 'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'char']);
        $as  = (int) DB::table('allowed_subnets')->insertGetId(['member_id' => $id, 'subnet_id' => $this->subnetId, 'enabled' => 1]);
        $so  = (int) DB::table('subnets_owners')->insertGetId(['member_id' => $id, 'subnet_id' => $this->subnetId]);

        $c = DB::connection('contracts');
        $signed = (int) $c->table('contracts')->insertGetId(['member_id' => $id, 'contract_no' => 'CHARDEL-S-' . uniqid(), 'status' => 'signed']);
        $draft  = (int) $c->table('contracts')->insertGetId(['member_id' => $id, 'contract_no' => 'CHARDEL-D-' . uniqid(), 'status' => 'draft']);

        return compact('id', 'acc', 'vs', 'vsValue', 'tIn', 'tOut', 'uid', 'cid', 'dev', 'iface', 'ip', 'ip6', 'ipDirect', 'mf', 'as', 'so', 'signed', 'draft');
    }

    private function destroy(int $id)
    {
        return $this->actingAs(User::find($this->adminId))->delete(route('members.destroy', $id));
    }

    private function exists(string $table, int $id, string $conn = 'mysql'): bool
    {
        return DB::connection($conn)->table($table)->where('id', $id)->exists();
    }

    private function assertAllGone(array $x): void
    {
        foreach (['members' => 'id', 'accounts' => 'acc', 'variable_symbols' => 'vs', 'transfers' => 'tIn', 'users' => 'uid',
                  'contacts' => 'cid', 'devices' => 'dev', 'ifaces' => 'iface', 'ip6_addresses' => 'ip6',
                  'members_fees' => 'mf', 'allowed_subnets' => 'as', 'subnets_owners' => 'so'] as $table => $key) {
            $this->assertFalse($this->exists($table, $x[$key]), "{$table} smazáno");
        }
        $this->assertFalse($this->exists('transfers', $x['tOut']));
        $this->assertFalse($this->exists('ip_addresses', $x['ip']));
        $this->assertFalse(DB::table('users_contacts')->where('user_id', $x['uid'])->exists());

        // podivnost: přímo přiřazená IP zůstává bez vlastníka
        $this->assertTrue($this->exists('ip_addresses', $x['ipDirect']));
        $this->assertNull(DB::table('ip_addresses')->where('id', $x['ipDirect'])->value('member_id'));

        $this->assertTrue($this->exists('contracts', $x['signed'], 'contracts'), 'podepsaná smlouva zůstává');
        $this->assertFalse($this->exists('contracts', $x['draft'], 'contracts'));

        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $x['id'])->where('action', 'deleted')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Char Del', json_decode($audit->old_values, true)['name']);
        $this->assertNull($audit->new_values);
    }

    public function test_bez_prava_403(): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(false);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $x = $this->member(16);
        $this->destroy($x['id'])->assertForbidden();
        $this->assertTrue($this->exists('members', $x['id']));
    }

    public function test_neexistujici_404(): void
    {
        $this->destroy(999999999)->assertNotFound();
    }

    public function test_cekatel_smazan_hned(): void
    {
        foreach ([17, 18] as $type) {
            $x = $this->member($type);
            $this->destroy($x['id'])->assertRedirect(route('members.index'))->assertSessionHas('success', 'Čekající člen byl smazán.');
            $this->assertAllGone($x);
        }
    }

    public function test_byvaly_krok2_smaze_vse(): void
    {
        foreach ([15, 16] as $type) {
            $x = $this->member($type);
            $this->destroy($x['id'])->assertRedirect(route('members.index'))
                ->assertSessionHas('success', 'Člen a všechna jeho data byla trvale smazána.');
            $this->assertAllGone($x);
        }
    }

    public function test_aktivni_krok1_oznaci_jako_byvaleho(): void
    {
        $sc = (int) DB::table('speed_classes')->insertGetId(['name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 100]);
        $x = $this->member(2, ['speed_class_id' => $sc]);

        $this->destroy($x['id'])->assertRedirect(route('members.show', $x['id']))->assertSessionHas('info',
            'Člen byl označen jako bývalý zákazník. Pro úplné smazání klikněte znovu na Trvale smazat. Smlouva byla označena jako ukončená.');

        $today = now()->format('Y-m-d');
        $m = DB::table('members')->where('id', $x['id'])->first();
        $this->assertSame([16, 1, $today, null], [(int) $m->type, (int) $m->locked, $m->leaving_date, $m->speed_class_id]);
        // podivnost: flagy ani VS se nemění, zařízení zůstávají
        $this->assertSame([1, 1], [(int) $m->payment_blocked, (int) $m->pending_termination]);
        $this->assertSame($x['vsValue'], DB::table('variable_symbols')->where('id', $x['vs'])->value('variable_symbol'));
        $this->assertTrue($this->exists('devices', $x['dev']));

        $this->assertSame($today, DB::table('members_fees')->where('id', $x['mf'])->value('deactivation_date'));
        $c = DB::connection('contracts');
        $this->assertSame('terminated', $c->table('contracts')->where('id', $x['signed'])->value('status'));
        $this->assertFalse($this->exists('contracts', $x['draft'], 'contracts'));

        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $x['id'])->where('action', 'updated')->first();
        $this->assertSame(['type' => 16, 'locked' => 1, 'leaving_date' => $today, 'speed_class_id' => null], json_decode($audit->new_values, true));
        $this->assertSame(['type' => 2, 'locked' => 0, 'leaving_date' => '9999-12-31', 'speed_class_id' => $sc], json_decode($audit->old_values, true));
    }

    public function test_aktivni_krok1_bez_smlouvy_radny(): void
    {
        $x = $this->member(90);
        DB::connection('contracts')->table('contracts')->where('member_id', $x['id'])->delete();
        $this->destroy($x['id'])->assertSessionHas('info', 'Člen byl označen jako bývalý člen. Pro úplné smazání klikněte znovu na Trvale smazat.');
        $this->assertSame(15, (int) DB::table('members')->where('id', $x['id'])->value('type'));
    }
}
