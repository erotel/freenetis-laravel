<?php

namespace App\Domains\Access\Listeners;

use App\Domains\Access\Actions\AdoptPppoeCredentialAction;
use App\Domains\Access\Actions\EnsurePppoeCredentialAction;
use App\Domains\Access\Events\DeviceRegisteredFromRequest;
use App\Domains\Access\Repositories\PppoeCredentialRepository;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * PPPoE onboarding: přípojná rozhraní nového zařízení (IP s gateway=0) dostanou
 * credential. První PŘEVEZME username/heslo ze žádosti (instalátor ho už zadal
 * do CPE), další se dogenerují. Jen se zapnutým modulem — během přechodu
 * MAC/IP zůstává výchozí. Nikdy nesmí shodit vytvoření zařízení.
 */
class ProvisionPppoeCredentials
{
    public function __construct(
        private PppoeCredentialRepository $credentials,
        private EnsurePppoeCredentialAction $ensure,
        private AdoptPppoeCredentialAction $adopt,
    ) {
    }

    public function handle(DeviceRegisteredFromRequest $event): void
    {
        if (!Setting::get('pppoe_enabled', 0)) {
            return;
        }
        try {
            $request = $this->credentials->requestCredential($event->connectionRequestId);
            $adopted = false;
            foreach ($this->credentials->connectionIfaces($event->deviceId) as $iface) {
                if (!$adopted && $request !== null) {
                    $this->adopt->handle($iface, $request['username'], $request['secret']);
                    $adopted = true;
                } else {
                    $this->ensure->handle($iface);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PPPoE credential při registraci selhal', [
                'device' => $event->deviceId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
