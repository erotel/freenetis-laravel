<?php

namespace App\Http\Requests\Devices;

/** Úprava zařízení (ACL edit_all ověří authorize() před validací). */
class UpdateDeviceRequest extends SaveDeviceRequest
{
    public function authorize(): bool
    {
        return $this->canDevices('edit_all');
    }
}
