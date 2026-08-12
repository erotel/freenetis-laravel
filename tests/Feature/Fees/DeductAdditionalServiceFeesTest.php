<?php

namespace Tests\Feature\Fees;

use App\Console\Commands\DeductFees;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\DatabaseTestCase;

/**
 * Měsíční srážka dodatečných služeb (`fees:deduct`, transfer type 6):
 * samostatná transakce oddělená od tarifu, prepaid blokace při nedostatku
 * kreditu, idempotence. (v2.16.0)
 *
 * Metoda deductAdditionalServiceFees() je private → voláme přes Closure::bind.
 * Má vlastní beginTransaction/commit; uvnitř testovací transakce běží jako
 * savepoint, takže rollback DatabaseTestCase vše zahodí.
 */
class DeductAdditionalServiceFeesTest extends DatabaseTestCase
{
    private const CREDIT    = 221100;
    private const OPERATING = 221101;
    private const TYPE      = 6;
    private const DATE      = '2099-06-01'; // daleká budoucnost → žádné kolizní transfery

    private DeductFees $cmd;
    private Closure $deduct;
    private int $orgOperating;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgOperating = (int) DB::table('accounts')
            ->where('member_id', 1)->where('account_attribute_id', self::OPERATING)->value('id');
        if (!$this->orgOperating) {
            $this->markTestSkipped('organizační operating účet (member 1) nenalezen');
        }

        $this->cmd = app(DeductFees::class);
        // Metoda na konci volá $this->info() → musíme nastavit výstup.
        $out = new OutputStyle(new ArrayInput([]), new NullOutput());
        Closure::bind(function () use ($out) { $this->output = $out; }, $this->cmd, Command::class)();

        $this->deduct = Closure::bind(
            fn (string $date, int $org) => $this->deductAdditionalServiceFees($date, $org),
            $this->cmd,
            DeductFees::class
        );
    }

    /** Reálný člen s kreditním účtem, aktivní, bez dodatečných služeb. Vrací [account_id, member_id]. */
    private function eligibleMember(): array
    {
        $row = DB::selectOne("
            SELECT a.id AS account_id, m.id AS member_id
            FROM accounts a JOIN members m ON a.member_id = m.id
            WHERE m.id <> 1
              AND a.account_attribute_id = ?
              AND m.entrance_date < ?
              AND (m.leaving_date IN ('0000-00-00','9999-12-31') OR m.leaving_date > ?)
              AND NOT EXISTS (
                  SELECT 1 FROM members_fees mf
                  JOIN fees f ON f.id = mf.fee_id
                  JOIN enum_types et ON et.id = f.type_id
                  WHERE LOWER(et.value) = 'additional service'
                    AND mf.member_id = m.id
                    AND mf.activation_date <= ? AND mf.deactivation_date >= ?
              )
              AND NOT EXISTS (
                  SELECT 1 FROM allowed_subnets asub
                  WHERE asub.member_id = m.id AND asub.charged = 1
              )
            LIMIT 1
        ", [self::CREDIT, self::DATE, self::DATE, self::DATE, self::DATE]);

        if (!$row) {
            $this->markTestSkipped('nenašel jsem vhodného člena s kreditním účtem');
        }
        return [(int) $row->account_id, (int) $row->member_id];
    }

    private function assignService(int $member, float $price): void
    {
        $feeId = DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => $price, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id'  => 39, 'name' => 'Test služba', 'special_type_id' => null,
        ]);
        DB::table('members_fees')->insert([
            'fee_id' => $feeId, 'member_id' => $member,
            'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31',
            'priority' => 1, 'comment' => 'test',
        ]);
    }

    private function transferCount(int $accountId): int
    {
        return DB::table('transfers')
            ->where('origin_id', $accountId)->where('type', self::TYPE)->where('datetime', self::DATE)
            ->count();
    }

    public function test_strhne_sluzbu_a_snizi_kredit(): void
    {
        [$account, $member] = $this->eligibleMember();
        DB::table('accounts')->where('id', $account)->update(['balance' => 100000]);
        $this->assignService($member, 17);

        ($this->deduct)(self::DATE, $this->orgOperating);

        $this->assertSame(1, $this->transferCount($account), 'má vzniknout 1 transfer typu 6');
        $t = DB::table('transfers')->where('origin_id', $account)->where('type', self::TYPE)->where('datetime', self::DATE)->first();
        $this->assertEqualsWithDelta(17.0, (float) $t->amount, 0.001);
        $this->assertSame('Automatická srážka – dodatečné služby', $t->text);
        $this->assertEqualsWithDelta(100000 - 17, (float) DB::table('accounts')->where('id', $account)->value('balance'), 0.001);
    }

    public function test_je_idempotentni(): void
    {
        [$account, $member] = $this->eligibleMember();
        DB::table('accounts')->where('id', $account)->update(['balance' => 100000]);
        $this->assignService($member, 17);

        ($this->deduct)(self::DATE, $this->orgOperating);
        ($this->deduct)(self::DATE, $this->orgOperating); // druhý běh nesmí strhnout znovu

        $this->assertSame(1, $this->transferCount($account), 'druhý běh nesmí přidat další transfer');
    }

    public function test_strhne_i_placene_pripojne_misto(): void
    {
        [$account, $member] = $this->eligibleMember();
        DB::table('accounts')->where('id', $account)->update(['balance' => 100000]);

        // Placené přípojné místo s rychlostí Mega (cena 400), bez dodatečných služeb.
        $subnetId = DB::table('subnets')->insertGetId(['name' => 'test-deduct-fee']);
        DB::table('allowed_subnets')->insert([
            'member_id' => $member, 'subnet_id' => $subnetId,
            'speed_class_id' => 18, 'charged' => 1, 'enabled' => 1, // 18 = Mega, 400
        ]);

        ($this->deduct)(self::DATE, $this->orgOperating);

        $t = DB::table('transfers')->where('origin_id', $account)->where('type', self::TYPE)->where('datetime', self::DATE)->first();
        $this->assertNotNull($t, 'má vzniknout transfer typu 6 i za samotné placené místo');
        $this->assertEqualsWithDelta(400.0, (float) $t->amount, 0.001, 'částka = cena Mega místa');
    }

    public function test_prepaid_blokace_pri_nedostatku_kreditu(): void
    {
        [$account, $member] = $this->eligibleMember();
        DB::table('accounts')->where('id', $account)->update(['balance' => 5]); // < 17
        DB::table('members')->where('id', $member)->update(['payment_blocked' => 0, 'payment_blocked_since' => null]);
        $this->assignService($member, 17);

        ($this->deduct)(self::DATE, $this->orgOperating);

        $this->assertSame(0, $this->transferCount($account), 'při nedostatku kreditu se nestrhává');
        $this->assertSame(1, (int) DB::table('members')->where('id', $member)->value('payment_blocked'), 'člen má být zablokován');
    }
}
