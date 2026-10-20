<?php

namespace App\Http\Controllers\Devices;

use App\Domains\Access\Actions\EnsurePppoeCredentialAction;
use App\Domains\Access\Actions\RotatePppoeSecretAction;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Http\Controllers\Controller;
use App\Models\Iface;
use App\Models\Setting;

/** PPPoE přístupy rozhraní zařízení: vytvoření a rotace hesla. */
class DevicePppoeController extends Controller
{
    use DeviceAccess;

    /**
     * Vygeneruje (nebo dorovná) PPPoE credential pro rozhraní. Idempotentní —
     * existující heslo nemění. Vyžaduje právo editovat + vidět hesla; modul
     * musí být zapnutý (Setting pppoe_enabled). Viz onboarding návrh.
     */
    public function generate(int $ifaceId, EnsurePppoeCredentialAction $ensure)
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
    public function rotate(int $ifaceId, RotatePppoeSecretAction $rotate)
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
        $iface = app(DeviceRepository::class)->ifaceWithDeviceOrFail($ifaceId);
        abort_if(!$iface->device, 404);
        abort_unless($this->can('view_all') || $this->isOwnUser($iface->device->user_id), 403);
        return $iface;
    }
}
