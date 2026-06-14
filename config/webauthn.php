<?php

return [

    /*
    | Relying Party ID — musí být registrovatelná doména shodná s tím, na čem
    | aplikace běží (BEZ schématu, portu a cesty). Např. "is.pvfree.net".
    | Když není v .env, odvodí se z hostu APP_URL.
    |
    | WebAuthn funguje JEN v secure contextu (HTTPS) nebo na localhost.
    */
    'rp_id' => env('WEBAUTHN_RPID') ?: (parse_url((string) env('APP_URL'), PHP_URL_HOST) ?: 'localhost'),

    'rp_name' => env('WEBAUTHN_RPNAME', 'FreenetIS'),

    /*
    | User verification pro login: 'required' | 'preferred' | 'discouraged'.
    | 'required' = vždy biometrie/PIN (doporučeno pro náš případ).
    */
    'user_verification' => env('WEBAUTHN_USER_VERIFICATION', 'required'),

];
