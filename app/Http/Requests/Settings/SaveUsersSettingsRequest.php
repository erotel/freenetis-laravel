<?php

namespace App\Http\Requests\Settings;

class SaveUsersSettingsRequest extends SettingsRequest
{
    public function rules(): array
    {
        return [
            'security_password_length' => 'nullable|integer|min:4|max:32',
            'security_password_level'  => 'nullable|integer|in:1,2,3,4',
        ];
    }
}
