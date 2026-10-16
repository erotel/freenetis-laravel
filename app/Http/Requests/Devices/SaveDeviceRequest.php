<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Založení / úprava zařízení bez šablony. Login/heslo/WPA2 klíč jsou citlivé —
 * pravidla (a tím i přijatá pole) jen s jemným právem; bez něj se podvržený
 * POST zahodí (validated() je neobsahuje). Formulář je stejným právem skrývá.
 */
abstract class SaveDeviceRequest extends FormRequest
{
    use DeviceRules;

    public function rules(): array
    {
        $rules = [
            'member_id'        => 'required|integer|exists:members,id',
            'name'             => 'required|string|max:255',
            'type'             => 'required|integer|exists:enum_types,id',
            'trade_name'       => 'nullable|string|max:50',
            'operating_system' => 'nullable|integer',
            'PPPoE_logging_in' => 'nullable|integer',
            'price'            => 'nullable|numeric|min:0',
            'payment_rate'     => 'nullable|numeric|min:0',
            'buy_date'         => 'nullable|date',
            'comment'          => 'nullable|string|max:254',
        ] + self::addressRules();

        if ($this->canDevices('view_all', 'login')) {
            $rules['login'] = 'nullable|string|max:30';
        }
        if ($this->canDevices('view_all', 'password')) {
            $rules['password'] = 'nullable|string|max:30';
            // WPA2 klíč AP: stejné právo jako heslo; délka dle WPA2 (≤63), min 22 kvůli entropii.
            $rules['wpa_key']  = 'nullable|string|min:22|max:63';
        }

        return $rules;
    }
}
