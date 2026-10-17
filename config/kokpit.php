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
    | unsent_invoice_email_warning_after / unsent_invoice_email_stale_after
    |   (R-37-1, D-22): seconds a document e-mail may wait, queued or sending,
    |   before the unsent invoice e-mails slot turns Warning, and seconds a
    |   row may stay in sending (a worker that died mid-send) before it counts
    |   as failed and turns the slot Error. The state of a document is the
    |   state of its latest e-mail row (R-24-1).
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
        'unsent_invoice_email_warning_after' => 900,
        'unsent_invoice_email_stale_after' => 3600,
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
    | REST API
    |--------------------------------------------------------------------------
    |
    | Fixed settings of the /api/v1 REST API. Literal starting points, not
    | editable in the UI.
    |
    | rate_limit.read / rate_limit.write: requests a minute per token for the
    |   read routes and for the write routes. The two are separate buckets, and
    |   every token has its own; a request over the limit answers 429 with
    |   Retry-After and X-RateLimit-Limit / X-RateLimit-Remaining.
    | docs_rate_limit: requests a minute per IP address for the public API
    |   documentation pages.
    | page_size.default / page_size.max: items of one list page when the client
    |   names no size, and the largest size it may ask for.
    | batch_max_items: largest number of entries one batch request may carry.
    | transaction_batch_max_items: largest number of transactions one batch
    |   request may carry.
    | external_max_bytes: largest size in bytes of the free-form `external`
    |   object an API client may store with an entry.
    | idempotency_ttl_hours: how long a stored Idempotency-Key and its response
    |   are kept before they are purged.
    | idempotency_stale_lock_seconds: a key claimed but never completed is taken
    |   over by a retry after this many seconds.
    |
    */

    'api' => [
        'rate_limit' => [
            'read' => 120,
            'write' => 60,
        ],
        'docs_rate_limit' => 60,
        'page_size' => [
            'default' => 50,
            'max' => 200,
        ],
        'batch_max_items' => 100,
        'transaction_batch_max_items' => 500,
        'external_max_bytes' => 4096,
        'idempotency_ttl_hours' => 24,
        'idempotency_stale_lock_seconds' => 120,
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
    |   the Admin gets one bell notification from a scheduled job. The bell
    |   notice itself never stops the timer; only the max_running_hours safety
    |   net below ends one.
    | max_running_hours: a running entry whose start is more than this many
    |   hours ago is ended every hour by the scheduled kokpit:time:end-overrun
    |   (routes/console.php, kokpit-end-overrun-timers) at its start plus this
    |   many hours, never at now. It is a safety net for entries left running,
    |   mainly hand-inserted ones, and it keeps the stored duration far below
    |   the integer column limit. Config only, no environment variable.
    |
    */

    'time' => [
        'long_running_hours' => 12,
        'max_running_hours' => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoicing
    |--------------------------------------------------------------------------
    |
    | Fixed settings of the billing documents (IN-02). Literal starting point,
    | not editable in the UI.
    |
    | max_items: the most lines one draft may carry. UpdateDraft refuses a
    |   longer list with a field error on `items`, so a runaway form or a
    |   forged request cannot build a document the PDF and the screens can no
    |   longer hold.
    |
    */

    'invoicing' => [
        'max_items' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | CNB exchange rates
    |--------------------------------------------------------------------------
    |
    | Fixed settings of the rates area (EX-01, EX-02; D-03, D-04, D-05). Literal
    | starting points, not editable in the UI, kept as literals because no
    | deployment needs a different value.
    |
    | attempt_hours / final_hour (D-03): the CNB publishes the daily list on
    |   working days after 14:30 Prague time, so the scheduled download is
    |   attempted at each of the hours in attempt_hours (Europe/Prague, Monday
    |   to Friday), every attempt after the publication. final_hour is one more
    |   run after the last attempt, at most until about 18:00 and never after 22.
    |   Only the final run raises the failure alert, so an Admin is told once a
    |   day and not once per hourly attempt; the earlier attempts retry quietly.
    |
    | health.warning_after_working_days / health.error_after_working_days (D-04):
    |   how many working days the newest stored list may be behind the expected
    |   date before the System page slot turns Warning, then Error. The count is
    |   in working days, never calendar days: a weekend or a public holiday
    |   publishes no list, so a fixed number of days would show a false Warning
    |   after every long weekend and a false OK after a real outage. A working
    |   day is a weekday that is not a Czech public holiday (CzechPublicHolidays).
    |
    | gap_lookback_days: how far back the scheduled run looks for a working day
    |   without a stored list and downloads it, so an outage heals itself on
    |   the next run. A gap older than this is closed with the backfill.
    |
    | backfill.earliest / backfill.max_span_days (D-05): the earliest day a
    |   history backfill may start from, and the longest span in days one
    |   request may cover. The CNB serves one year per request, so a span costs
    |   at most one request per calendar year it touches; the cap keeps a single
    |   queued job short.
    |
    */

    'rates' => [
        'attempt_hours' => [15, 16, 17],
        'final_hour' => 18,
        'health' => [
            'warning_after_working_days' => 1,
            'error_after_working_days' => 2,
        ],
        'gap_lookback_days' => 31,
        'backfill' => [
            'earliest' => '2000-01-01',
            'max_span_days' => 3660,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report exports
    |--------------------------------------------------------------------------
    |
    | Fixed settings of the exports area (RE-03, D-09). Literal starting points,
    | not editable in the UI.
    |
    | ttl_days (D-09): how many days a finished export file is kept on the
    |   private disk, counted from its completion. The scheduled prune then
    |   deletes the file and the record. A pending or failed record expires the
    |   same number of days after its creation, so stale records go too. The
    |   value is never read below 1.
    |
    | csv.delimiter / csv.decimal_separator (research A1): the CSV is written
    |   for Czech Excel, which reads a semicolon as the column separator and a
    |   comma as the decimal sign (a comma delimiter would split every amount
    |   such as 1234,50 in two). The file starts with a UTF-8 byte order mark so
    |   Excel reads the Czech characters, and every text cell is neutralised
    |   against spreadsheet formulas.
    |
    */

    'exports' => [
        'ttl_days' => 7,
        'csv' => [
            'delimiter' => ';',
            'decimal_separator' => ',',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | Fixed settings of the files area (DO-01 to DO-06). Literal starting
    | points, not editable in the UI; the upload rules, the file route and the
    | ZIP job all read them from here.
    |
    | max_size_kb (D-06): the largest single file. Filament and Livewire count
    |   KILOBYTES, the medialibrary ceiling (media-library.max_file_size) counts
    |   BYTES; the two are the same 10 MB and a test pins them together.
    |
    | max_files_per_upload (D-06): how many files one upload may carry.
    |
    | signed_url_ttl_seconds (D-10): how long a signed download link lives. Kept
    |   short on purpose: the link is minted again on every click.
    |
    | download_rate_limit_per_minute: downloads a minute per signed-in user.
    |
    | archive (D-08, research A6): a ZIP of a selection is built once and kept
    |   ttl_days days, then pruned; one archive holds at most max_files files
    |   and max_bytes bytes (200 MB) of source files, so a request cannot ask
    |   the job for unbounded work.
    |
    */

    'documents' => [
        'max_size_kb' => 10240,
        'max_files_per_upload' => 10,
        'signed_url_ttl_seconds' => 120,
        'download_rate_limit_per_minute' => 120,
        'archive' => [
            'ttl_days' => 7,
            'max_files' => 200,
            'max_bytes' => 209715200,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Time report
    |--------------------------------------------------------------------------
    |
    | Fixed settings of the "Report času" page (RE-01, RE-02; UI-SPEC U-16).
    | Literal starting point, not editable in the UI.
    |
    | max_rendered_rows: at most this many leaf rows (task rows, or the day or
    |   user rows below them) are rendered in the report tree, which keeps the
    |   HTML of a long period bounded. The summary, the row totals and the
    |   footer are always computed over every entry, and the exports cover the
    |   full set, so the cap only limits what is drawn. The value is never read
    |   below 1.
    |
    */

    'report' => [
        'max_rendered_rows' => 1000,
    ],

];
