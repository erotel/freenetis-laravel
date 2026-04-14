<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MembershipInterruptController extends Controller
{
    // Fee.special_type_id = 1 → "Membership interrupt" (fee id=5, fee=0)
    const FEE_SPECIAL_TYPE = 1;

    private function canView(): bool
    {
        return $this->aclCheck('view_all', 'Members_Controller', 'membership_interrupts');
    }

    private function canEdit(int $memberId = 0): bool
    {
        return $this->aclCheck('edit_all', 'Members_Controller', 'membership_interrupts');
    }

    private function canAdd(int $memberId = 0): bool
    {
        return $this->aclCheck('new_all', 'Members_Controller', 'membership_interrupts');
    }

    private function canDelete(): bool
    {
        return $this->aclCheck('delete_all', 'Members_Controller', 'membership_interrupts');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getMember(int $memberId): object
    {
        $member = DB::table('members')->where('id', $memberId)->first();
        abort_if(!$member, 404);
        return $member;
    }

    private function getInterrupt(int $id): object
    {
        $interrupt = DB::table('membership_interrupts as mi')
            ->join('members_fees as mf', 'mf.id', '=', 'mi.members_fee_id')
            ->where('mi.id', $id)
            ->select('mi.*', 'mf.activation_date', 'mf.deactivation_date')
            ->first();
        abort_if(!$interrupt, 404);
        return $interrupt;
    }

    /** Find the fee with special_type_id = 1 (Membership interrupt). */
    private function getInterruptFeeId(): int
    {
        $fee = DB::table('fees')->where('special_type_id', self::FEE_SPECIAL_TYPE)->first();
        abort_if(!$fee, 500, 'Membership interrupt fee not found (special_type_id=1).');
        return $fee->id;
    }

    /**
     * Validate and parse form dates. Returns ['from' => Carbon, 'to' => Carbon].
     * Throws validation back with errors on failure.
     */
    private function validateDates(Request $request, int $memberId, ?int $ignoreInterruptId = null): array
    {
        $request->validate([
            'from'    => 'required|date',
            'to'      => 'required|date',
            'comment' => 'required|string|max:250',
        ]);

        $from = Carbon::parse($request->input('from'))->startOfDay();
        $to   = Carbon::parse($request->input('to'))->startOfDay();

        $errors = [];

        if ($from->greaterThan($to)) {
            $errors['from'] = 'Datum od musí být menší než datum do.';
        }

        if (empty($errors)) {
            $diffMonths = $from->diffInMonths($to);

            $min = (int) Setting::get('membership_interrupt_minimum', 0);
            if ($min > 0 && $diffMonths < $min) {
                $errors['from'] = "Minimální délka přerušení je {$min} měsíců.";
            }

            $max = (int) Setting::get('membership_interrupt_maximum', 0);
            if ($max > 0 && $diffMonths > $max) {
                $errors['from'] = "Maximální délka přerušení je {$max} měsíců.";
            }
        }

        if (empty($errors)) {
            // Check overlap with existing interrupts for this member
            $feeTypeId = DB::table('fees')
                ->where('special_type_id', self::FEE_SPECIAL_TYPE)
                ->value('type_id');

            $overlap = DB::table('members_fees as mf')
                ->join('membership_interrupts as mi', 'mi.members_fee_id', '=', 'mf.id')
                ->join('fees as f', 'f.id', '=', 'mf.fee_id')
                ->where('mf.member_id', $memberId)
                ->where('f.special_type_id', self::FEE_SPECIAL_TYPE)
                ->where('mf.activation_date', '<=', $to->toDateString())
                ->where(function ($q) use ($from) {
                    $q->whereNull('mf.deactivation_date')
                      ->orWhere('mf.deactivation_date', '>=', $from->toDateString());
                })
                ->when($ignoreInterruptId, fn($q) => $q->where('mi.id', '!=', $ignoreInterruptId))
                ->exists();

            if ($overlap) {
                $errors['from'] = 'Interval přerušení se překrývá s jiným přerušením tohoto člena.';
            }
        }

        if ($errors) {
            back()->withInput()->withErrors($errors)->throwResponse();
        }

        return ['from' => $from, 'to' => $to];
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function create(int $memberId)
    {
        abort_unless($this->canAdd($memberId), 403);
        $member = $this->getMember($memberId);

        return view('membership_interrupts.form', [
            'action'   => 'create',
            'member'   => $member,
            'record'   => null,
        ]);
    }

    public function store(Request $request, int $memberId)
    {
        abort_unless($this->canAdd($memberId), 403);
        $member = $this->getMember($memberId);

        $dates   = $this->validateDates($request, $memberId);
        $comment = trim($request->input('comment', ''));
        $endAfter = $request->boolean('end_after_interrupt_end') ? 1 : 0;
        $feeId   = $this->getInterruptFeeId();

        DB::transaction(function () use ($member, $dates, $comment, $endAfter, $feeId) {
            $membersFeeId = DB::table('members_fees')->insertGetId([
                'fee_id'            => $feeId,
                'member_id'         => $member->id,
                'activation_date'   => $dates['from']->toDateString(),
                'deactivation_date' => $dates['to']->toDateString(),
                'priority'          => 0,
                'comment'           => '',
            ]);

            DB::table('membership_interrupts')->insert([
                'member_id'               => $member->id,
                'members_fee_id'          => $membersFeeId,
                'comment'                 => $comment,
                'end_after_interrupt_end' => $endAfter,
            ]);

            if ($endAfter) {
                DB::table('members')
                    ->where('id', $member->id)
                    ->update(['leaving_date' => $dates['to']->toDateString()]);
            }
        });

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Přerušení členství bylo přidáno.');
    }

    public function edit(int $id)
    {
        $interrupt = $this->getInterrupt($id);
        abort_unless($this->canEdit($interrupt->member_id), 403);
        $member = $this->getMember($interrupt->member_id);

        return view('membership_interrupts.form', [
            'action'   => 'edit',
            'member'   => $member,
            'record'   => $interrupt,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $interrupt = $this->getInterrupt($id);
        abort_unless($this->canEdit($interrupt->member_id), 403);

        $dates    = $this->validateDates($request, $interrupt->member_id, $id);
        $comment  = trim($request->input('comment', ''));
        $endAfter = $request->boolean('end_after_interrupt_end') ? 1 : 0;
        $today    = now()->toDateString();

        DB::transaction(function () use ($interrupt, $dates, $comment, $endAfter, $today) {
            DB::table('members_fees')
                ->where('id', $interrupt->members_fee_id)
                ->update([
                    'activation_date'   => $dates['from']->toDateString(),
                    'deactivation_date' => $dates['to']->toDateString(),
                ]);

            DB::table('membership_interrupts')
                ->where('id', $interrupt->id)
                ->update([
                    'comment'                 => $comment,
                    'end_after_interrupt_end' => $endAfter,
                ]);

            $member = DB::table('members')->where('id', $interrupt->member_id)->first();

            if ($endAfter) {
                // Set leaving_date if not already past
                if (!$member->leaving_date || $member->leaving_date === '0000-00-00'
                    || $member->leaving_date > $today) {
                    DB::table('members')
                        ->where('id', $interrupt->member_id)
                        ->update(['leaving_date' => $dates['to']->toDateString()]);
                }
            } else {
                // Clear future leaving_date that was set by this interrupt
                if ($member->leaving_date && $member->leaving_date > $today) {
                    DB::table('members')
                        ->where('id', $interrupt->member_id)
                        ->update(['leaving_date' => null]);
                }
            }
        });

        return redirect()->route('members.show', $interrupt->member_id)
            ->with('success', 'Přerušení členství bylo upraveno.');
    }

    public function destroy(int $id)
    {
        $interrupt = $this->getInterrupt($id);
        abort_unless($this->canDelete(), 403);

        $today = now()->toDateString();

        DB::transaction(function () use ($interrupt, $today) {
            $member = DB::table('members')->where('id', $interrupt->member_id)->first();

            // Clear future leaving_date set by this interrupt
            if ($interrupt->end_after_interrupt_end
                && $member->leaving_date
                && $member->leaving_date > $today) {
                DB::table('members')
                    ->where('id', $interrupt->member_id)
                    ->update(['leaving_date' => null]);
            }

            DB::table('membership_interrupts')->where('id', $interrupt->id)->delete();
            DB::table('members_fees')->where('id', $interrupt->members_fee_id)->delete();
        });

        return redirect()->route('members.show', $interrupt->member_id)
            ->with('success', 'Přerušení členství bylo smazáno.');
    }
}
