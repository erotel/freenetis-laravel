<?php

namespace App\Http\Controllers;

use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Repositories\WhitelistRepository;
use Illuminate\Http\Request;

class MemberWhitelistController extends Controller
{
    const ACL_SECTION = 'Members_whitelists_Controller';
    const ACL_KEY     = 'whitelist';

    private const RULES = [
        'permanent' => 'boolean',
        'since'     => 'required|date',
        'until'     => 'required_unless:permanent,1|nullable|date|after_or_equal:since',   // trvalá: formulář pole „Do" skryje a vyprázdní
        'comment'   => 'nullable|string|max:1000',
    ];

    public function __construct(private WhitelistRepository $whitelists, private MemberRepository $members)
    {
    }

    private function canAdd(): bool    { return $this->aclCheck('new_all',    self::ACL_SECTION, self::ACL_KEY); }
    private function canEdit(): bool   { return $this->aclCheck('edit_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canDelete(): bool { return $this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY); }

    // ── Global index ──────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        abort_unless($this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $memberId = $request->input('member_id') ? (int) $request->input('member_id') : null;

        return view('member_whitelists.index', [
            'whitelists' => $this->whitelists->paginate($memberId, 50),
            'memberName' => $memberId ? $this->members->find($memberId)?->name : null,
            'memberId'   => $memberId,
            'canAdd'     => $this->canAdd(),
            'canEdit'    => $this->canEdit(),
            'canDelete'  => $this->canDelete(),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(int $memberId)
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY), 403);

        return view('member_whitelists.form', [
            'action'   => 'create',
            'member'   => $this->members->findOrFail($memberId),
            'whitelist'=> null,
        ]);
    }

    public function store(Request $request, int $memberId)
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $this->members->findOrFail($memberId);
        $data = $request->validate(self::RULES);
        $this->whitelists->create($memberId, ['permanent' => $request->boolean('permanent')] + $data);

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Whitelist záznam byl přidán.');
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function edit(int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $whitelist = $this->whitelists->findWithMemberOrFail($id);

        return view('member_whitelists.form', [
            'action'    => 'edit',
            'member'    => $whitelist->member,
            'whitelist' => $whitelist,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $whitelist = $this->whitelists->findOrFail($id);
        $data = $request->validate(self::RULES);
        $this->whitelists->update($whitelist, ['permanent' => $request->boolean('permanent')] + $data);

        return redirect()->route('members.show', $whitelist->member_id)
            ->with('success', 'Whitelist záznam byl upraven.');
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(int $id)
    {
        abort_unless($this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $whitelist = $this->whitelists->findOrFail($id);
        $this->whitelists->delete($whitelist);

        return redirect()->route('members.show', $whitelist->member_id)
            ->with('success', 'Whitelist záznam byl smazán.');
    }
}
