<?php

namespace App\Http\Controllers;

use App\Models\AllowedSubnet;
use App\Models\AllowedSubnetsCount;
use App\Models\Member;
use App\Models\Setting;
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

    /**
     * Člen má self-service přístup k vlastnímu záznamu allowed_subnets:
     *  - prohlížet seznam (showByMember)
     *  - přepínat existující podsítě on/off (toggle)
     * Změna limitu počtu, přidání nové podsítě a smazání zůstává adminům.
     */
    private function isOwner(int $memberId): bool
    {
        return auth()->check() && (int) auth()->user()->member_id === $memberId;
    }

    /**
     * Limit pro daného člena: per-member z `allowed_subnets_counts`, jinak fallback
     * na globální `allowed_subnets_default_count` (Kohana výchozí 1). Vrací 0 =
     * neomezeno, jak očekává volající kód (`if ($maxCount > 0)`).
     */
    private function maxCount(int $memberId): int
    {
        $row = AllowedSubnetsCount::firstWhere('member_id', $memberId);
        if ($row) {
            return (int) $row->count;
        }
        return (int) Setting::get('allowed_subnets_default_count', 1);
    }

    public function showByMember(int $memberId)
    {
        $isAdmin = $this->can('view_all');
        if (!$isAdmin && !$this->isOwner($memberId)) {
            abort(403);
        }

        $member = Member::findOrFail($memberId);

        // Stabilní pořadí podle id — ať se řádky při přepnutí on/off nepřehazují
        // (dřív se podle `enabled` vypnutá podsíť přesouvala dolů, což mátlo).
        $allowedSubnets = AllowedSubnet::where('member_id', $memberId)
            ->with('subnet')
            ->orderBy('id')
            ->get();

        // Členové bez admin oprávnění nepotřebují seznam dostupných podsítí
        // (nemůžou je stejně přidávat), tak ho ani nenačítáme.
        $availableSubnets = $isAdmin
            ? Subnet::whereNotIn('id', $allowedSubnets->pluck('subnet_id'))
                ->orderBy('name')
                ->get()
            : collect();

        $hasOwnLimit  = AllowedSubnetsCount::where('member_id', $memberId)->exists();
        $maxCount     = $this->maxCount($memberId);
        $globalCount  = (int) Setting::get('allowed_subnets_default_count', 1);

        return view('allowed_subnets.show_by_member', [
            'member'           => $member,
            'allowedSubnets'   => $allowedSubnets,
            'availableSubnets' => $availableSubnets,
            'maxCount'         => $maxCount,
            'hasOwnLimit'      => $hasOwnLimit,
            'globalCount'      => $globalCount,
            // Toggle smí člen sám (self-service), zbytek jen admin
            'canToggle'        => $isAdmin || $this->isOwner($memberId),
            // Detail podsítě (subnets.show) je admin-only — zákazníkovi ho
            // ukážeme jako text, ne jako proklik (jinak by dostal 403).
            'canViewSubnetDetail' => $this->aclCheck('view_all', 'Subnets_Controller', 'subnet'),
            'canEditCount'     => $this->can('edit_all'),
            'canNew'           => $this->can('new_all'),
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
        $maxCount = $this->maxCount($memberId);
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

        Member::findOrFail($memberId);
        $request->validate([
            'allowed_subnets_count' => 'nullable|integer|min:0',
            'use_global'            => 'nullable|in:1',
        ]);

        // 'use_global' checkbox smaže per-member řádek → vrátí na globální default.
        if ($request->boolean('use_global')) {
            AllowedSubnetsCount::where('member_id', $memberId)->delete();
            return back()->with('success', 'Limit vrácen na globální default.');
        }

        AllowedSubnetsCount::updateOrCreate(
            ['member_id' => $memberId],
            ['count'     => (int) $request->input('allowed_subnets_count', 0)]
        );

        return back()->with('success', 'Maximum povolených podsítí bylo uloženo.');
    }

    public function toggle(int $id)
    {
        $as = AllowedSubnet::with('member')->findOrFail($id);
        $member = $as->member;

        if (!$this->can('edit_all') && !$this->isOwner((int) $as->member_id)) {
            abort(403);
        }
        $maxCount = $this->maxCount($member->id);

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
