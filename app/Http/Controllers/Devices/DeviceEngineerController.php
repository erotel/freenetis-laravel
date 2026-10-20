<?php

namespace App\Http\Controllers\Devices;

use App\Domains\Network\Actions\RemoveDeviceEngineerAction;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Technici zařízení: přidání a odebrání. */
class DeviceEngineerController extends Controller
{
    use DeviceAccess;

    public function store(Request $request, int $deviceId, DeviceRepository $devices)
    {
        abort_unless($this->can('new_all', 'engineer'), 403);

        $devices->findOrFail($deviceId);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $devices->addEngineer($deviceId, (int) $data['user_id']);

        return redirect()->route('devices.show', $deviceId)
            ->with('success', 'Technik byl přidán.');
    }

    public function destroy(int $deviceId, int $userId, RemoveDeviceEngineerAction $remove)
    {
        abort_unless($this->can('delete_all', 'engineer'), 403);

        $remove->handle($deviceId, $userId);

        return redirect()->route('devices.show', $deviceId)
            ->with('success', 'Technik byl odebrán.');
    }
}
