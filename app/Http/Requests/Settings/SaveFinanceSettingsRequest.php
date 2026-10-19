<?php

namespace App\Http\Requests\Settings;

class SaveFinanceSettingsRequest extends SettingsRequest
{
    public function rules(): array
    {
        return [
            'deduct_day'               => 'required|integer|min:1|max:31',
            'pending_termination_day'  => 'nullable|integer|min:1|max:31',
            'admin_notification_email' => 'nullable|email|max:255',
        ];
    }
}
