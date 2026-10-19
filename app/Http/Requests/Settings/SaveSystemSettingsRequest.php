<?php

namespace App\Http\Requests\Settings;

class SaveSystemSettingsRequest extends SettingsRequest
{
    public function rules(): array
    {
        return [
            'title'              => 'nullable|string|max:100',
            'ico'                => 'nullable|string|max:20',
            'dic'                => 'nullable|string|max:20',
            'session_expiration' => 'nullable|integer|min:300',
        ];
    }
}
