<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zapnutí audit trailu
    |--------------------------------------------------------------------------
    | Globální vypínač. Když false, AuditLogger i Auditable trait mlčí
    | (užitečné pro testy/seedování, kde audit nechceme).
    */
    'enabled' => env('AUDIT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Retence (v měsících)
    |--------------------------------------------------------------------------
    | Partitions starší než tento počet měsíců `audit:maintain-partitions`
    | dropne. NIS2/ZoKB nemá jednotný limit; 24 měsíců je bezpečný default
    | pro ISP. 0 = nikdy nemazat.
    */
    'retention_months' => (int) env('AUDIT_RETENTION_MONTHS', 24),

    /*
    |--------------------------------------------------------------------------
    | Předstih budoucích partitions (v měsících)
    |--------------------------------------------------------------------------
    | Kolik měsíců dopředu se drží připravené partitions, aby INSERT nikdy
    | nespadl, i kdyby cron vynechal běh.
    */
    'future_buffer_months' => (int) env('AUDIT_FUTURE_BUFFER_MONTHS', 2),

    /*
    |--------------------------------------------------------------------------
    | Redakce citlivých polí
    |--------------------------------------------------------------------------
    | Hodnoty těchto sloupců se do auditu nikdy nezapíšou v plaintextu —
    | nahradí se značkou '***'. Zaznamená se, že se pole změnilo, ne jak.
    */
    'redact' => [
        'password',
        'password_hash',
        'remember_token',
        'application_password',
        'api_token',
        'token',
        'secret',
        'snmp_auth_pass',
        'snmp_priv_pass',
        'totp_secret',
    ],

    /*
    |--------------------------------------------------------------------------
    | Globálně ignorované sloupce
    |--------------------------------------------------------------------------
    | Šum, který nemá auditní hodnotu (timestampy). Do diffu se nezahrnují.
    */
    'exclude_columns' => [
        'created_at',
        'updated_at',
        // Telemetrie / machine-heartbeat — píše se automaticky, bez auditní
        // hodnoty. Když se ve změně mění JEN tyto sloupce, audit se přeskočí.
        'access_time',      // devices: „naposledy se ozval" (MikroTik poll pro DHCP config)
        'last_update',      // allowed_subnets: čas posledního sync
        'dhcp_expired',     // subnets: flag „přegenerovat DHCP" (Subnet::setExpired po změně IP)
        'dhcp_changed_at',  // subnets: čas poslední změny DHCP
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignorované tabulky
    |--------------------------------------------------------------------------
    | Tabulky, které se nikdy neauditují (fronty, cache, samotný audit).
    */
    'ignore_tables' => [
        'audit_logs',
        'logs',
        'login_logs',
        'log_queue',
        'sessions',
        'cache',
        'jobs',
        'email_queue',
        'sms_messages',
    ],

];
