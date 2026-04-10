<?php

namespace App\Http\Controllers;

use App\Models\AllowedSubnet;
use App\Models\Member;
use App\Models\Subnet;
use Illuminate\Http\Request;

class AllowedSubnetController extends Controller
{
    private const ACL_SECTION = 'Allowed_subnets_Controller';
    private const ACL_VALUE   = 'allowed_subnet';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function showByMember(int $memberId)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $member = Member::findOrFail($memberId);

        $allowedSubnets = AllowedSubnet::where('member_id', $memberId)
            ->with('subnet')
            ->orderBy('enabled', 'desc')
            ->orderBy('id')
            ->get();

        $assignedSubnetIds = $allowedSubnets->pluck('subnet_id');

        $availableSubnets = Subnet::whereNotIn('id', $assignedSubnetIds)
            ->orderBy('name')
            ->get();

        return view('allowed_subnets.show_by_member', [
            'member'           => $member,
            'allowedSubnets'   => $allowedSubnets,
            'availableSubnets' => $availableSubnets,
            'canNew'           => $this->can('new_all'),
            'canEdit'          => $this->can('edit_all'),
            'canDelete'        => $this->can('delete_all'),
        ]);
    }

    public function store(Request $request, int $memberId)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $member = Member::findOrFail($memberId);

        $request->validate([
            'subnet_id' => [
                'required',
                'integer',
                'exists:subnets,id',
                function ($attr, $value, $fail) use ($memberId) {
                    if (AllowedSubnet::where('member_id', $memberId)->where('subnet_id', $value)->exists()) {
                        $fail('Tato podsíť je již v seznamu povolených.');
                    }
                },
            ],
        ]);

        $as = AllowedSubnet::create([
            'member_id' => $memberId,
            'subnet_id' => $request->subnet_id,
            'enabled'   => true,
        ]);

        // Rebalance: if over limit, disable oldest enabled subnets (not the one just added)
        $maxCount = (int) $member->allowed_subnets_count;
        if ($maxCount > 0) {
            $enabledCount = AllowedSubnet::where('member_id', $memberId)
                ->where('enabled', true)->count();

            while ($enabledCount > $maxCount) {
                $oldest = AllowedSubnet::where('member_id', $memberId)
                    ->where('enabled', true)
                    ->where('id', '!=', $as->id)
                    ->orderBy('id', 'asc')
                    ->first();
                if (!$oldest) break;
                $oldest->update(['enabled' => false]);
                $enabledCount--;
            }
        }

        return redirect()->route('allowed_subnets.by_member', $memberId)
            ->with('success', 'Podsíť byla přidána.');
    }

    public function updateCount(Request $request, int $memberId)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $member = Member::findOrFail($memberId);
        $request->validate(['allowed_subnets_count' => 'required|integer|min:0']);
        $member->update(['allowed_subnets_count' => $request->allowed_subnets_count]);

        return back()->with('success', 'Maximum povolených podsítí bylo uloženo.');
    }

    public function toggle(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $as = AllowedSubnet::with('member')->findOrFail($id);
        $member = $as->member;
        $maxCount = (int) $member->allowed_subnets_count;

        if ($as->enabled) {
            // Disable — always allowed
            $as->update(['enabled' => false]);
            return redirect()->route('allowed_subnets.by_member', $member->id)
                ->with('success', 'Podsíť byla vypnuta.');
        } else {
            // Enable — check limit, displace oldest if at max
            if ($maxCount > 0) {
                $enabledCount = AllowedSubnet::where('member_id', $member->id)
                    ->where('enabled', true)->count();

                if ($enabledCount >= $maxCount) {
                    $oldest = AllowedSubnet::where('member_id', $member->id)
                        ->where('enabled', true)
                        ->orderBy('id', 'asc')
                        ->first();
                    if ($oldest) {
                        $oldest->update(['enabled' => false]);
                    }
                }
            }
            $as->update(['enabled' => true]);
            return redirect()->route('allowed_subnets.by_member', $member->id)
                ->with('success', 'Podsíť byla zapnuta.');
        }
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $allowedSubnet = AllowedSubnet::findOrFail($id);
        $memberId = $allowedSubnet->member_id;
        $allowedSubnet->delete();

        return redirect()->route('allowed_subnets.by_member', $memberId)
            ->with('success', 'Podsíť byla odebrána.');
    }
}
