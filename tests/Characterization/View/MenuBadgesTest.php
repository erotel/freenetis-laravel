<?php

namespace Tests\Characterization\View;

use App\Domains\Acl\AclService;
use App\Models\User;
use App\View\Components\FreenetisMenu;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace hlavního menu (FreenetisMenu): odznaky s počty (čekatelé,
 * zákazníci, k ukončení, neidentifikované platby, koncepty plateb, chyby,
 * fronty e-mailů/SMS, žádosti, anomálie), položky jen s právem, nečlen-admin
 * vidí jen vlastní profil.
 */
class MenuBadgesTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
    }

    /** @return array<string, ?int> label => počet */
    private function badges(): array
    {
        $this->actingAs(User::find($this->adminId));
        $menu = app(FreenetisMenu::class);
        $out = [];
        foreach ($menu->groups as $g) {
            foreach ($g['items'] as $i) {
                $out[$i['label']] = $i['count'];
            }
        }
        return $out;
    }

    public function test_odznaky_odpovidaji_datum(): void
    {
        $this->acl();
        $before = $this->badges();
        $member = fn (int $type, array $over = []) => DB::table('members')->insert($over + ['name' => 'Char Menu', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'), 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $member(18);
        $member(2, ['pending_termination' => 1]);
        $member(90);
        DB::table('log_queues')->insert(['type' => 0, 'state' => 0, 'created_at' => now(), 'description' => 'char']);
        DB::table('email_queues')->insert(['from' => 'a@example.invalid', 'to' => 'b@example.invalid', 'subject' => 'x', 'body' => 'x', 'state' => 0, 'access_time' => now()]);
        DB::table('sms_messages')->insert(['user_id' => $this->adminId, 'stamp' => now(), 'send_date' => now(), 'text' => 'x', 'sender' => '1', 'receiver' => '2', 'driver' => 3, 'type' => 1, 'state' => 1]);
        DB::table('connection_requests')->insert(['member_id' => 1, 'created_at' => now(), 'state' => 0]);
        DB::table('outgoing_payments')->insert(['bank_account_id' => (int) DB::table('bank_accounts')->value('id'), 'target_account' => '1/0100', 'amount' => 1, 'currency' => 'CZK',
            'status' => 'draft', 'reason' => 'unidentified_refund', 'message' => 'x', 'created_by' => $this->adminId, 'created_at' => now(), 'updated_at' => now()]);
        $income = (int) DB::table('accounts')->where('account_attribute_id', 684000)->value('id');
        $tr = (int) DB::table('transfers')->insertGetId(['origin_id' => $income, 'destination_id' => null, 'member_id' => null, 'amount' => 5, 'datetime' => now(), 'creation_datetime' => now(), 'text' => 'c']);
        DB::table('bank_transfers')->insert(['transfer_id' => $tr]);
        DB::table('line_id_anomalies')->insert(['circuit_id' => 'char menu ' . uniqid(), 'seen_mac' => 'AA:00:00:00:00:09', 'type' => 'x', 'severity' => 'critical']);

        $after = $this->badges();
        foreach (['Seznam členů' => 1, 'Seznam zákazníků' => 1, 'Čekatelé' => 1, 'Kandidáti na ukončení' => 1, 'Chyby a logy' => 1, 'E-mailová fronta' => 1,
                  'SMS zprávy' => 1, 'Žádosti o připojení' => 1, 'Odchozí platby' => 1, 'Neidentifikované' => 1, 'Anomálie line-id' => 1] as $label => $delta) {
            $this->assertSame($before[$label] + $delta, $after[$label], $label);
        }
        $this->assertArrayHasKey('DHCP servery', $after);
        $this->assertArrayHasKey('Smlouvy', $after);
        $this->assertNull($after['Faktury'], 'bez odznaku');
    }

    public function test_jen_polozky_s_pravem_a_neadmin(): void
    {
        $this->acl(['view_all/Sms_Controller/sms']);
        $this->assertArrayNotHasKey('SMS zprávy', $this->badges());

        $this->acl(['view_all/Members_Controller/members']);
        $this->assertSame(['Můj profil' => null, 'Moje oznámení' => null, 'Zabezpečení (MFA)' => null], $this->badges());
    }
}
