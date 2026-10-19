<?php

namespace App\Http\Requests\Settings;

class SaveSledovaniTvSettingsRequest extends SettingsRequest
{
    public function rules(): array
    {
        return ['sledovanitv_partner' => 'nullable|string|max:50'];
    }
}
