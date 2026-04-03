<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    private const ACL_SECTION = 'Users_Controller';
    private const ACL_VALUE   = 'users';

    public function __construct(private AclService $acl) {}

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, $value);
    }

    public function index(Request $request)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $allowedSorts = ['id', 'login', 'surname', 'name', 'type'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $search    = trim((string) $request->query('search', ''));
        $memberId  = $request->query('member_id');

        $query = User::with('member')->select('users.*');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                  ->orWhere('users.surname', 'like', "%{$search}%")
                  ->orWhere('users.login', 'like', "%{$search}%");
            });
        }

        if ($memberId) {
            $query->where('users.member_id', (int) $memberId);
        }

        $users = $query->orderBy('users.' . $sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('users.index', [
            'users'    => $users,
            'sort'     => $sort,
            'dir'      => $dir,
            'perPage'  => $perPage,
            'search'   => $search,
            'memberId' => $memberId,
            'canNew'   => $this->can('new_all'),
            'canEdit'  => $this->can('edit_all'),
            'canDelete'=> $this->can('delete_all'),
        ]);
    }

    public function showByMember(int $memberId)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        return redirect()->route('users.index', ['member_id' => $memberId]);
    }

    public function show(int $id)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $user = User::with('member')->find($id);
        if (!$user) {
            abort(404);
        }

        return view('users.show', [
            'user'              => $user,
            'canEdit'           => $this->can('edit_all'),
            'canChangePassword' => $this->can('edit_all', 'password'),
            'canViewAppPwd'     => $this->can('view_all', 'application_password'),
            'canChangeAppPwd'   => $this->can('edit_all', 'application_password'),
        ]);
    }

    public function create(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $memberId = $request->query('member_id') ? (int) $request->query('member_id') : null;
        $members  = Member::orderBy('name')->get();

        return view('users.create', [
            'members'        => $members,
            'preselectedMember' => $memberId,
            'canEditLogin'   => $this->can('new_all', 'login'),
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $rules = [
            'member_id'             => 'required|integer|exists:members,id',
            'password'              => 'required|string|min:6|confirmed',
            'name'                  => 'nullable|string|max:100',
            'middle_name'           => 'nullable|string|max:100',
            'surname'               => 'required|string|max:100',
            'pre_title'             => 'nullable|string|max:50',
            'post_title'            => 'nullable|string|max:50',
            'birthday'              => 'nullable|date',
            'type'                  => 'required|integer|in:1,2',
            'comment'               => 'nullable|string|max:250',
        ];

        if ($this->can('new_all', 'login')) {
            $rules['login'] = 'required|string|max:100|unique:users,login';
        } else {
            $rules['login'] = 'nullable|string|max:100|unique:users,login';
        }

        $data = $request->validate($rules);
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        session()->flash('success', 'Uživatel byl úspěšně přidán.');

        if ($request->input('member_id')) {
            return redirect()->route('members.show', $request->input('member_id'));
        }

        return redirect()->route('users.show', $user->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $user    = User::find($id);
        if (!$user) {
            abort(404);
        }

        $members = Member::orderBy('name')->get();

        return view('users.edit', [
            'user'             => $user,
            'members'          => $members,
            'canEditLogin'     => $this->can('edit_all', 'login'),
            'canEditComment'   => $this->can('edit_all', 'comment'),
            'canEditMember'    => $this->can('edit_all', 'member'),
        ]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $user = User::find($id);
        if (!$user) {
            abort(404);
        }

        $rules = [
            'name'        => 'nullable|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'surname'     => 'required|string|max:100',
            'pre_title'   => 'nullable|string|max:50',
            'post_title'  => 'nullable|string|max:50',
            'birthday'    => 'nullable|date',
            'type'        => 'required|integer|in:1,2',
        ];

        if ($this->can('edit_all', 'login')) {
            $rules['login'] = 'required|string|max:100|unique:users,login,' . $id;
        }

        if ($this->can('edit_all', 'comment')) {
            $rules['comment'] = 'nullable|string|max:250';
        }

        if ($this->can('edit_all', 'member')) {
            $rules['member_id'] = 'required|integer|exists:members,id';
        }

        if ($request->filled('password')) {
            $rules['password'] = 'string|min:6|confirmed';
        }

        $data = $request->validate($rules);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        session()->flash('success', 'Uživatel byl úspěšně upraven.');

        return redirect()->route('users.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $user = User::find($id);
        if (!$user) {
            abort(404);
        }

        if ($user->id === auth()->id()) {
            session()->flash('error', 'Nelze smazat vlastní účet.');
            return redirect()->back();
        }

        if ($user->devices()->exists()) {
            session()->flash('error', 'Uživatele nelze smazat, má přiřazená zařízení.');
            return redirect()->back();
        }

        $memberUserCount = User::where('member_id', $user->member_id)->count();
        if ($memberUserCount <= 1) {
            session()->flash('error', 'Uživatele nelze smazat, je jediným uživatelem člena.');
            return redirect()->back();
        }

        $user->delete();

        session()->flash('success', 'Uživatel byl úspěšně smazán.');

        return redirect()->route('users.index');
    }

    public function changePassword(int $id)
    {
        if (!$this->can('edit_all', 'password')) {
            abort(403);
        }

        $user = User::find($id);
        if (!$user) {
            abort(404);
        }

        return view('users.change_password', ['user' => $user]);
    }

    public function updatePassword(Request $request, int $id)
    {
        if (!$this->can('edit_all', 'password')) {
            abort(403);
        }

        $user = User::find($id);
        if (!$user) {
            abort(404);
        }

        $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user->password = Hash::make($request->input('password'));
        $user->save();

        session()->flash('success', 'Heslo bylo úspěšně změněno.');

        return redirect()->route('users.show', $id);
    }
}
