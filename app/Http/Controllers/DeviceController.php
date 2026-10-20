<?php

namespace App\Http\Controllers;

use App\Domains\Members\Repositories\UserRepository;
use App\Domains\Network\Actions\CreateDeviceAction;
use App\Domains\Network\Actions\RemoveDeviceAction;
use App\Domains\Network\Actions\UpdateDeviceAction;
use App\Domains\Network\Exceptions\DeviceOwnerInvalid;
use App\Domains\Network\Queries\DeviceCardQuery;
use App\Domains\Network\Queries\DeviceFormQuery;
use App\Domains\Network\Queries\DeviceListQuery;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Http\Controllers\Devices\DeviceAccess;
use App\Http\Filters\DeviceFilter;
use App\Http\Requests\Devices\StoreDeviceRequest;
use App\Http\Requests\Devices\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\Setting;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    use DeviceAccess;

    public function index(Request $request, DeviceListQuery $list)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $sort = in_array($request->query('sort'), DeviceListQuery::SORTS, true) ? $request->query('sort') : 'id';
        $dir  = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, DeviceListQuery::PER_PAGES, true)) {
            $perPage = 50;
        }

        $search          = trim((string) $request->query('search', ''));
        $advancedFilters = $request->input('filters', []);

        return view('devices.index', [
            'devices'        => $list->paginate($search, $advancedFilters, $sort, $dir, $perPage),
            'sort'           => $sort,
            'dir'            => $dir,
            'perPage'        => $perPage,
            'search'         => $search,
            'filterFields'   => DeviceFilter::fields(),
            'currentFilters' => $advancedFilters,
            'canNew'         => $this->can('new_all'),
            'canEdit'        => $this->can('edit_all'),
            'canDelete'      => $this->can('delete_all'),
        ]);
    }

    public function showByUser(int $userId)
    {
        if (!$this->can('view_all') && !$this->isOwnUser($userId)) {
            abort(403);
        }

        $user = app(UserRepository::class)->findOrFail($userId);

        $sort = in_array(request('sort'), DeviceListQuery::USER_SORTS, true) ? request('sort') : 'name';
        $dir  = request('dir') === 'desc' ? 'desc' : 'asc';

        $devices = app(DeviceListQuery::class)->forUser($userId, $sort, $dir);

        return view('devices.show_by_user', [
            'user'      => $user,
            'devices'   => $devices,
            'sort'      => $sort,
            'dir'       => $dir,
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
            'canViewUser' => $this->aclCheck('view_all', 'Users_Controller', 'users'),
            // Self-service: vlastník smí požádat o nové připojení (stejně jako SNMP-unknown flow)
            'canRequestConnection' => $this->isOwnUser($userId)
                && (bool) Setting::get('connection_request_enable')
                && $this->aclCheck('new_own', 'Connection_Requests_Controller', 'request'),
            'memberId' => $user->member_id,
        ]);
    }

    public function show(int $id, DeviceCardQuery $card)
    {
        $device = $card->find($id);
        if (!$device) {
            abort(404);
        }

        if (!$this->can('view_all') && !$this->isOwnUser($device->user_id)) {
            abort(403);
        }

        $canManageEngineers = $this->can('new_all', 'engineer');

        // PPPoE přístupy (per iface) — jen se zapnutým modulem a právem vidět hesla.
        $pppoeEnabled = (bool) Setting::get('pppoe_enabled', 0);

        return view('devices.show', [
            'device'             => $device,
            'canEdit'            => $this->can('edit_all'),
            'canDelete'          => $this->can('delete_all'),
            'canEditDevice'      => $this->can('edit_all'),
            'canViewLogin'       => $this->can('view_all', 'login'),
            'canViewPassword'    => $this->can('view_all', 'password'),
            'pppoeEnabled'       => $pppoeEnabled,
            'pppoeSecrets'       => ($pppoeEnabled && $this->can('view_all', 'password')) ? $card->pppoeSecrets($device) : collect(),
            'canManageEngineers' => $canManageEngineers,
            'canDeleteEngineer'  => $this->can('delete_all', 'engineer'),
            'engineerUsers'      => $canManageEngineers ? $card->assignableEngineers($device) : collect(),
            'canViewAll'         => $this->can('view_all'),
            'canViewUser'        => $this->aclCheck('view_all', 'Users_Controller', 'users'),
        ]);
    }

    // ── PPPoE přístupy (per iface) ──────────────────────────────────────────────

    // ── Engineers ─────────────────────────────────────────────────────────────

    public function create(Request $request, DeviceFormQuery $form, DeviceRepository $devices)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        // Zpětná kompatibilita URL: ?user_id= se převede na člena.
        $preselectedMemberId = $request->filled('member_id')
            ? (int) $request->query('member_id')
            : ($request->filled('user_id') ? $devices->memberIdOfUser((int) $request->query('user_id')) : null);

        return view('devices.create', [
            'deviceTypes'         => $form->deviceTypeOptions(),
            'members'             => $form->membersForPicker(),
            'towns'               => $form->towns(),
            'preselectedMemberId' => $preselectedMemberId,
            'canEditLogin'        => $this->can('view_all', 'login'),
            'canEditPassword'     => $this->can('view_all', 'password'),
            'apTypeId'            => Device::AP_TYPE_ID,
        ]);
    }

    public function store(StoreDeviceRequest $request, CreateDeviceAction $create)
    {
        try {
            $device = $create->handle($request->validated());
        } catch (DeviceOwnerInvalid $e) {
            return back()->withInput()->withErrors(['member_id' => $e->getMessage()]);
        }

        session()->flash('success', 'Zařízení bylo úspěšně přidáno.');
        return redirect()->route('devices.show', $device->id);
    }

    public function edit(int $id, DeviceFormQuery $form, DeviceRepository $devices)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $device = $devices->findWithAddressOrFail($id);
        $ap = $device->addressPoint;

        return view('devices.edit', [
            'device'              => $device,
            'deviceTypes'         => $form->deviceTypeOptions(),
            'members'             => $form->membersForPicker(),
            'towns'               => $form->towns(),
            'currentMemberId'     => $devices->memberIdOfUser($device->user_id),
            'currentTownId'       => $ap?->town_id,
            'currentStreetId'     => $ap?->street_id,
            'currentStreetNumber' => $ap?->street_number,
            'canEditLogin'        => $this->can('view_all', 'login'),
            'canEditPassword'     => $this->can('view_all', 'password'),
            'apTypeId'            => Device::AP_TYPE_ID,
        ]);
    }

    public function update(UpdateDeviceRequest $request, int $id, UpdateDeviceAction $update)
    {
        $device = app(DeviceRepository::class)->findOrFail($id);

        try {
            $update->handle($device, $request->validated());
        } catch (DeviceOwnerInvalid $e) {
            return back()->withInput()->withErrors(['member_id' => $e->getMessage()]);
        }

        session()->flash('success', 'Zařízení bylo úspěšně upraveno.');
        return redirect()->route('devices.show', $id);
    }

    public function destroy(int $id, RemoveDeviceAction $remove)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $removed = $remove->handle($id);

        return redirect()->route('devices.by_user', $removed->userId)
            ->with('success', 'Zařízení bylo smazáno.');
    }

    // ── DHCP Servers overview ─────────────────────────────────────────────────

    // ── DHCP Export ───────────────────────────────────────────────────────────

}
