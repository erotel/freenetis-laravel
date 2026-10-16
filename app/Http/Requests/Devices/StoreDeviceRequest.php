<?php

namespace App\Http\Requests\Devices;

/** Založení zařízení bez šablony (ACL new_all ověří authorize() před validací). */
class StoreDeviceRequest extends SaveDeviceRequest
{
    public function authorize(): bool
    {
        return $this->canDevices('new_all');
    }
}
