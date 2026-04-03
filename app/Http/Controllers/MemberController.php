<?php

namespace App\Http\Controllers;

use App\Helpers\MemberType;
use App\Models\Member;
use App\Services\AclService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_VALUE   = 'members';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $allowedSorts = ['id', 'name', 'type', 'entrance_date', 'registration'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $search = trim((string) $request->query('search', ''));

        // Paginated list with first variable symbol via subquery
        $query = Member::query()
            ->select([
                'members.id',
                'members.name',
                'members.type',
                'members.registration',
                'members.entrance_date',
                'members.leaving_date',
                'members.locked',
                DB::raw('(SELECT vs.variable_symbol FROM accounts a JOIN variable_symbols vs ON vs.account_id = a.id WHERE a.member_id = members.id ORDER BY vs.id LIMIT 1) AS variable_symbol'),
            ]);

        if ($search !== '') {
            $query->where('members.name', 'like', "%{$search}%");
        }

        $members = $query->orderBy('members.' . $sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('members.index', [
            'members'   => $members,
            'sort'      => $sort,
            'dir'       => $dir,
            'perPage'   => $perPage,
            'search'    => $search,
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function show(int $id)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $member = Member::with(['users', 'accounts.variableSymbols', 'addressPoint'])->find($id);
        if (!$member) {
            abort(404);
        }

        // Collect all variable symbols across all accounts
        $variableSymbols = $member->accounts
            ->flatMap(fn($a) => $a->variableSymbols)
            ->pluck('variable_symbol');

        $mainUser = $member->users()->where('type', \App\Models\User::MAIN_USER)->first();
        $contacts = $mainUser
            ? $mainUser->contacts()->with('enumType')->get()
            : collect();

        return view('members.show', [
            'member'          => $member,
            'variableSymbols' => $variableSymbols,
            'canEdit'         => $this->can('edit_all'),
            'canDelete'       => $this->can('delete_all'),
            'mainUser'        => $mainUser,
            'contacts'        => $contacts,
            'canViewUser'     => $this->acl->hasAccess(auth()->id(), 'view_all', 'Users_Controller', 'users'),
            'canEditUser'     => $this->acl->hasAccess(auth()->id(), 'edit_all', 'Users_Controller', 'users'),
            'canViewContacts' => $this->acl->hasAccess(auth()->id(), 'view_all', 'Users_Controller', 'additional_contacts'),
        ]);
    }

    public function create()
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $types = MemberType::labels();
        unset($types[MemberType::FORMER], $types[MemberType::APPLICANT]);

        return view('members.create', ['types' => $types]);
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'type'           => 'required|integer|in:' . implode(',', array_keys(MemberType::labels())),
            'entrance_date'  => 'nullable|date',
            'comment'        => 'nullable|string|max:250',
            'organization_identifier'     => 'nullable|string|max:20',
            'vat_organization_identifier' => 'nullable|string|max:30',
        ]);

        Member::create($data);

        session()->flash('success', 'Člen byl úspěšně přidán.');

        return redirect()->route('members.index');
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $member = Member::find($id);
        if (!$member) {
            abort(404);
        }

        $types = MemberType::labels();

        return view('members.edit', ['member' => $member, 'types' => $types]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $member = Member::find($id);
        if (!$member) {
            abort(404);
        }

        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'type'           => 'required|integer|in:' . implode(',', array_keys(MemberType::labels())),
            'entrance_date'  => 'nullable|date',
            'leaving_date'   => 'nullable|date',
            'comment'        => 'nullable|string|max:250',
            'organization_identifier'     => 'nullable|string|max:20',
            'vat_organization_identifier' => 'nullable|string|max:30',
        ]);

        $member->update($data);

        session()->flash('success', 'Člen byl úspěšně upraven.');

        return redirect()->route('members.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $member = Member::find($id);
        if (!$member) {
            abort(404);
        }

        if ($member->users()->exists()) {
            session()->flash('error', 'Člena nelze smazat, má přiřazené uživatele.');
            return redirect()->back();
        }

        $member->delete();

        session()->flash('success', 'Člen byl úspěšně smazán.');

        return redirect()->route('members.index');
    }
}
