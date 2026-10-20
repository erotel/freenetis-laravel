<?php

namespace App\Http\Controllers\Members;

use App\Domains\Billing\Actions\ChargeMonthlyFeeNowAction;
use App\Domains\Billing\Fees\FeePolicy;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Billing\Repositories\RefundRepository;
use App\Domains\Billing\Support\TransferType;
use App\Domains\Members\Actions\ApproveMemberAction;
use App\Domains\Members\Actions\EndMembershipAction;
use App\Domains\Members\Actions\RestoreFormerMemberAction;
use App\Domains\Members\Actions\ResumeInterruptedMembershipAction;
use App\Domains\Members\Exceptions\MemberNotApprovable;
use App\Domains\Members\Exceptions\MemberNotRestorable;
use App\Domains\Members\Exceptions\MembershipNotEndable;
use App\Domains\Members\Queries\MemberListQuery;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\EndMode;
use App\Domains\Members\Support\FormerType;
use App\Helpers\MemberType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Životní cyklus členství: přihlášky ke schválení, k ukončení, ukončení, obnova bývalého / přerušeného člena. */
class MembershipController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_VALUE   = 'members';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function applicants(MemberListQuery $list)
    {
        abort_unless($this->can('view_all'), 403);

        return view('members.applicants', ['pending' => $list->applicants()]);
    }

    /**
     * Seznam členů označených k ukončení (cron MarkPendingTermination, 14. den
     * každého měsíce — VOP: 1 měsíc neplacení = automatický konec smlouvy).
     * Admin každý případ projde a klikne 'Ukončit' (link na endMembershipForm
     * s předvyplněným leaving_date) nebo 'Reset blokace' pokud chce dát milost.
     */
    public function pendingTermination(MemberListQuery $list)
    {
        abort_unless($this->can('view_all'), 403);

        return view('members.pending_termination', [
            'rows'    => $list->pendingTermination(),
            'today'   => now()->format('Y-m-d'),
            'canEdit' => $this->can('edit_all'),
        ]);
    }

    /** Má výpověď s datem odchodu v budoucnu (běží výpovědní lhůta)? */
    public function endMembershipForm(int $id, RefundRepository $refunds, LedgerRepository $ledger, FeePolicy $fees)
    {
        abort_unless($this->can('edit_all'), 403);
        $member = app(MemberRepository::class)->row($id);
        abort_if(!$member, 404);
        abort_if(in_array($member->type, [15, 16]), 400, 'Člen již byl ukončen.');
        abort_if(FormerType::inNoticePeriod($member->leaving_date), 400, "Člen už má výpověď s datem odchodu {$member->leaving_date}.");

        $account = $ledger->creditAccount($id);
        $today   = now()->toDateString();

        // Do data odchodu strhne srážka (fees:deduct) poplatek za každý deduct_day
        // před odchodem — JS z měsíčního poplatku přepočítá vratku podle data, ať
        // admin nevrátí peníze, které srážka stejně strhne. Měsíčně = pravidelný
        // poplatek (individuální → tarif → základní) + příplatky (služby, místa).
        $monthlyFee = (float) ($fees->regularFee($id, (int) $member->type, $today) ?? 0.0)
            + $fees->surcharges($id, $today);

        return view('members.end_membership', [
            'member'        => $member,
            'balance'       => $account?->balance ?? 0,
            'refundAccount' => $refunds->suggestedRefundAccount($id),
            'today'         => now()->format('Y-m-d'),
            'monthlyFee'    => $monthlyFee,
            'deductDay'     => (int) \App\Models\Setting::get('deduct_day', 26),
            // Dnešní srážka už proběhla → nepočítat ji mezi budoucí.
            'todayDeducted' => $account ? $ledger->hasTransferOnDay((int) $account->id, TransferType::MEMBER_FEE, $today) : false,
        ]);
    }

    public function endMembership(Request $request, int $id, EndMembershipAction $endMembership)
    {
        abort_unless($this->can('edit_all'), 403);

        $isRefundMode = (int) $request->input('end_mode') === EndMode::REFUND;

        $validated = $request->validate([
            'leaving_date'   => 'required|date',
            'end_mode'       => 'required|integer|in:' . implode(',', EndMode::ALL),
            'refund_account' => $isRefundMode ? 'required|string|max:50' : 'nullable',
            'refund_amount'  => $isRefundMode ? 'required|numeric|gt:0' : 'nullable',
        ]);

        try {
            $ended = $endMembership->handle(
                $id,
                (string) $validated['leaving_date'],
                (int) $validated['end_mode'],
                $validated['refund_account'] ?? null,
                isset($validated['refund_amount']) ? (float) $validated['refund_amount'] : null,
                auth()->id() ?? 1,
                auth()->user()?->login,
            );
        } catch (MembershipNotEndable $e) {
            abort_if($e->field === null, 400, $e->getMessage());
            return redirect()->back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        $name  = app(MemberRepository::class)->row($id)?->name;
        $label = ($ended->formerType == MemberType::FORMER_CUSTOMER) ? 'zákazník' : 'člen';
        $note  = "Členství bylo ukončeno. {$name} označen jako bývalý {$label}.";
        if ($ended->contractsTerminated > 0) {
            $note .= ' Smlouva byla označena jako ukončená.';
        }
        return redirect()->route('members.show', $id)->with('success', $note);
    }

    public function restore(int $id, RestoreFormerMemberAction $restore)
    {
        abort_unless($this->can('edit_all'), 403);

        $member = app(MemberRepository::class)->row($id);
        abort_if(!$member, 404);

        try {
            $newType = $restore->handle($member);
        } catch (MemberNotRestorable $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = ($newType === MemberType::REGULAR) ? 'člen' : 'zákazník';
        return redirect()->route('members.show', $id)
            ->with('success', "Člen byl obnoven jako {$label}.");
    }

    /**
     * Obnovit přerušené členství (aktivní poplatek „Přerušení členství") k datu:
     * dnes, nebo max 60 dní dopředu (jinak hrozí kolize s automatickou srážkou).
     */
    public function restoreInterrupt(Request $request, int $id, ResumeInterruptedMembershipAction $resume)
    {
        abort_unless($this->can('edit_all'), 403);

        $member = app(MemberRepository::class)->row($id);
        abort_if(!$member, 404);

        $today = now()->toDateString();
        $maxDate = now()->addDays(60)->toDateString();

        $validated = $request->validate([
            'effective_date' => ['nullable', 'date', 'after_or_equal:' . $today, 'before_or_equal:' . $maxDate],
        ], [
            'effective_date.after_or_equal'  => 'Datum obnovy nesmí být v minulosti.',
            'effective_date.before_or_equal' => 'Datum obnovy max 60 dní dopředu (jinak hrozí kolize s automatickým strhnutím).',
        ]);

        $effective = $validated['effective_date'] ?? $today;

        try {
            $chargeResult = $resume->handle($member, $effective, auth()->id());
        } catch (MemberNotRestorable $e) {
            return back()->with('error', $e->getMessage());
        }

        $when = $effective === now()->toDateString() ? 'dnes' : 'k ' . $effective;
        if ($chargeResult === ChargeMonthlyFeeNowAction::SKIPPED_BLOCKED) {
            return redirect()->route('members.show', $id)
                ->with('success', "Členství obnoveno {$when}. Poplatek NEstrhnut — nedostatek kreditu, nastaven payment_blocked. Doplatek proběhne po další příchozí platbě.");
        }
        if ($chargeResult === ChargeMonthlyFeeNowAction::NOOP) {
            return redirect()->route('members.show', $id)
                ->with('success', "Členství obnoveno {$when}. Poplatek nestrhnut (nulový tarif nebo už existuje), přesměrování smazáno.");
        }
        return redirect()->route('members.show', $id)
            ->with('success', "Členství obnoveno {$when}. Poplatek strhnut k {$effective}, přesměrování smazáno.");
    }

    public function approve(int $id, ApproveMemberAction $approve)
    {
        abort_unless($this->aclCheck('edit_all', 'Members_Controller', 'members'), 403);

        try {
            $approve->handle($id);
        } catch (MemberNotApprovable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('members.show', $id)
            ->with('success', 'Člen byl schválen.');
    }
}
