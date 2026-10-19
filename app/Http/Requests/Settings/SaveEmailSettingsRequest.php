<?php

namespace App\Http\Requests\Settings;

class SaveEmailSettingsRequest extends SettingsRequest
{
    public function rules(): array
    {
        return ['registration_summary_pdf_file' => 'nullable|file|max:10240|mimes:pdf'];
    }
}
