<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Actions\AssignMemberFeeAction;
use App\Domains\Billing\Exceptions\MemberFeeRejected;
use App\Domains\Billing\Queries\MemberFeesOverviewQuery;
use App\Domains\Billing\Repositories\MemberFeeRepository;
use App\Domains\Members\Repositories\MemberRepository;
use App\Models\Fee;
use Illuminate\Http\Request;

class MemberFeeController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_VALUE   = 'fees';

    private const RULES = [
        'fee_id'            => 'required|integer|exists:fees,id',
        'activation_date'   => 'required|date',
        'deactivation_date' => 'nullable|date|after_or_equal:activation_date',
        'comment'           => 'nullable|string|max:1000',
    ];

    public function __construct(private MemberFeeRepository $memberFees, private MemberRepository $members)
    {
    }

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function showByMember(int $memberId, MemberFeesOverviewQuery $overview)
    {
        abort_unless($this->aclCheck('view_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        $member = $this->members->findOrFail($memberId);

        return view('members_fees.show_by_member', $overview->handle($member) + [
            'member'      => $member,
            'typeLabels'  => Fee::typeLabels(),
            'canAdd'      => $this->can('new_all'),
            'canEdit'     => $this->can('edit_all'),
            'canDelete'   => $this->can('delete_all'),
        ]);
    }

    public function create(int $memberId)
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        return view('members_fees.create', [
            'member'      => $this->members->findOrFail($memberId),
            'fees'        => $this->memberFees->assignableFees(),
            'typeLabels'  => Fee::typeLabels(),
        ]);
    }

    public function store(Request $request, int $memberId, AssignMemberFeeAction $assign)
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        $this->members->findOrFail($memberId);
        $data = $request->validate(self::RULES);

        try {
            $assign->handle($memberId, $data);
        } catch (MemberFeeRejected $e) {
            return redirect()->route('contracts.show', $memberId)->with('error', $e->getMessage());
        }

        session()->flash('success', 'Tarif byl úspěšně přiřazen.');
        return redirect()->route('members_fees.by_member', $memberId);
    }

    public function edit(int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        return view('members_fees.edit', [
            'memberFee'  => $this->memberFees->findWithMemberAndFeeOrFail($id),
            'fees'       => $this->memberFees->assignableFees(),
            'typeLabels' => Fee::typeLabels(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        $memberFee = $this->memberFees->findOrFail($id);
        $data = $request->validate(self::RULES);

        $this->memberFees->update($memberFee, [
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
        abort_unless($this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        $memberFee = $this->memberFees->findOrFail($id);
        $this->memberFees->delete($memberFee);

        session()->flash('success', 'Tarif byl odebrán.');
        return redirect()->route('members_fees.by_member', $memberFee->member_id);
    }
}
