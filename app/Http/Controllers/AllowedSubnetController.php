<?php

namespace App\Http\Controllers;

use App\Domains\Network\Actions\AddAllowedSubnetAction;
use App\Domains\Network\Actions\SetAllowedSubnetBillingAction;
use App\Domains\Network\Actions\SetAllowedSubnetSpeedAction;
use App\Domains\Network\Actions\ToggleAllowedSubnetAction;
use App\Domains\Network\Exceptions\PlaceBillingRejected;
use App\Domains\Network\Queries\AllowedSubnetsCardQuery;
use App\Domains\Network\Repositories\AllowedSubnetRepository;
use App\Domains\Members\Repositories\MemberRepository;
use Illuminate\Http\Request;

/**
 * Povolené podsítě / přípojná místa člena. Člen má self-service: vidí své
 * místa a přepíná je on/off; limit, přidání, rychlost, účtování a smazání
 * jen admin. Logika v app/Domains/Network.
 */
class AllowedSubnetController extends Controller
{
    private const ACL_SECTION = 'Allowed_subnets_Controller';
    private const ACL_VALUE   = 'allowed_subnet';

    public function __construct(private AllowedSubnetRepository $places, private MemberRepository $members) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    private function isOwner(int $memberId): bool
    {
        return auth()->check() && (int) auth()->user()->member_id === $memberId;
    }

    private function toMember(int $memberId, string $flash, string $message)
    {
        return redirect()->route('allowed_subnets.by_member', $memberId)->with($flash, $message);
    }

    public function showByMember(int $memberId, AllowedSubnetsCardQuery $card)
    {
        $isAdmin = $this->can('view_all');
        if (!$isAdmin && !$this->isOwner($memberId)) {
            abort(403);
        }

        return view('allowed_subnets.show_by_member', $card->find($memberId, $isAdmin) + [
            'canToggle'           => $isAdmin || $this->isOwner($memberId),
            // detail podsítě je admin-only — zákazníkovi jen text, ne proklik
            'canViewSubnetDetail' => $this->aclCheck('view_all', 'Subnets_Controller', 'subnet'),
            'canEditCount'        => $this->can('edit_all'),
            'canNew'              => $this->can('new_all'),
            'canDelete'           => $this->can('delete_all'),
            'canEditSpeed'        => $this->can('edit_all'),
        ]);
    }

    /** Vlastní rychlost přípojného místa; prázdná = zdědit rychlost člena. */
    public function updateSpeed(int $id, Request $request, SetAllowedSubnetSpeedAction $setSpeed)
    {
        $as = $this->places->findOrFail($id);
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $validated = $request->validate(['speed_class_id' => 'nullable|integer|exists:speed_classes,id']);
        $speedId   = $validated['speed_class_id'] ?? null;
        $setSpeed->handle($as, $speedId !== null ? (int) $speedId : null);

        return $this->toMember((int) $as->member_id, 'success', $as->speed_class_id
            ? 'Rychlost přípojného místa byla nastavena.'
            : 'Rychlost přípojného místa se nově dědí od člena (účtování zrušeno).');
    }

    /** Účtování přípojného místa (cena podle jeho rychlosti). */
    public function updateBilling(int $id, Request $request, SetAllowedSubnetBillingAction $setBilling)
    {
        $as = $this->places->findOrFail($id);
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $request->validate(['charged' => 'nullable|boolean']);
        $charged = (bool) $request->input('charged', false);

        try {
            $setBilling->handle($as, $charged);
        } catch (PlaceBillingRejected $e) {
            return $e->viaContract
                ? redirect()->route('contracts.show', $as->member_id)->with('error', $e->getMessage())
                : $this->toMember((int) $as->member_id, 'error', $e->getMessage());
        }

        return $this->toMember((int) $as->member_id, 'success', $charged
            ? 'Přípojné místo se nově účtuje (cena podle rychlosti).'
            : 'Přípojné místo se nově neúčtuje.');
    }

    public function store(Request $request, int $memberId, AddAllowedSubnetAction $add)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }
        $this->members->findOrFail($memberId);

        $request->validate([
            'subnet_id' => ['required', 'integer', 'exists:subnets,id', function ($attr, $value, $fail) use ($memberId) {
                if ($this->places->exists($memberId, (int) $value)) {
                    $fail('Tato podsíť je již v seznamu povolených.');
                }
            }],
        ]);
        $add->handle($memberId, (int) $request->subnet_id);

        return $this->toMember($memberId, 'success', 'Podsíť byla přidána.');
    }

    /** Limit zapnutých míst člena; „use_global" vrátí na globální výchozí. */
    public function updateCount(Request $request, int $memberId)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }
        $this->members->findOrFail($memberId);
        $request->validate([
            'allowed_subnets_count' => 'nullable|integer|min:0',
            'use_global'            => 'nullable|in:1',
        ]);

        if ($request->boolean('use_global')) {
            $this->places->clearLimit($memberId);
            return back()->with('success', 'Limit vrácen na globální default.');
        }
        $this->places->setLimit($memberId, (int) $request->input('allowed_subnets_count', 0));

        return back()->with('success', 'Maximum povolených podsítí bylo uloženo.');
    }

    public function toggle(int $id, ToggleAllowedSubnetAction $toggle)
    {
        $as = $this->places->findOrFail($id);
        if (!$this->can('edit_all') && !$this->isOwner((int) $as->member_id)) {
            abort(403);
        }

        return $this->toMember((int) $as->member_id, 'success', $toggle->handle($as) ? 'Podsíť byla zapnuta.' : 'Podsíť byla vypnuta.');
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }
        $as = $this->places->findOrFail($id);
        $this->places->delete($as);

        return $this->toMember((int) $as->member_id, 'success', 'Podsíť byla odebrána.');
    }
}
