<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table      = 'config';
    protected $primaryKey = 'name';
    public    $incrementing = false;
    protected $keyType    = 'string';
    protected $fillable   = ['name', 'value'];

    /**
     * Klíče, jejichž hodnota se v `config` tabulce ukládá zašifrovaná
     * (Laravel Crypt — APP_KEY symetrické). Plaintext z legacy DB se přečte
     * přes try/catch fallback, takže migrace existujících záznamů není nutná —
     * při další úpravě v Nastavení se hodnota automaticky zašifruje.
     */
    private const ENCRYPTED_KEYS_EXACT = [
        'sledovanitv_password',
        'pdf_sign_pass',
        'email_password',
        'dhcp_api_token',
    ];

    private const ENCRYPTED_KEY_PREFIXES = [
        'fio_api_token_bank_account_',  // FIO API token per bank account
        'sms_password',                  // sms_password1..9 (per dispatcher)
    ];

    private static function isEncrypted(string $name): bool
    {
        if (in_array($name, self::ENCRYPTED_KEYS_EXACT, true)) return true;
        foreach (self::ENCRYPTED_KEY_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) return true;
        }
        return false;
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        $row = static::find($name);
        if (!$row) return $default;

        $value = $row->value;
        if ($value !== null && $value !== '' && self::isEncrypted($name)) {
            try {
                $value = Crypt::decryptString($value);
            } catch (DecryptException $e) {
                // Legacy plaintext — vrátíme tak, jak je. Při další set()
                // se přepíše šifrovanou variantou.
            }
        }

        return $value;
    }

    public static function set(string $name, mixed $value): void
    {
        $stored = (string) $value;
        if ($stored !== '' && self::isEncrypted($name)) {
            $stored = Crypt::encryptString($stored);
        }
        static::updateOrCreate(['name' => $name], ['value' => $stored]);
    }

    /**
     * `config` je key/value tabulka — kromě smysluplných nastavení (které
     * auditovat CHCEME) obsahuje i systémové/heartbeat klíče, jež se přepisují
     * automaticky (cron každou minutu, redirect sync). Ty do auditu nepatří:
     * je to šum bez odpovědnosti („kdo") a zaplavily by log.
     */
    public function auditShouldSkip(string $action): bool
    {
        $name = (string) ($this->name ?? '');

        $systemKeys = [
            'cron_last_active', 'cron_state', 'redirection_state',
            'sledovanitv_last_sync', 'sledovanitv_last_sync_status',
        ];
        if (in_array($name, $systemKeys, true)) {
            return true;
        }

        // Budoucí telemetrie stejného typu (…_last_active / …_last_sync / …_heartbeat).
        return (bool) preg_match('/(_last_active|_last_sync|_last_run|_heartbeat)$/', $name);
    }
}
