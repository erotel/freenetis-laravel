<?php

namespace App\Domains\Members\Settings;

use App\Models\Setting;

/**
 * Nastavení → Systém (spolek, samoregistrace, zapomenuté heslo, session),
 * Uživatelé (politika hesel, odebrání zařízení bývalým) a Registrace (HTML
 * šablony registračního PDF — bez sanitizace: píše admin v TinyMCE a PDF
 * renderuje mPDF na serveru, viz members/registration_pdf.blade.php).
 */
class SystemSettings
{
    public const SYSTEM_KEYS = [
        'title', 'ico', 'dic', 'self_registration', 'forgotten_password', 'session_expiration',
        'association_www', 'association_email', 'association_phone',
        'association_court', 'association_court_ref',
    ];

    public const USERS_KEYS = [
        'security_password_length', 'security_password_level', 'former_member_auto_device_remove',
    ];

    public const REGISTRATION_KEYS = [
        'registration_info', 'registration_license',
    ];

    /** @param string[] $keys */
    public function values(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = Setting::get($key, '');
        }
        return $out;
    }

    /** @param array<string, mixed> $texts textová pole SYSTEM_KEYS (bez checkboxů) */
    public function saveSystem(array $texts, bool $selfRegistration, bool $forgottenPassword): void
    {
        Setting::set('title', $texts['title']);
        Setting::set('ico', $texts['ico']);
        Setting::set('dic', $texts['dic']);
        Setting::set('self_registration', $selfRegistration ? 1 : 0);
        Setting::set('forgotten_password', $forgottenPassword ? 1 : 0);
        Setting::set('session_expiration', $texts['session_expiration']);
        foreach (['association_www', 'association_email', 'association_phone', 'association_court', 'association_court_ref'] as $key) {
            Setting::set($key, $texts[$key]);
        }
    }

    public function saveUsers(mixed $passwordLength, mixed $passwordLevel, bool $removeFormerDevices): void
    {
        Setting::set('security_password_length', $passwordLength);
        Setting::set('security_password_level', $passwordLevel);
        Setting::set('former_member_auto_device_remove', $removeFormerDevices ? 1 : 0);
    }

    /** @param array<string, string> $templates */
    public function saveRegistration(array $templates): void
    {
        foreach (self::REGISTRATION_KEYS as $key) {
            Setting::set($key, (string) ($templates[$key] ?? ''));
        }
    }
}
