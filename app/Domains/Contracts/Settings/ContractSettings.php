<?php

namespace App\Domains\Contracts\Settings;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;

/**
 * Nastavení → Smlouvy: OTP podpisu, PDF podpis (certifikát), přílohy e-mailu
 * se smlouvou. Bez uložené hodnoty se zobrazí default z configu (env).
 * Tajné hodnoty (pepper, heslo certifikátu) prázdným polem nepřepisuje.
 */
class ContractSettings
{
    public const KEYS = [
        'otp_pepper', 'otp_ttl_min', 'otp_max_attempts', 'otp_resend_window_sec',
        'otp_test_mode', 'otp_test_code',
        'pdf_sign_cert', 'pdf_sign_pass', 'pdf_sign_name', 'pdf_sign_location', 'pdf_sign_reason',
        'contract_email_attachments_enabled',
        'contract_email_pricelist_pdf', 'contract_email_vop_pdf',
    ];

    /** Textová pole — prázdný vstup hodnotu vymaže. */
    public const PLAIN_KEYS = [
        'otp_ttl_min', 'otp_max_attempts', 'otp_resend_window_sec', 'otp_test_code',
        'pdf_sign_cert', 'pdf_sign_name', 'pdf_sign_location', 'pdf_sign_reason',
        'contract_email_pricelist_pdf', 'contract_email_vop_pdf',
    ];

    public const SECRET_KEYS = ['otp_pepper', 'pdf_sign_pass'];

    /** upload pole => cílový klíč, adresář v storage/app/private, záložní jméno */
    public const UPLOADS = [
        'pdf_sign_cert_file'                => ['target' => 'pdf_sign_cert',                'dir' => 'certs',                'fallback_name' => 'cert.pfx'],
        'contract_email_pricelist_pdf_file' => ['target' => 'contract_email_pricelist_pdf', 'dir' => 'contract-attachments', 'fallback_name' => 'cenik.pdf'],
        'contract_email_vop_pdf_file'       => ['target' => 'contract_email_vop_pdf',       'dir' => 'contract-attachments', 'fallback_name' => 'vop.pdf'],
    ];

    public function values(): array
    {
        $defaults = [
            'otp_pepper'            => '',
            'otp_ttl_min'           => (string) config('services.contracts.otp_ttl_min', 5),
            'otp_max_attempts'      => (string) config('services.contracts.otp_max_attempts', 5),
            'otp_resend_window_sec' => (string) config('services.contracts.otp_resend_window', 60),
            'otp_test_mode'         => config('services.contracts.otp_test_mode') ? '1' : '0',
            'otp_test_code'         => (string) config('services.contracts.otp_test_code', '111111'),
            'pdf_sign_cert'         => (string) config('services.contracts.pdf_sign_cert', ''),
            'pdf_sign_pass'         => '',
            'pdf_sign_name'         => (string) config('services.contracts.pdf_sign_name', ''),
            'pdf_sign_location'     => (string) config('services.contracts.pdf_sign_location', ''),
            'pdf_sign_reason'       => (string) config('services.contracts.pdf_sign_reason', ''),
            'contract_email_attachments_enabled' => '0',
            'contract_email_pricelist_pdf'       => 'storage/app/private/contract-attachments/cenik.pdf',
            'contract_email_vop_pdf'             => 'storage/app/private/contract-attachments/vop.pdf',
        ];
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = (string) Setting::get($key, $defaults[$key] ?? '');
        }
        return $out;
    }

    /**
     * Uloží nahraný soubor pod storage/app/private/<dir> (jméno sanitizované →
     * žádný path traversal) a vrátí cestu relativní k base_path() — stejný
     * formát, jaký admini píšou ručně (storage/app/private/...).
     */
    public function storeUpload(string $field, UploadedFile $file): string
    {
        $meta = self::UPLOADS[$field];
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME));
        $ext  = strtolower($file->getClientOriginalExtension() ?: pathinfo($meta['fallback_name'], PATHINFO_EXTENSION));
        $name = ($safe !== '' ? $safe : pathinfo($meta['fallback_name'], PATHINFO_FILENAME)) . '.' . $ext;
        $file->storeAs($meta['dir'], $name, 'local');
        return 'storage/app/private/' . $meta['dir'] . '/' . $name;
    }

    /**
     * @param array<string, mixed> $plain   PLAIN_KEYS => hodnota
     * @param array<string, string> $secrets SECRET_KEYS => hodnota ('' = ponechat)
     */
    public function save(array $plain, array $secrets, bool $otpTestMode, bool $attachmentsEnabled): void
    {
        foreach (self::PLAIN_KEYS as $key) {
            Setting::set($key, (string) ($plain[$key] ?? ''));
        }
        foreach (self::SECRET_KEYS as $key) {
            if (($secrets[$key] ?? '') !== '') {
                Setting::set($key, $secrets[$key]);
            }
        }
        Setting::set('otp_test_mode', $otpTestMode ? 1 : 0);
        Setting::set('contract_email_attachments_enabled', $attachmentsEnabled ? 1 : 0);
    }
}
