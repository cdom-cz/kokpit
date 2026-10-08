<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication for the admin
    |--------------------------------------------------------------------------
    |
    | Switch for local development and tests only (D-06). Production keeps the
    | default of true.
    |
    */

    'require_admin_two_factor' => (bool) env('KOKPIT_REQUIRE_ADMIN_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | Canary harness
    |--------------------------------------------------------------------------
    |
    | Enables the test-suite-only canary models and routes (D-04). Never true
    | outside the test suite.
    |
    */

    'canary_harness' => (bool) env('KOKPIT_CANARY_HARNESS', false),

    /*
    |--------------------------------------------------------------------------
    | Operational alerts
    |--------------------------------------------------------------------------
    |
    | Thresholds of the Admin alert raised when a background job fails for
    | good (D-11). Starting points, not editable in the UI, kept as literals
    | because no deployment needs a different value.
    |
    | throttle_seconds: after an alert for a job class, further failures of the
    |   same class inside this window are only counted; the count is reported
    |   with the next alert.
    | message_max_length: characters kept from the first line of the exception
    |   message. Messages can carry SQL values or connection details, so the
    |   alert never holds more.
    |
    */

    'alerts' => [
        'throttle_seconds' => 900,
        'message_max_length' => 200,
    ],

];
