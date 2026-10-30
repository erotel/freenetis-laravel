<?php

namespace App\Domains\Notifications\Settings;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Nastavení → E-mail: SMTP, souhrn registrace (PDF příloha) a BCC pravidla
 * (email_bcc_rule_{n}_message_id / _subject_prefix / _address, n < 10).
 */
class EmailSettings
{
    public const KEYS = [
        'email_enabled', 'email_driver', 'email_hostname', 'email_port',
        'email_username', 'email_password', 'email_encryption', 'email_default_email',
        'registration_summary_enabled', 'registration_summary_pdf',
    ];

    public const BCC_PREFIX = 'email_bcc_rule_';
    private const BCC_SLOTS = 10;
    private const BCC_FIELDS = ['message_id', 'subject_prefix', 'address'];

    /** Pro formulář — heslo se do stránky neposílá, jen příznak, že je uložené. */
    public function values(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = Setting::get($key, '');
        }
        $out['email_password_set'] = $out['email_password'] !== '';
        $out['email_password']     = '';
        return $out;
    }

    /** Vyplněná BCC pravidla, doplněná prázdnými řádky na aspoň 3. */
    public function bccRules(): array
    {
        $rules = [];
        for ($i = 0; $i < self::BCC_SLOTS; $i++) {
            $rule = [];
            foreach (self::BCC_FIELDS as $f) {
                $rule[$f] = Setting::get(self::BCC_PREFIX . $i . '_' . $f, '');
            }
            if ($rule['message_id'] !== '' || $rule['subject_prefix'] !== '' || $rule['address'] !== '') {
                $rules[] = $rule;
            }
        }
        while (count($rules) < 3) {
            $rules[] = ['message_id' => '', 'subject_prefix' => '', 'address' => ''];
        }
        return $rules;
    }

    public function messages(): Collection
    {
        return Message::orderBy('name')->get(['id', 'name', 'type']);
    }

    /** @param array<string, mixed> $values klíč z KEYS => hodnota; prázdné heslo = ponechat uložené */
    public function save(array $values): void
    {
        foreach (self::KEYS as $key) {
            if ($key === 'email_password' && (string) ($values[$key] ?? '') === '') {
                continue;
            }
            Setting::set($key, $values[$key] ?? '');
        }
    }

    /**
     * Přepíše BCC pravidla: smaže všech 10 slotů a uloží neprázdná (adresa
     * nebo prefix předmětu) zhuštěně od 0. Pole indexovaná podle adres.
     */
    public function saveBccRules(array $messageIds, array $subjectPrefixes, array $addresses): void
    {
        for ($i = 0; $i < self::BCC_SLOTS; $i++) {
            foreach (self::BCC_FIELDS as $f) {
                Setting::set(self::BCC_PREFIX . $i . '_' . $f, '');
            }
        }
        $idx = 0;
        foreach ($addresses as $i => $address) {
            $address       = trim($address);
            $subjectPrefix = trim($subjectPrefixes[$i] ?? '');
            $messageId     = trim($messageIds[$i] ?? '');
            if ($address !== '' || $subjectPrefix !== '') {
                Setting::set(self::BCC_PREFIX . $idx . '_message_id', $messageId);
                Setting::set(self::BCC_PREFIX . $idx . '_subject_prefix', $subjectPrefix);
                Setting::set(self::BCC_PREFIX . $idx . '_address', $address);
                $idx++;
            }
        }
    }

    /**
     * Uloží PDF souhrnu registrace do storage/app/private (cíl napevno, jméno
     * sanitizované → žádný path traversal). Vrací název relativní k
     * storage/app/private (formát, který čte RegistrationController).
     */
    public function storeSummaryPdf(UploadedFile $file): string
    {
        $orig = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $name = (preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig) ?: 'smlouva_shrnuti') . '.pdf';
        $file->storeAs('', $name, 'local');
        return $name;
    }
}
