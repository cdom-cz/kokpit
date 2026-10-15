<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // ARES, the public Czech business register: a government address, no key or account.
    'ares' => [
        'base_url' => env('ARES_BASE_URL', 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty'),
    ],

    // The Czech National Bank exchange rate API: a public address, no key or account.
    'cnb' => [
        'base_url' => env('CNB_API_BASE_URL', 'https://api.cnb.cz/cnbapi'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // The standard AWS names are read by AWS services only; the object storage uses its own S3_* keys.
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
