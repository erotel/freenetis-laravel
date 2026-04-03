<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\EnumType;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    private const ACL_SECTION = 'Devices_Controller';
    private const ACL_VALUE   = 'devices';

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

        $allowedSorts = ['id', 'name', 'type', 'user_id', 'access_time'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $search = trim((string) $request->query('search', ''));

        $query = Device::with(['user', 'enumType']);

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        $devices = $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('devices.index', [
            'devices'   => $devices,
            'sort'      => $sort,
            'dir'       => $dir,
            'perPage'   => $perPage,
            'search'    => $search,
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function showByUser(int $userId)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $user = User::find($userId);
        if (!$user) {
            abort(404);
        }

        $devices = Device::with('enumType')
            ->withCount('ifaces')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();

        return view('devices.show_by_user', [
            'user'      => $user,
            'devices'   => $devices,
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

        $device = Device::with([
            'user.member',
            'enumType',
            'addressPoint.street',
            'addressPoint.town',
            'ifaces.ipAddresses.subnet',
            'deviceAdmins.user',
            'deviceEngineers.user',
        ])->find($id);

        if (!$device) {
            abort(404);
        }

        return view('devices.show', [
            'device'      => $device,
            'canEdit'     => $this->can('edit_all'),
            'canDelete'   => $this->can('delete_all'),
            'canViewLogin'    => $this->can('view_all', 'login'),
            'canViewPassword' => $this->can('view_all', 'password'),
        ]);
    }

    public function create(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $preselectedUserId = $request->query('user_id') ? (int) $request->query('user_id') : null;
        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('id')->pluck('value', 'id');
        $users = User::orderBy('surname')->orderBy('name')->get();

        return view('devices.create', [
            'deviceTypes'       => $deviceTypes,
            'users'             => $users,
            'preselectedUserId' => $preselectedUserId,
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $request->validate([
            'user_id'         => 'required|integer|exists:users,id',
            'name'            => 'required|string|max:255',
            'type'            => 'required|integer|exists:enum_types,id',
            'trade_name'      => 'nullable|string|max:50',
            'operating_system'=> 'nullable|integer',
            'PPPoE_logging_in'=> 'nullable|integer',
            'login'           => 'nullable|string|max:30',
            'password'        => 'nullable|string|max:30',
            'price'           => 'nullable|numeric|min:0',
            'payment_rate'    => 'nullable|numeric|min:0',
            'buy_date'        => 'nullable|date',
            'comment'         => 'nullable|string|max:254',
        ]);

        $device = Device::create($data);

        session()->flash('success', 'Zařízení bylo úspěšně přidáno.');
        return redirect()->route('devices.show', $device->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $device = Device::find($id);
        if (!$device) {
            abort(404);
        }

        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('id')->pluck('value', 'id');
        $users = User::orderBy('surname')->orderBy('name')->get();

        return view('devices.edit', [
            'device'             => $device,
            'deviceTypes'        => $deviceTypes,
            'users'              => $users,
            'canEditLogin'       => $this->can('view_all', 'login'),
            'canEditPassword'    => $this->can('view_all', 'password'),
        ]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $device = Device::find($id);
        if (!$device) {
            abort(404);
        }

        $rules = [
            'user_id'         => 'required|integer|exists:users,id',
            'name'            => 'required|string|max:255',
            'type'            => 'required|integer|exists:enum_types,id',
            'trade_name'      => 'nullable|string|max:50',
            'operating_system'=> 'nullable|integer',
            'PPPoE_logging_in'=> 'nullable|integer',
            'price'           => 'nullable|numeric|min:0',
            'payment_rate'    => 'nullable|numeric|min:0',
            'buy_date'        => 'nullable|date',
            'comment'         => 'nullable|string|max:254',
        ];

        if ($this->can('view_all', 'login')) {
            $rules['login'] = 'nullable|string|max:30';
        }

        if ($this->can('view_all', 'password')) {
            $rules['password'] = 'nullable|string|max:30';
        }

        $data = $request->validate($rules);
        $device->update($data);

        session()->flash('success', 'Zařízení bylo úspěšně upraveno.');
        return redirect()->route('devices.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $device = Device::find($id);
        if (!$device) {
            abort(404);
        }

        if ($device->ifaces()->exists()) {
            session()->flash('error', 'Zařízení nelze smazat, má přiřazená rozhraní.');
            return redirect()->back();
        }

        $userId = $device->user_id;
        $device->delete();

        session()->flash('success', 'Zařízení bylo úspěšně smazáno.');

        if ($userId) {
            return redirect()->route('devices.by_user', $userId);
        }

        return redirect()->route('devices.index');
    }
}
