<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberWhitelist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberWhitelistController extends Controller
{
    const ACL_SECTION = 'Members_whitelists_Controller';
    const ACL_KEY     = 'whitelist';

    private function canView(): bool   { return $this->aclCheck('view_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canAdd(): bool    { return $this->aclCheck('new_all',    self::ACL_SECTION, self::ACL_KEY); }
    private function canEdit(): bool   { return $this->aclCheck('edit_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canDelete(): bool { return $this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY); }

    // ── Global index ──────────────────────────────────────────────────────────

    public function index()
    {
        abort_unless($this->canView(), 403);

        $whitelists = DB::table('members_whitelists as mw')
            ->join('members as m', 'm.id', '=', 'mw.member_id')
            ->select('mw.*', 'm.name as member_name')
            ->orderByDesc('mw.id')
            ->paginate(50);

        return view('member_whitelists.index', [
            'whitelists' => $whitelists,
            'canAdd'     => $this->canAdd(),
            'canEdit'    => $this->canEdit(),
            'canDelete'  => $this->canDelete(),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(int $memberId)
    {
        abort_unless($this->canAdd(), 403);

        $member = Member::findOrFail($memberId);

        return view('member_whitelists.form', [
            'action'   => 'create',
            'member'   => $member,
            'whitelist'=> null,
        ]);
    }

    public function store(Request $request, int $memberId)
    {
        abort_unless($this->canAdd(), 403);

        Member::findOrFail($memberId);

        $data = $request->validate([
            'permanent' => 'boolean',
            'since'     => 'required|date',
            'until'     => 'required|date|after_or_equal:since',
            'comment'   => 'nullable|string|max:1000',
        ]);

        MemberWhitelist::create([
            'member_id' => $memberId,
            'permanent' => $request->boolean('permanent'),
            'since'     => $data['since'],
            'until'     => $request->boolean('permanent') ? '9999-12-31' : $data['until'],
            'comment'   => $data['comment'] ?? null,
        ]);

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Whitelist záznam byl přidán.');
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function edit(int $id)
    {
        abort_unless($this->canEdit(), 403);

        $whitelist = MemberWhitelist::with('member')->findOrFail($id);

        return view('member_whitelists.form', [
            'action'    => 'edit',
            'member'    => $whitelist->member,
            'whitelist' => $whitelist,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canEdit(), 403);

        $whitelist = MemberWhitelist::findOrFail($id);

        $data = $request->validate([
            'permanent' => 'boolean',
            'since'     => 'required|date',
            'until'     => 'required|date|after_or_equal:since',
            'comment'   => 'nullable|string|max:1000',
        ]);

        $whitelist->update([
            'permanent' => $request->boolean('permanent'),
            'since'     => $data['since'],
            'until'     => $request->boolean('permanent') ? '9999-12-31' : $data['until'],
            'comment'   => $data['comment'] ?? null,
        ]);

        return redirect()->route('members.show', $whitelist->member_id)
            ->with('success', 'Whitelist záznam byl upraven.');
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(int $id)
    {
        abort_unless($this->canDelete(), 403);

        $whitelist = MemberWhitelist::findOrFail($id);
        $memberId  = $whitelist->member_id;
        $whitelist->delete();

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Whitelist záznam byl smazán.');
    }
}
