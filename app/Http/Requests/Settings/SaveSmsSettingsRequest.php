<?php

namespace App\Http\Requests\Settings;

class SaveSmsSettingsRequest extends SettingsRequest
{
    public function rules(): array
    {
        return ['sms_sender_number' => 'nullable|string|max:20'];
    }
}
