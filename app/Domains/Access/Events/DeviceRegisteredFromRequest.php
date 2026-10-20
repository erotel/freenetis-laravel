<?php

namespace App\Domains\Access\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Technik založil zařízení ze žádosti o připojení (Devices\DeviceFromTemplateController::store
 * s connection_request_id). Dispatchne se i u už rozhodnuté žádosti — listenery
 * (PPPoE onboarding) se nemají řídit stavem žádosti.
 */
class DeviceRegisteredFromRequest
{
    use Dispatchable;

    public function __construct(
        public readonly int $deviceId,
        public readonly int $connectionRequestId,
    ) {
    }
}
