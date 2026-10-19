<?php

namespace App\Http\Requests\Settings;

class SaveContractSettingsRequest extends SettingsRequest
{
    public function rules(): array
    {
        return [
            'otp_ttl_min'                  => 'nullable|integer|min:1|max:60',
            'otp_max_attempts'             => 'nullable|integer|min:1|max:20',
            'otp_resend_window_sec'        => 'nullable|integer|min:0|max:3600',
            'otp_test_code'                => 'nullable|string|max:20',
            'pdf_sign_cert'                => 'nullable|string|max:255',
            'pdf_sign_name'                => 'nullable|string|max:100',
            'pdf_sign_location'            => 'nullable|string|max:100',
            'pdf_sign_reason'              => 'nullable|string|max:200',
            'contract_email_pricelist_pdf' => 'nullable|string|max:255',
            'contract_email_vop_pdf'       => 'nullable|string|max:255',
            // PFX (~ pár kB) a PDF (do 10 MB)
            'pdf_sign_cert_file'                => 'nullable|file|max:1024|extensions:pfx,p12',
            'contract_email_pricelist_pdf_file' => 'nullable|file|max:10240|mimes:pdf',
            'contract_email_vop_pdf_file'       => 'nullable|file|max:10240|mimes:pdf',
        ];
    }
}
