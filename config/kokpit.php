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

];
