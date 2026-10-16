<?php

namespace App\Http\Controllers;

use App\Domains\Access\Actions\ExportDhcpConfigAction;
use App\Domains\Access\Repositories\DhcpExportRepository;
use App\Domains\Access\Support\DhcpClientId;
use App\Domains\Network\Actions\CreateDeviceAction;
use App\Domains\Network\Actions\RegisterDeviceFromTemplateAction;
use App\Domains\Network\Actions\RemoveDeviceAction;
use App\Domains\Network\Actions\RemoveDeviceEngineerAction;
use App\Domains\Network\Actions\UpdateDeviceAction;
use App\Domains\Network\Documents\DeviceInventoryCsv;
use App\Domains\Network\Exceptions\DeviceOwnerInvalid;
use App\Domains\Network\Queries\DeviceCardQuery;
use App\Domains\Network\Queries\DeviceFormQuery;
use App\Domains\Network\Queries\DeviceListQuery;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Http\Filters\DeviceFilter;
use App\Http\Requests\Devices\StoreDeviceFromTemplateRequest;
use App\Http\Requests\Devices\StoreDeviceRequest;
use App\Http\Requests\Devices\UpdateDeviceRequest;
use App\Models\ConnectionRequest;
use App\Models\Device;
use App\Models\Iface;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    private const ACL_SECTION = 'Devices_Controller';
    private const ACL_VALUE   = 'devices';

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    /**
     * True, pokud daný uživatel patří pod stejného člena jako přihlášený uživatel
     * (self-access — zákazník smí vidět vlastní zařízení i bez ACL view_all).
     */
    private function isOwnUser(?int $userId): bool
    {
        $ownMemberId = auth()->user()?->member_id;
        if (!$ownMemberId || !$userId) {
            return false;
        }

        return app(DeviceRepository::class)->memberIdOfUser($userId) == $ownMemberId;
    }

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

        $user = User::find($userId);
        if (!$user) {
            abort(404);
        }

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

    /**
     * Vygeneruje (nebo dorovná) PPPoE credential pro rozhraní. Idempotentní —
     * existující heslo nemění. Vyžaduje právo editovat + vidět hesla; modul
     * musí být zapnutý (Setting pppoe_enabled). Viz onboarding návrh.
     */
    public function pppoeGenerate(int $ifaceId, \App\Domains\Access\Actions\EnsurePppoeCredentialAction $ensure)
    {
        abort_unless((bool) Setting::get('pppoe_enabled', 0), 403, 'PPPoE modul není zapnutý.');
        abort_unless($this->can('edit_all') && $this->can('view_all', 'password'), 403);

        $iface  = $this->pppoeIface($ifaceId);
        $secret = $ensure->handle($iface);

        return redirect()->route('devices.show', $iface->device_id)->with(
            $secret ? 'success' : 'error',
            $secret
                ? "PPPoE přístup vytvořen: {$secret->username}"
                : 'PPPoE přístup nelze vytvořit — rozhraní nemá přiřazeného člena.'
        );
    }

    /** Přegeneruje heslo (rotace) — username zůstává. Stejná autorizace jako generate. */
    public function pppoeRotate(int $ifaceId, \App\Domains\Access\Actions\RotatePppoeSecretAction $rotate)
    {
        abort_unless((bool) Setting::get('pppoe_enabled', 0), 403, 'PPPoE modul není zapnutý.');
        abort_unless($this->can('edit_all') && $this->can('view_all', 'password'), 403);

        $iface  = $this->pppoeIface($ifaceId);
        $secret = $rotate->handle($iface);

        return redirect()->route('devices.show', $iface->device_id)->with(
            $secret ? 'success' : 'error',
            $secret ? 'Heslo PPPoE bylo přegenerováno.' : 'Rotaci nelze provést.'
        );
    }

    /**
     * Dohledá iface pro PPPoE akci a ověří vlastnictví zařízení (uživatel ho smí
     * editovat). Autorizaci modulu + práva řeší volající metoda (kvůli statickému
     * ACL guardu musí být markery přímo v těle akce).
     */
    private function pppoeIface(int $ifaceId): Iface
    {
        $iface = Iface::with('device.user')->findOrFail($ifaceId);
        abort_if(!$iface->device, 404);
        abort_unless($this->can('view_all') || $this->isOwnUser($iface->device->user_id), 403);
        return $iface;
    }

    // ── Engineers ─────────────────────────────────────────────────────────────

    public function addEngineer(Request $request, int $deviceId, DeviceRepository $devices)
    {
        abort_unless($this->can('new_all', 'engineer'), 403);

        Device::findOrFail($deviceId);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $devices->addEngineer($deviceId, (int) $data['user_id']);

        return redirect()->route('devices.show', $deviceId)
            ->with('success', 'Technik byl přidán.');
    }

    public function removeEngineer(int $deviceId, int $userId, RemoveDeviceEngineerAction $remove)
    {
        abort_unless($this->can('delete_all', 'engineer'), 403);

        $remove->handle($deviceId, $userId);

        return redirect()->route('devices.show', $deviceId)
            ->with('success', 'Technik byl odebrán.');
    }

    public function createWithTemplate(Request $request, DeviceFormQuery $form, ?int $userId = null)
    {
        abort_unless($this->can('new_all'), 403);

        $user = $userId ? User::findOrFail($userId) : null;
        // Preselect: primárně ?member_id= (po reloadu formuláře), jinak člen
        // hlavního uživatele z URL (legacy „/devices/add/{userId}").
        $preselectedMemberId = $request->filled('member_id') ? (int) $request->query('member_id') : $user?->member_id;

        return view('devices.add', ['user' => $user, 'preselectedMemberId' => $preselectedMemberId]
            + $form->templateForm((int) $request->query('type_id') ?: null, (int) $request->query('template_id') ?: null));
    }

    public function createFromConnectionRequest(Request $request, int $crId, DeviceFormQuery $form)
    {
        abort_unless($this->can('new_all'), 403);

        $cr = ConnectionRequest::with(['member', 'deviceTemplate', 'deviceType'])->findOrFail($crId);

        // Admin může typ/šablonu ze žádosti přepsat (reloadWithTemplate()).
        $typeId     = (int) $request->query('type_id', $cr->device_type_id) ?: $cr->device_type_id;
        $templateId = (int) $request->query('template_id', $cr->device_template_id) ?: $cr->device_template_id;

        return view('devices.add', [
            'user'                => User::where('member_id', $cr->member_id)->orderBy('id')->first(),
            'preselectedMemberId' => $cr->member_id,
            'preselectedMac'      => $cr->mac_address,
            'preselectedIp'       => $cr->ip_address,
            'preselectedSubnetId' => $cr->subnet_id,
            'connectionRequestId' => $cr->id,
        ] + $form->templateForm($typeId, $templateId));
    }

    public function storeWithTemplate(StoreDeviceFromTemplateRequest $request, RegisterDeviceFromTemplateAction $register)
    {
        try {
            $deviceId = $register->handle($request->deviceData(), $request->ifaceInputs(), $request->connectionRequestId(), auth()->id());
        } catch (\Exception $e) {
            \Log::error('storeWithTemplate error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        session()->flash('success', 'Zařízení bylo úspěšně přidáno.');
        return redirect()->route('devices.show', $deviceId);
    }

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

        $device = Device::with(['addressPoint.street', 'addressPoint.town'])->find($id);
        if (!$device) {
            abort(404);
        }
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
        $device = Device::find($id);
        if (!$device) {
            abort(404);
        }

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

    public function dhcpServers(DeviceListQuery $list)
    {
        abort_unless($this->aclCheck('view_all', 'Devices_Controller', 'devices'), 403);

        return view('devices.dhcp_servers', [
            'devices' => $list->dhcpServers(),
            'token'   => Setting::get('dhcp_api_token'),
        ]);
    }

    // ── DHCP Export ───────────────────────────────────────────────────────────

    public function export(Request $request, int $id, string $format, DhcpExportRepository $repo, ExportDhcpConfigAction $action)
    {
        if (!in_array($format, ExportDhcpConfigAction::FORMATS)) {
            abort(404);
        }

        // Čas zachytíme PŘED načtením stavu subnetů (viz ExportDhcpConfigAction).
        $exportStartedAt = now();

        $device = $repo->deviceWithSubnets($id);
        if (!$device) {
            abort(404);
        }

        // Auth: DHCP servery se ověřují dhcp_api_tokenem, jinak přihlášený uživatel
        // s ACL view_all#export. Legacy „volání z vlastní IP zařízení" (fromDevice)
        // bylo odstraněno (audit 2026-08-18): DHCP servery už jezdí přes token a
        // IP-based větev jen otevírala cross-member únik (export vydává
        // MAC + jméno člena sousedů na subnetu komukoliv, kdo zavolá z IP zařízení).
        $token      = $request->input('token') ?? $request->bearerToken();
        $validToken = Setting::get('dhcp_api_token');
        $tokenValid = $token && $validToken && hash_equals($validToken, $token);

        if (!$tokenValid) {
            if (auth()->guest()) {
                abort(403);
            }
            abort_unless($this->aclCheck('view_all', 'Devices_Controller', 'export'), 403);
        }

        $text = $action->handle(
            $device,
            $format,
            fromDhcpServer: (bool) $tokenValid,
            forced: $request->boolean('forced'),
            client: DhcpClientId::normalize($request->input('client')),
            role: (string) $request->input('role'),
            startedAt: $exportStartedAt,
        );

        if ($text === null) {
            return response('', 204);
        }

        return response($text, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * CSV inventář VŠECH zařízení — vše jako na detailu zařízení (info, technici,
     * rozhraní s MAC/IPv4/IPv6), BEZ PPPoE sekce. Credentialy (login/heslo/wpa2)
     * jen s příslušným právem (stejně jako detail). Streamované + chunk kvůli
     * tisícům zařízení (bounded paměť). Oddělovač ';' + UTF-8 BOM (Excel/CZ).
     */
    public function inventoryCsv(Request $request, DeviceInventoryCsv $csv): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($this->can('view_all'), 403);

        return $csv->download($this->can('view_all', 'login'), $this->can('view_all', 'password'));
    }
}
