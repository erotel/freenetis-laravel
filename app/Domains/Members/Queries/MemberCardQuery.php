<?php

namespace App\Domains\Members\Queries;

use App\Domains\Billing\Fees\FeePolicy;
use App\Domains\Members\Repositories\MembershipInterruptRepository;
use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Models\AccountAttribute;
use App\Models\Fee;
use App\Models\Member;
use App\Models\MemberFee;
use App\Models\MemberLanAccess;
use App\Models\Setting;
use App\Models\User;
use App\Services\AllowedSubnetFeesResolver;
use App\Domains\Contracts\Repositories\ContractRepository;
use App\Services\LanIsolationService;
use App\Services\LoginLockService;
use App\Services\PaymentQrService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data karty člena (members.show) — read model, nic nemění. Oprávnění
 * (co se smí zobrazit) řeší volající; sem jen říká, zda načíst citlivé části.
 */
class MemberCardQuery
{
    /** Autor komentáře; smazaný uživatel (user_id NULL) zůstane v historii. */
    private const AUTHOR_SQL = "COALESCE(CONCAT(u.surname, ' ', u.name), 'smazaný uživatel')";

    public function __construct(
        private FeePolicy $fees,
        private MembershipInterruptRepository $interrupts,
    ) {
    }

    public function find(int $id): ?Member
    {
        return Member::with([
            'users',
            'accounts.variableSymbols',
            'accounts.accountAttribute',
            'addressPoint.town',
            'addressPoint.street',
            'ipAddresses.subnet',
            'speedClass',
        ])->find($id);
    }

    /**
     * @param bool $withAudit       historie změn (citlivé — jen admin)
     * @param bool $withLoginLocks  zamčená přihlášení uživatelů člena
     * @return array<string, mixed> proměnné pro view members.show
     */
    public function data(Member $member, bool $withAudit, bool $withLoginLocks): array
    {
        $id    = (int) $member->id;
        $today = now()->toDateString();

        $creditAccount = $member->accounts
            ->first(fn ($a) => $a->account_attribute_id === AccountAttribute::CREDIT_ATTRIBUTE_ID);
        $mainUser = $member->users()->where('type', User::MAIN_USER)->first();

        // Efektivní poplatek (shodný se srážkou) + zdroj; služby a placená místa navíc.
        [$monthlyFee, $monthlyFeeSource] = $this->fees->regularFeeWithSource($id, (int) $member->type, $today);
        $additionalServices = $this->fees->additionalServiceItems($id, $today);
        $subnetFees         = AllowedSubnetFeesResolver::items($id);

        return [
            'member'                  => $member,
            'variableSymbols'         => $member->accounts->flatMap(fn ($a) => $a->variableSymbols)->pluck('variable_symbol'),
            'creditAccount'           => $creditAccount,
            'mainUser'                => $mainUser,
            'contacts'                => $mainUser ? $mainUser->contacts()->with('enumType')->get() : collect(),
            'activeMemberFee'         => $this->activeMemberFee($member),
            'monthlyFee'              => $monthlyFee,
            'monthlyFeeSource'        => $monthlyFeeSource,
            'additionalServices'      => $additionalServices,
            'additionalServicesTotal' => array_sum(array_column($additionalServices, 'fee')),
            'subnetFees'              => $subnetFees,
            'subnetFeesTotal'         => array_sum(array_column($subnetFees, 'fee')),
            'hasActiveInterrupt'      => $this->interrupts->activeInterruptFeeId($id, $today) !== null,
            'auditHistory'            => $withAudit ? $this->auditHistory($member) : collect(),
            'lockedUsers'             => $withLoginLocks ? $this->lockedUsers($member) : [],
            'tvEnabled'               => (bool) Setting::get('sledovanitv_enabled', 0),
            'accountCommentsList'     => $creditAccount ? $this->accountComments($creditAccount->comments_thread_id) : collect(),
            'accountComments'         => $creditAccount && $creditAccount->comments_thread_id
                ? $this->accountCommentsText((int) $creditAccount->comments_thread_id)
                : '',
            'gponOnts'                => Setting::get('gpon_enabled', '0')
                ? $member->onts()->where('reg_status', 'registered')->get()
                : collect(),
            'interrupts'              => DB::table('membership_interrupts as mi')
                ->join('members_fees as mf', 'mf.id', '=', 'mi.members_fee_id')
                ->where('mi.member_id', $id)
                ->select('mi.*', 'mf.activation_date', 'mf.deactivation_date')
                ->orderBy('mf.activation_date')
                ->get(),
            'lanAccess'               => MemberLanAccess::where('member_id', $id)->orderBy('destination')->get(),
            'lanIsInfra'              => app(LanIsolationService::class)->isInfraMember($id),
            'lanNetwork'              => app(LanIsolationService::class)->networkCidr(),
            'memberRedirections'      => app(RedirectionRepository::class)->memberRedirections($id),
            'expirationDate'          => $creditAccount?->getExpirationDate(),
            'memberContract'          => app(ContractRepository::class)->forMember($id),
            'paymentInfo'             => app(PaymentQrService::class)->paymentInfoForMember($id),
            'paymentQrSvg'            => app(PaymentQrService::class)->svgForMember($id),
        ];
    }

    /** Aktivní individuální poplatek, jinak základní dle typu (nastavení) jako neuložený MemberFee. */
    private function activeMemberFee(Member $member): ?MemberFee
    {
        $active = MemberFee::where('member_id', $member->id)->active()->with('fee')->first();
        if ($active) {
            return $active;
        }
        $defaultFeeId = (int) Setting::get('default_fee_member_type_' . $member->type, 0);
        $defaultFee   = $defaultFeeId ? Fee::find($defaultFeeId) : null;
        if (!$defaultFee) {
            return null;
        }
        $fee = new MemberFee();
        $fee->setRelation('fee', $defaultFee);
        return $fee;
    }

    /**
     * Audit trail (NIS2/ZoKB): posledních 100 změn člena, jeho uživatelů,
     * placených míst a zařízení. Smazání zařízení se loguje member-keyed
     * (DeviceController::destroy) — smazaný řádek by se přes ID nedohledal.
     */
    private function auditHistory(Member $member): Collection
    {
        $id         = (int) $member->id;
        $userIds    = $member->users->pluck('id')->all();
        $allowedIds = DB::table('allowed_subnets')->where('member_id', $id)->pluck('id')->all();
        $deviceIds  = !empty($userIds) ? DB::table('devices')->whereIn('user_id', $userIds)->pluck('id')->all() : [];

        return DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where(function ($q) use ($id, $userIds, $allowedIds, $deviceIds) {
                $q->where(fn ($x) => $x->where('a.auditable_type', 'members')->where('a.auditable_id', $id));
                foreach (['users' => $userIds, 'allowed_subnets' => $allowedIds, 'devices' => $deviceIds] as $type => $ids) {
                    if (!empty($ids)) {
                        $q->orWhere(fn ($x) => $x->where('a.auditable_type', $type)->whereIn('a.auditable_id', $ids));
                    }
                }
            })
            ->orderByDesc('a.id')
            ->limit(100)
            ->selectRaw("a.*, TRIM(CONCAT(COALESCE(u.surname,''),' ',COALESCE(u.name,''))) as actor_name, u.login as actor_login")
            ->get();
    }

    /** Uživatelé člena zamčení po neúspěšných přihlášeních. */
    private function lockedUsers(Member $member): array
    {
        $lockService = app(LoginLockService::class);
        $locked = [];
        foreach ($member->users as $u) {
            $st = $lockService->status((int) $u->id);
            if ($st['locked_until']) {
                $locked[] = ['id' => $u->id, 'login' => $u->login, 'locked_until' => $st['locked_until']];
            }
        }
        return $locked;
    }

    /** Komentáře ke kreditnímu účtu jako jeden text („Příjmení Jméno (datum):\ntext", nejnovější první). */
    private function accountCommentsText(int $threadId): string
    {
        return DB::table('comments as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.comments_thread_id', $threadId)
            ->orderByDesc('c.datetime')
            ->selectRaw('CONCAT(' . self::AUTHOR_SQL . ', " (", DATE(c.datetime), "):\n", c.text) as line')
            ->pluck('line')
            ->implode("\n\n");
    }

    /** Komentáře ke kreditnímu účtu, nejnovější první. */
    private function accountComments(?int $threadId): Collection
    {
        return DB::table('comments as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.comments_thread_id', $threadId)
            ->orderByDesc('c.datetime')
            ->selectRaw('c.id, c.text, c.datetime, ' . self::AUTHOR_SQL . ' as user_name')
            ->get();
    }
}
