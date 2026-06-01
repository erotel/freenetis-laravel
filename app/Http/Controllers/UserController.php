<?php

namespace App\Http\Controllers;

use App\Models\DeviceAdmin;
use App\Models\DeviceEngineer;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    private const ACL_SECTION = 'Users_Controller';
    private const ACL_VALUE   = 'users';

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    public function index(Request $request)
    {
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
            // Stejná pole jako SearchController — login, jméno (i přes mezeru),
            // kontakt (email/telefon), aby odkaz „Otevřít všechny v seznamu" z /search seděl.
            $like   = '%' . $search . '%';
            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $multi  = count($tokens) > 1;

            $query->where(function ($q) use ($like, $tokens, $multi) {
                $q->where('users.login', 'like', $like)
                  ->orWhere(DB::raw("CONCAT(users.name, ' ', users.surname)"), 'like', $like);

                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('users_contacts as uc')
                        ->join('contacts as c', 'c.id', '=', 'uc.contact_id')
                        ->whereColumn('uc.user_id', 'users.id')
                        ->where('c.value', 'like', $like);
                });

                if ($multi) {
                    $q->orWhere(function ($qq) use ($tokens) {
                        foreach ($tokens as $t) {
                            $qq->where(DB::raw("CONCAT(users.name, ' ', users.surname)"), 'like', '%' . $t . '%');
                        }
                    });
                }
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

        $aroGroups = DB::table('groups_aro_map')
            ->join('aro_groups', 'aro_groups.id', '=', 'groups_aro_map.group_id')
            ->where('groups_aro_map.aro_id', $user->id)
            ->select('aro_groups.id', 'aro_groups.name')
            ->get();

        $deviceAdmins    = DeviceAdmin::where('user_id', $user->id)->with('device')->get();
        $deviceEngineers = DeviceEngineer::where('user_id', $user->id)->with('device')->get();

        return view('users.show', [
            'user'                 => $user,
            'aroGroups'            => $aroGroups,
            'deviceAdmins'         => $deviceAdmins,
            'deviceEngineers'      => $deviceEngineers,
            'canEdit'              => $this->can('edit_all'),
            'canChangePassword'    => $this->can('edit_all', 'password'),
            'canViewAppPwd'        => $this->can('view_all', 'application_password'),
            'canChangeAppPwd'      => $this->can('edit_all', 'application_password'),
            'canViewContacts'      => $this->aclCheck('view_all',   'Users_Controller', 'additional_contacts'),
            'canAddContact'        => $this->aclCheck('new_all',    'Users_Controller', 'additional_contacts'),
            'canEditContact'       => $this->aclCheck('edit_all',   'Users_Controller', 'additional_contacts'),
            'canDeleteContact'     => $this->aclCheck('delete_all', 'Users_Controller', 'additional_contacts'),
            'canViewDevices'       => $this->aclCheck('view_all', 'Devices_Controller',    'devices'),
            'canViewLoginLogs'     => $this->aclCheck('view_all', 'Login_logs_Controller', 'logs'),
        ]);
    }

    public function create(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $member  = $request->has('member_id') ? Member::find($request->member_id) : null;
        $members = $member ? collect() : Member::orderBy('name')->get();

        return view('users.create', [
            'members'      => $members,
            'member'       => $member,
            'canEditLogin' => $this->can('new_all', 'login'),
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

        if ($user->member_id) {
            return redirect()->route('members.show', $user->member_id);
        }

        return redirect()->route('users.index');
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
        $isSelf  = (int) auth()->id() === (int) $id;
        $isAdmin = $this->can('edit_all', 'password');
        abort_unless($isSelf || $isAdmin, 403);

        $user = User::find($id);
        if (!$user) {
            abort(404);
        }

        $member = DB::table('members')->where('id', $user->member_id)->first();

        return view('users.change_password', compact('user', 'member', 'isAdmin', 'isSelf'));
    }

    public function updatePassword(Request $request, int $id)
    {
        $isSelf  = (int) auth()->id() === (int) $id;
        $isAdmin = $this->can('edit_all', 'password');
        abort_unless($isSelf || $isAdmin, 403);

        $user = User::find($id);
        if (!$user) {
            abort(404);
        }

        $minLength = (int) \App\Models\Setting::get('security_password_length', 8);

        // Self-change vyžaduje znalost aktuálního hesla — chrání před zneužitím
        // unesené session a před vzájemným zásahem mezi sub-uživateli členství.
        // Admin (edit_all,password) může reset bez current_password.
        $rules = [
            'password'              => "required|string|min:{$minLength}|confirmed",
            'password_confirmation' => 'required',
        ];
        if ($isSelf && !$isAdmin) {
            $rules['current_password'] = ['required', 'current_password'];
        }
        $request->validate($rules);

        $user->password = Hash::make($request->input('password'));
        $user->save();

        session()->flash('success', 'Heslo bylo úspěšně změněno.');

        return redirect()->route('members.show', $user->member_id);
    }

    public function toggleDarkMode(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        $settings = json_decode($user->settings ?? '{}', true) ?: [];
        $settings['dark_mode'] = $request->input('dark_mode', 0) ? 1 : 0;
        $user->settings = json_encode($settings);
        $user->save();
        return response()->json(['ok' => true]);
    }
}
