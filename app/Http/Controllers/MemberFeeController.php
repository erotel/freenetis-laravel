<?php

namespace App\Http\Controllers;

use App\Models\Fee;
use App\Models\Member;
use App\Models\MemberFee;
use App\Models\Setting;
use Illuminate\Http\Request;

class MemberFeeController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_VALUE   = 'fees';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function showByMember(int $memberId)
    {
        abort_unless($this->can('view_all'), 403);

        $member = Member::findOrFail($memberId);

        // Load member fees with their fee and fee's enumType, grouped by type_id
        $memberFees = MemberFee::where('member_id', $memberId)
            ->with('fee.enumType')
            ->orderBy('activation_date', 'desc')
            ->get()
            ->groupBy(fn($mf) => $mf->fee->type_id);

        // Get default fee from config for this member type
        $configKey    = 'default_fee_member_type_' . $member->type;
        $defaultFeeId = (int) Setting::get($configKey, 0);

        $defaultFees = collect();
        if ($defaultFeeId) {
            $defaultFee = Fee::find($defaultFeeId);
            if ($defaultFee) {
                $virtualMf = new MemberFee([
                    'fee_id'            => $defaultFee->id,
                    'member_id'         => 1,
                    'activation_date'   => $defaultFee->from,
                    'deactivation_date' => $defaultFee->to,
                    'priority'          => 0,
                    'comment'           => '',
                ]);
                $virtualMf->setRelation('fee', $defaultFee);
                $defaultFees = collect([$virtualMf])->groupBy(fn($mf) => $mf->fee->type_id);
            }
        }

        $typeLabels = Fee::typeLabels();

        return view('members_fees.show_by_member', [
            'member'      => $member,
            'memberFees'  => $memberFees,
            'defaultFees' => $defaultFees,
            'typeLabels'  => $typeLabels,
            'canAdd'      => $this->can('new_all'),
            'canEdit'     => $this->can('edit_all'),
            'canDelete'   => $this->can('delete_all'),
        ]);
    }

    public function create(int $memberId)
    {
        abort_unless($this->can('new_all'), 403);

        $member = Member::findOrFail($memberId);
        $fees   = Fee::assignable()->orderBy('type_id')->orderBy('name')->get();

        return view('members_fees.create', [
            'member'      => $member,
            'fees'        => $fees,
            'typeLabels'  => Fee::typeLabels(),
        ]);
    }

    public function store(Request $request, int $memberId)
    {
        abort_unless($this->can('new_all'), 403);

        Member::findOrFail($memberId);

        $data = $request->validate([
            'fee_id'            => 'required|integer|exists:fees,id',
            'activation_date'   => 'required|date',
            'deactivation_date' => 'nullable|date|after_or_equal:activation_date',
            'comment'           => 'nullable|string|max:1000',
        ]);

        MemberFee::create([
            'member_id'         => $memberId,
            'fee_id'            => $data['fee_id'],
            'activation_date'   => $data['activation_date'],
            'deactivation_date' => $data['deactivation_date'] ?? '9999-12-31',
            'comment'           => $data['comment'] ?? '',
            'priority'          => 1,
        ]);

        session()->flash('success', 'Tarif byl úspěšně přiřazen.');
        return redirect()->route('members_fees.by_member', $memberId);
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $memberFee = MemberFee::with(['member', 'fee'])->findOrFail($id);
        $fees      = Fee::assignable()->orderBy('type_id')->orderBy('name')->get();

        return view('members_fees.edit', [
            'memberFee'  => $memberFee,
            'fees'       => $fees,
            'typeLabels' => Fee::typeLabels(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $memberFee = MemberFee::findOrFail($id);

        $data = $request->validate([
            'fee_id'            => 'required|integer|exists:fees,id',
            'activation_date'   => 'required|date',
            'deactivation_date' => 'nullable|date|after_or_equal:activation_date',
            'comment'           => 'nullable|string|max:1000',
        ]);

        $memberFee->update([
            'fee_id'            => $data['fee_id'],
            'activation_date'   => $data['activation_date'],
            'deactivation_date' => $data['deactivation_date'] ?? '9999-12-31',
            'comment'           => $data['comment'] ?? '',
        ]);

        session()->flash('success', 'Tarif byl úspěšně upraven.');
        return redirect()->route('members_fees.by_member', $memberFee->member_id);
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $memberFee = MemberFee::findOrFail($id);
        $memberId  = $memberFee->member_id;
        $memberFee->delete();

        session()->flash('success', 'Tarif byl odebrán.');
        return redirect()->route('members_fees.by_member', $memberId);
    }
}
