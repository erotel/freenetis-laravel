<?php

namespace Tests\Feature\Fees;

use App\Domains\Billing\Actions\ChargeMonthlyFeeNowAction;
use App\Domains\Billing\Actions\ChargeOverdueFeesAction;
use App\Domains\Billing\Actions\DeductFeesAction;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Billing\Support\TransferType;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Souběh srážek (noční srážka, dohnání po platbě, srážka při obnově):
 * kontrola „už strženo?" a zůstatek se čtou až pod zámkem účtu (FOR UPDATE),
 * noční srážka běží jen jednou najednou. Jinak dvojí stržení.
 */
class FeeChargeConcurrencyTest extends DatabaseTestCase
{
    private const DATE = '2099-06-26';

    private int $member;
    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 300, 'from' => '2000-01-01', 'to' => '9999-12-31',
            'type_id' => 35, 'name' => 'char fee', 'special_type_id' => null]);
        Setting::set('default_fee_member_type_2', $fee);
        Setting::set('deduct_day', 26);
        $this->member = (int) DB::table('members')->insertGetId(['name' => 'Char Race', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'payment_blocked' => 0]);
        $this->account = (int) DB::table('accounts')->insertGetId(['member_id' => $this->member, 'account_attribute_id' => 221100,
            'balance' => 1000, 'name' => 'char']);
    }

    private function fees(int $type = TransferType::MEMBER_FEE): int
    {
        return DB::table('transfers')->where('origin_id', $this->account)->where('type', $type)->count();
    }

    /** Výběr účtů proběhl před cizí srážkou (zastaralý) — pod zámkem se to musí poznat. */
    public function test_nocni_srazka_nestrhne_co_mezitim_strhl_jiny_proces(): void
    {
        $account = $this->account;
        $this->app->instance(LedgerRepository::class, new class($account) extends LedgerRepository {
            public function __construct(private int $acc) {}
            public function dueMemberFees(string $date, ?int $only = null): array
            {
                $rows = parent::dueMemberFees($date, $only);
                // souběžné dohnání po platbě strhlo a commitlo mezi výběrem a zámkem
                $this->postFee($this->acc, (int) $this->organizationAccountId(221101), TransferType::MEMBER_FEE, 300, $date, now()->toDateTimeString(), 'jiný proces');
                return $rows;
            }
        });

        app(DeductFeesAction::class)->handle(self::DATE, $this->member);

        $this->assertSame(1, $this->fees(), 'jen jedna srážka');
        $this->assertEqualsWithDelta(700, (float) DB::table('accounts')->where('id', $this->account)->value('balance'), 0.001);
    }

    public function test_nocni_srazka_bezi_jen_jednou_najednou(): void
    {
        $lock = Cache::lock('fees:deduct', 60);
        $this->assertTrue($lock->get());
        $deduct = app(DeductFeesAction::class);
        $this->assertSame(0, $deduct->handle(self::DATE, $this->member));
        $this->assertSame(['already running'], $deduct->failures());
        $this->assertSame(0, $this->fees());
        $lock->release();

        $this->assertSame(1, $deduct->handle(self::DATE, $this->member));
        $this->assertSame(0, $deduct->handle(self::DATE, $this->member), 'druhý běh nic');
        $this->assertSame(1, $this->fees());
    }

    public function test_vsechny_srazky_zamykaji_ucet_pred_kontrolou(): void
    {
        $locks = function (callable $fn): bool {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $fn();
            return collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from `accounts`')
                && str_ends_with(strtolower($q['query']), 'for update') && in_array($this->account, $q['bindings'], true));
        };

        $this->assertTrue($locks(fn () => app(DeductFeesAction::class)->handle(self::DATE, $this->member)), 'noční srážka');
        $this->assertTrue($locks(fn () => app(ChargeMonthlyFeeNowAction::class)->handle($this->member, 2, '2099-07-26', null)), 'srážka při obnově');

        DB::table('members')->where('id', $this->member)->update(['payment_blocked' => 1, 'payment_blocked_since' => '2099-08-01']);
        $this->assertTrue($locks(fn () => app(ChargeOverdueFeesAction::class)->handle($this->member, '2099-08-27')), 'dohnání po platbě');

        $this->assertSame(3, $this->fees(), 'každý měsíc jednou');
    }
}
