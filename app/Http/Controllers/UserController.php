<?php

namespace App\Http\Controllers;

use App\Domains\Members\Actions\RemoveUserAction;
use App\Domains\Members\Actions\ResetUserMfaAction;
use App\Domains\Members\Actions\UnlockUserLoginAction;
use App\Domains\Members\Exceptions\UserNotDeletable;
use App\Domains\Members\Queries\UserListQuery;
use App\Domains\Members\Repositories\UserRepository;
use App\Services\LoginLockService;
use App\Services\MfaService;
use Illuminate\Http\Request;

/** Uživatelé členů; data a akce v doméně Members. Jemná práva (login, komentář, člen, heslo) vynucuje i zápis. */
class UserController extends Controller
{
    private const ACL_SECTION = 'Users_Controller';
    private const ACL_VALUE   = 'users';

    public function __construct(private UserRepository $users) {}

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

        $users = app(UserListQuery::class)->paginate($search, $memberId, $sort, $dir, $perPage);

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

        $user = $this->users->findWithMember($id);
        if (!$user) {
            abort(404);
        }

        return view('users.show', [
            'user'                 => $user,
            'aroGroups'            => $this->users->aclGroups((int) $user->id),
            'deviceAdmins'         => $this->users->deviceAdmins((int) $user->id),
            'deviceEngineers'      => $this->users->deviceEngineers((int) $user->id),
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
            'mfaEnabled'           => app(MfaService::class)->isEnabled((int) $user->id),
            'canResetMfa'          => $this->can('edit_all'),
            'loginLock'            => app(LoginLockService::class)->status((int) $user->id),
            'canUnlockLogin'       => $this->can('edit_all'),
        ]);
    }

    /**
     * Ruční odemčení účtu zamčeného po opakovaných neúspěšných přihlášeních.
     * Auditováno.
     */
    public function unlockLogin(int $id, UnlockUserLoginAction $unlock)
    {
        abort_unless($this->can('edit_all'), 403);
        $unlock->handle((int) $this->users->findOrFail($id)->id, auth()->id(), request()->ip());

        return back()->with('success', 'Účet byl odemčen.');
    }

    /**
     * Admin reset cizího MFA — když uživatel ztratí telefon i záložní kódy.
     * Smaže jeho TOTP i záložní kódy; při dalším přihlášení už MFA nebude
     * vyžadováno a může si ho zřídit znovu. Auditováno.
     */
    public function resetMfa(int $id, ResetUserMfaAction $reset)
    {
        abort_unless($this->can('edit_all'), 403);
        $reset->handle((int) $this->users->findOrFail($id)->id, auth()->id(), request()->ip());

        return back()->with('success', 'Dvoufázové přihlášení uživatele bylo resetováno.');
    }

    public function create(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $member  = $request->has('member_id') ? $this->users->member((int) $request->member_id) : null;
        $members = $member ? collect() : $this->users->membersByName();

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
            'password'              => \App\Rules\StrongPassword::rules($request->input('login')),
            'name'                  => 'nullable|string|max:100',
            'middle_name'           => 'nullable|string|max:100',
            'surname'               => 'required|string|max:100',
            'pre_title'             => 'nullable|string|max:50',
            'post_title'            => 'nullable|string|max:50',
            'birthday'              => 'nullable|date',
            'type'                  => 'required|integer|in:1,2',
            'comment'               => 'nullable|string|max:250',
        ];

        // Login smí zadat jen kdo má jemné právo new_all#login. Bez něj se pole
        // do pravidel vůbec nepřidá → případný podvržený POST se zahodí (stejně
        // jako to dělá update()). Dřív tu byla nullable větev, kterou šlo obejít.
        if ($this->can('new_all', 'login')) {
            $rules['login'] = 'required|string|max:100|unique:users,login';
        }

        $user = $this->users->create($request->validate($rules));

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

        $user = $this->users->find($id);
        if (!$user) {
            abort(404);
        }
        $members = $this->users->membersByName();

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

        $user = $this->users->find($id);
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

        // Heslo se mění jen přes změnu hesla (jemné právo edit_all#password,
        // u sebe s aktuálním heslem). Formulář úpravy pole nemá — podvržené
        // „password" by jinak obešlo obě kontroly.

        $this->users->update($user, $request->validate($rules));

        session()->flash('success', 'Uživatel byl úspěšně upraven.');

        return redirect()->route('users.show', $id);
    }

    public function destroy(int $id, RemoveUserAction $remove)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $user = $this->users->find($id);
        if (!$user) {
            abort(404);
        }

        try {
            $remove->handle($user, auth()->id());
        } catch (UserNotDeletable $e) {
            session()->flash('error', $e->getMessage());
            return redirect()->back();
        }

        session()->flash('success', 'Uživatel byl úspěšně smazán.');

        return redirect()->route('users.index');
    }

    public function changePassword(int $id)
    {
        $isSelf  = (int) auth()->id() === (int) $id;
        $isAdmin = $this->can('edit_all', 'password');
        abort_unless($isSelf || $isAdmin, 403);

        $user = $this->users->find($id);
        if (!$user) {
            abort(404);
        }
        $member = $this->users->memberRow($user->member_id);

        return view('users.change_password', compact('user', 'member', 'isAdmin', 'isSelf'));
    }

    public function updatePassword(Request $request, int $id)
    {
        $isSelf  = (int) auth()->id() === (int) $id;
        $isAdmin = $this->can('edit_all', 'password');
        abort_unless($isSelf || $isAdmin, 403);

        $user = $this->users->find($id);
        if (!$user) {
            abort(404);
        }

        // Self-change vyžaduje znalost aktuálního hesla — chrání před zneužitím
        // unesené session a před vzájemným zásahem mezi sub-uživateli členství.
        // Admin (edit_all,password) může reset bez current_password.
        $rules = [
            'password'              => \App\Rules\StrongPassword::rules($user->login),
            'password_confirmation' => 'required',
        ];
        if ($isSelf && !$isAdmin) {
            $rules['current_password'] = ['required', 'current_password'];
        }
        $request->validate($rules);

        $this->users->setPassword($user, (string) $request->input('password'));

        session()->flash('success', 'Heslo bylo úspěšně změněno.');

        return redirect()->route('members.show', $user->member_id);
    }

    public function toggleDarkMode(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->users->setDarkMode(auth()->user(), (bool) $request->input('dark_mode', 0));
        return response()->json(['ok' => true]);
    }
}
