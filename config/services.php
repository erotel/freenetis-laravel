<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'contracts' => [
        'token_secret'      => env('CONTRACTS_TOKEN_SECRET', ''),
        'smlouvy_url'       => env('CONTRACTS_SMLOUVY_URL', ''),
        'otp_pepper'        => env('OTP_PEPPER'),
        'otp_ttl_min'       => env('OTP_TTL_MIN', 5),
        'otp_max_attempts'  => env('OTP_MAX_ATTEMPTS', 5),
        'otp_resend_window' => env('OTP_RESEND_WINDOW_SEC', 60),
        'otp_test_mode'     => env('OTP_TEST_MODE', false),
        'otp_test_code'     => env('OTP_TEST_CODE', '111111'),
        'pdf_sign_cert'     => env('PDF_SIGN_CERT'),
        'pdf_sign_pass'     => env('PDF_SIGN_PASS'),
        'pdf_sign_name'     => env('PDF_SIGN_NAME'),
        'pdf_sign_location' => env('PDF_SIGN_LOCATION'),
        'pdf_sign_reason'   => env('PDF_SIGN_REASON'),
        'storage'           => env('CONTRACTS_STORAGE', 'storage/app/private/contracts'),
        'tmp'               => env('CONTRACTS_TMP', 'storage/app/private/tmp'),
    ],

];
