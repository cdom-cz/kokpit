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
    |   message after it was sanitised (hosts, IPs, ports, DSN fragments, quoted
    |   values and SQL tails removed, see AlertMessageSanitiser). The sanitising
    |   is best effort; the unfiltered message stays in failed_jobs and the log.
    |
    */

    'alerts' => [
        'throttle_seconds' => 900,
        'message_max_length' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Partner invitations
    |--------------------------------------------------------------------------
    |
    | ttl_days: how many days an invitation link stays valid after it was issued
    |   or resent (D-02). A literal starting point, not editable in the UI.
    |
    */

    'invitations' => [
        'ttl_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | System page health thresholds
    |--------------------------------------------------------------------------
    |
    | Fixed thresholds of the health indicators on the System page (D-12).
    | Starting points, not editable in the UI, kept as literals because no
    | deployment needs a different value.
    |
    | failed_jobs_warning_at: this many failed jobs or more is a Warning.
    | oldest_pending_warning_after / oldest_pending_error_after: seconds the
    |   oldest job may wait in the queue before the slot turns Warning, then Error.
    | scheduler_heartbeat_error_after: seconds without a scheduler heartbeat
    |   before the slot turns Error; the scheduler writes one every minute.
    |
    | Caveat, delayed jobs: the age of a pending job runs from the creation of
    |   the job, not from the moment it became ready. A job dispatched with a
    |   delay shows its whole delay as age once it is ready. The base job's
    |   backoff tops out at 300 s, below the warning; a later job with a longer
    |   delay or backoff must account for this.
    | Caveat, heartbeat flush: both heartbeats live in the cache (Cache::forever).
    |   A cache flush or a cache server restart wipes them, so the slots show
    |   Error for up to one minute until the next scheduler run writes them again.
    |
    */

    'health' => [
        'failed_jobs_warning_at' => 1,
        'oldest_pending_warning_after' => 600,
        'oldest_pending_error_after' => 1800,
        'scheduler_heartbeat_error_after' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | Task board
    |--------------------------------------------------------------------------
    |
    | Fixed settings of the kanban board (D-02). A literal starting point, not
    | editable in the UI.
    |
    | done_limit: how many of the most recently completed tasks the Done column
    |   shows. Older done tasks are reached through the task list.
    |
    */

    'board' => [
        'done_limit' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Time tracking
    |--------------------------------------------------------------------------
    |
    | Fixed settings of the timer (D-07). A literal starting point, not
    | editable in the UI.
    |
    | long_running_hours: a timer that has been running this many hours or more
    |   is flagged as forgotten: the top bar pill turns to the danger state and
    |   the Admin gets one bell notification from a scheduled job. The timer is
    |   never stopped automatically.
    |
    */

    'time' => [
        'long_running_hours' => 12,
    ],

];
