<?php

namespace Tests\Feature\Payments;

use App\Models\OutgoingPayment;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Regrese (audit 2026-08-14): refundStore validoval jen amount>=0.01 a cílový
 * účet z requestu → šlo poslat libovolnou částku na libovolný účet
 * (arbitrary payout). Oprava: částka je stropovaná přijatou platbou a cílový
 * účet se odvozuje ze serveru (origin účet), request se ignoruje.
 */
class RefundGuardTest extends DatabaseTestCase
{
    private int $userId;
    private int $btId;
    private string $senderAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // Vše ACL pustit (setup skupin je mimo rozsah testu).
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);

        $memberId = (int) DB::table('members')
            ->where('locked', 0)->where('type', 2)->where('leaving_date', '9999-12-31')
            ->value('id');

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id'            => $memberId,
            'login'               => 'refund_' . uniqid(),
            'password'            => Hash::make('x'),
            'type'                => 1,
            'application_password'=> 'xxxxxxxx',
            'settings'            => '',
            'name'                => 'Refund', 'surname' => 'Test', 'comment' => '',
        ]);

        // Org účet (odkud se platí) + účet odesílatele (kam se má vrátit).
        $orgAccount = (int) DB::table('bank_accounts')->insertGetId([
            'name' => 'Org', 'account_nr' => '2000', 'bank_nr' => '0100',
        ]);
        $senderId = (int) DB::table('bank_accounts')->insertGetId([
            'name' => 'Odesílatel', 'account_nr' => '123456', 'bank_nr' => '0800',
        ]);
        $this->senderAccount = '123456/0800';

        // Neidentifikovaný převod: přišlo 500 Kč, member_id NULL.
        $transferId = (int) DB::table('transfers')->insertGetId([
            'member_id' => null, 'amount' => 500, 'datetime' => now(),
            'creation_datetime' => now(), 'text' => 'test',
        ]);

        $this->btId = (int) DB::table('bank_transfers')->insertGetId([
            'origin_id' => $senderId, 'destination_id' => $orgAccount,
            'transfer_id' => $transferId,
        ]);

        // bank_account_id do refundu (org účet).
        $this->orgAccount = $orgAccount;
    }

    private int $orgAccount;

    private function refund(array $overrides = [])
    {
        return $this->actingAs(User::find($this->userId))->post(
            route('bank-transfers.refund.store', $this->btId),
            array_merge([
                'bank_account_id' => $this->orgAccount,
                'currency'        => 'CZK',
                'amount'          => '500.00',
            ], $overrides)
        );
    }

    public function test_over_refund_je_odmitnut(): void
    {
        $r = $this->refund(['amount' => '999999']);
        $r->assertSessionHas('error');
        $this->assertSame(0, OutgoingPayment::where('transfer_id', DB::table('bank_transfers')->where('id', $this->btId)->value('transfer_id'))->count());
    }

    public function test_cilovy_ucet_se_bere_ze_serveru_ne_z_requestu(): void
    {
        // Útočník podstrčí vlastní účet — musí být ignorován.
        $r = $this->refund(['target_account' => '6666666/9999', 'target_name' => 'Útočník', 'amount' => '500']);
        $r->assertRedirect(route('bank_transfers.unidentified'));

        $op = OutgoingPayment::where('bank_account_id', $this->orgAccount)->first();
        $this->assertNotNull($op);
        $this->assertSame($this->senderAccount, $op->target_account, 'cílový účet = odesílatel, ne request');
        $this->assertNotSame('Útočník', $op->target_name);
        $this->assertEqualsWithDelta(500, (float) $op->amount, 0.001);
    }

    public function test_castka_v_ramci_stropu_projde(): void
    {
        $r = $this->refund(['amount' => '300']);
        $r->assertRedirect(route('bank_transfers.unidentified'));
        $op = OutgoingPayment::where('bank_account_id', $this->orgAccount)->first();
        $this->assertNotNull($op);
        $this->assertEqualsWithDelta(300, (float) $op->amount, 0.001);
    }
}
