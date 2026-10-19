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

    /** Tarif jen z nabídky (nearchivovaný, ne systémový) — kromě ponechání stávajícího při úpravě. */
    private function rules(?int $currentFeeId = null): array
    {
        return [
            'fee_id'            => ['required', 'integer', 'exists:fees,id', function ($attr, $value, $fail) use ($currentFeeId) {
                if ((int) $value !== $currentFeeId && !$this->memberFees->isAssignable((int) $value)) {
                    $fail('Tento tarif nelze přiřadit (archivovaný nebo systémový).');
                }
            }],
            'activation_date'   => 'required|date',
            'deactivation_date' => 'nullable|date|after_or_equal:activation_date',
            'comment'           => 'nullable|string|max:1000',
        ];
    }

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
        $data = $request->validate($this->rules());

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

        $memberFee = $this->memberFees->findWithMemberAndFeeOrFail($id);

        return view('members_fees.edit', [
            'memberFee'  => $memberFee,
            'fees'       => $this->memberFees->feesForEdit($memberFee),
            'typeLabels' => Fee::typeLabels(),
        ]);
    }

    public function update(Request $request, int $id, AssignMemberFeeAction $assign)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        $memberFee = $this->memberFees->findOrFail($id);
        $data = $request->validate($this->rules((int) $memberFee->fee_id));

        try {
            $assign->update($memberFee, $data);
        } catch (MemberFeeRejected $e) {
            return redirect()->route('contracts.show', $memberFee->member_id)->with('error', $e->getMessage());
        }

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
