<?php

namespace App\Http\Controllers\Devices;

use App\Domains\Access\Repositories\ConnectionRequestRepository;
use App\Domains\Members\Repositories\UserRepository;
use App\Domains\Network\Actions\RegisterDeviceFromTemplateAction;
use App\Domains\Network\Queries\DeviceFormQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\StoreDeviceFromTemplateRequest;
use Illuminate\Http\Request;

/** Zařízení ze šablony: formulář (i ze žádosti o připojení) a založení. */
class DeviceFromTemplateController extends Controller
{
    use DeviceAccess;

    public function create(Request $request, DeviceFormQuery $form, ?int $userId = null)
    {
        abort_unless($this->can('new_all'), 403);

        $user = $userId ? app(UserRepository::class)->findOrFail($userId) : null;
        // Preselect: primárně ?member_id= (po reloadu formuláře), jinak člen
        // hlavního uživatele z URL (legacy „/devices/add/{userId}").
        $preselectedMemberId = $request->filled('member_id') ? (int) $request->query('member_id') : $user?->member_id;

        return view('devices.add', ['user' => $user, 'preselectedMemberId' => $preselectedMemberId]
            + $form->templateForm((int) $request->query('type_id') ?: null, (int) $request->query('template_id') ?: null));
    }

    public function createFromConnectionRequest(Request $request, int $crId, DeviceFormQuery $form)
    {
        abort_unless($this->can('new_all'), 403);

        $cr = app(ConnectionRequestRepository::class)->findOrFail($crId, ['member', 'deviceTemplate', 'deviceType']);

        // Admin může typ/šablonu ze žádosti přepsat (reloadWithTemplate()).
        $typeId     = (int) $request->query('type_id', $cr->device_type_id) ?: $cr->device_type_id;
        $templateId = (int) $request->query('template_id', $cr->device_template_id) ?: $cr->device_template_id;

        return view('devices.add', [
            'user'                => app(UserRepository::class)->firstOfMember((int) $cr->member_id),
            'preselectedMemberId' => $cr->member_id,
            'preselectedMac'      => $cr->mac_address,
            'preselectedIp'       => $cr->ip_address,
            'preselectedSubnetId' => $cr->subnet_id,
            'connectionRequestId' => $cr->id,
        ] + $form->templateForm($typeId, $templateId));
    }

    public function store(StoreDeviceFromTemplateRequest $request, RegisterDeviceFromTemplateAction $register)
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
}
