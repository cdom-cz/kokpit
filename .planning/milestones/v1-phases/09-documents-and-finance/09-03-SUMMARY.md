---
phase: 09-documents-and-finance
plan: 03
subsystem: documents
tags: [documents, download, signed-url, streaming, nosniff, route-walk, partner-isolation, filament-panel-route]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-01 Document model with the Partner scope, DocumentPolicy, kokpit.documents settings, canary Document fixture; 09-02 hardened upload with finfo-detected MIME types"
provides:
  - "GET /admin/files/documents/{document} (route name filament.admin.files.document): the one checked, signed, user-bound download and preview route"
  - DocumentUrl (user-bound temporary signed links) and DocumentDisposition (inline allowlist, safe download name, RFC 6266 header)
  - DocumentFileController streaming the object with nosniff, private no-store and no bucket detail
  - "document-files rate limiter and the panel SPA exception for /admin/files/*"
  - Route walk extended with the file route signed for the Partner
affects: [09-05 tabs, 09-08 Partner read grant, 09-12 forms, 09-14 listing, 09-18 archive route]

actuals:
  tokens: 7600
  tasks: 3
  commits: 3
plan_head_before: bc6574e25ae5098b13c74c5788bfb3d29b69ba79
plan_head_after: 1b19653618ebd99422e3af17041fb96ce36f9ff4
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Authenticated panel route (authenticatedRoutes) with SetNoReferrerPolicy first, signed before the limiter, throttle by user id with IP fallback"
    - "Signed link carries the requesting user id (u) and the mode under the signature; the controller compares u with the session"
    - "Fixed check order (user binding, UUID shape, scoped lookup, policy, media as system) so a foreign file is indistinguishable from an unknown one"
    - "Own Content-Disposition built with an ASCII fallback so the adapter's filename handling is skipped"
    - "Route-walk file route filled with the canary document of the requested side, signed for the signed-in user"

key-files:
  created:
    - app/Domain/Documents/Support/DocumentUrl.php
    - app/Domain/Documents/Support/DocumentDisposition.php
    - app/Http/Controllers/DocumentFileController.php
    - tests/Feature/Documents/DocumentFileRouteTest.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
    - app/Providers/AppServiceProvider.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "The app streams the object (FilesystemAdapter::response); there is no redirect to a presigned bucket URL, so the bucket host never reaches the browser and the response can carry nosniff"
  - "The route name is filament.admin.files.document, confirmed with route:list (research A9 assumption resolved for this route)"
  - "Content-Disposition is built by DocumentDisposition::header() with its own ASCII fallback (non-ASCII, quotes, backslash and percent removed, 'file' when empty) so a name that transliterates to nothing cannot throw inside Symfony's makeDisposition"
  - "A stored object that is missing from the disk answers 404 (exists() check) instead of failing mid-stream"
  - "The limiter key is the signed-in user id, IP address only as fallback for a request without a session"
  - "Rate-limit test lowers the config value to 3 for speed and a second test pins the default of 120 to the one config value"

patterns-established:
  - "Later screens call DocumentUrl::download()/preview() from an action at click time and redirect; a signed URL is never rendered into HTML"
  - "walkedUrl(route, recordId, side) signs the file route for the signed-in user; any other unknown route parameter still throws"

requirements-completed: [DO-03]

coverage:
  - id: D1
    description: "An Admin opens a signed link and receives the exact stored bytes streamed by the app, as an attachment with the document name, nosniff and private no-store caching, on a path under /admin/files/documents/"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentFileRouteTest.php#it streams the stored bytes of a document to the signed-in Admin through a signed app link"
        status: pass
      - kind: command
        ref: "ddev exec php artisan route:list --name=files.document lists filament.admin.files.document"
        status: pass
    human_judgment: false
  - id: D2
    description: "An unsigned, tampered or expired link and a link signed for another user answer 403 with Referrer-Policy no-referrer; a guest is sent to the panel login; a malformed or unknown id answers 404; the limit per minute answers 429 with no-referrer"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentFileRouteTest.php (refusal, guest, 404 and 429 cases)"
        status: pass
      - kind: integration
        ref: "mutation run: dropping the user binding, the policy check, the UUID shape check or nosniff made 2, 1, 1 and 6 route tests fail"
        status: pass
    human_judgment: false
  - id: D3
    description: "Only PNG, JPEG, GIF, WebP and PDF are inline and only when a preview was asked for; SVG, HTML, text and every other type are octet-stream attachments; a PDF asked as download is an attachment"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentFileRouteTest.php#it shows only a preview of the five allowlisted types inline and sends everything else as an octet-stream attachment (5 datasets)"
        status: pass
      - kind: integration
        ref: "mutation run: making isInline() always true made 3 route tests fail"
        status: pass
    human_judgment: false
  - id: D4
    description: "The download name is the document name plus its extension, encoded per RFC 6266 with an ASCII fallback; no header or body carries the bucket host, X-Amz or the media path"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentFileRouteTest.php (Czech name, renamed document, no bucket details cases)"
        status: pass
    human_judgment: false
  - id: D5
    description: "A Partner of another client, or one asking for a hidden file, gets 404; a Partner of the own client for the visible file gets 403 until the read grant of plan 09-08; the route walk proves Partner A gets 404 for client B's document with no canary or id in the body and a Partner without a client gets 403 or 404"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php (file route cases) and tests/Feature/Documents/DocumentFileRouteTest.php Partner cases"
        status: pass
    human_judgment: false
  - id: D6
    description: "The panel runs the file routes outside SPA navigation so a click on a download hands the browser a real file response in a real browser"
    requirement: DO-03
    verification:
      - kind: command
        ref: "spaUrlExceptions(['*/admin/files/*']) present in AdminPanelProvider; real-browser click behavior not exercised by an automated test"
        status: pass
    human_judgment: true
    rationale: "No browser test exists in this plan; the exception is configuration pinned by review. The first screen that calls DocumentUrl (plan 09-05) is where a real click is first verifiable."

# Metrics
duration: 30min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 03: Document Download Route Summary

**One authenticated, signed (120 s), user-bound panel route streams every document through the app with nosniff and private no-store headers, serves only five types inline, and is covered by the Partner route walk**

## Performance

- **Duration:** about 30 min (start time was not recorded; approximate)
- **Completed:** 2026-10-16T11:38Z
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 7 (4 created, 3 modified)

## Accomplishments

- `DocumentUrl::download()` / `preview()` sign `filament.admin.files.document` with `URL::temporarySignedRoute`, the TTL read from `kokpit.documents.signed_url_ttl_seconds` (never below 1 s), and the document id, mode and requesting user id under the signature.
- `DocumentFileController` checks in a fixed order: user id equals the session (403), UUID shape (404), Partner-scoped lookup (404), policy `view` (403), media row read as system (404), object exists (404); then streams through `FilesystemAdapter::response` with `Content-Length` from `size_bytes`, `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.
- `DocumentDisposition` holds `INLINE_MIME_TYPES` (png, jpeg, gif, webp, pdf), `isInline()`, `downloadName()` (name plus `.<extension>` when missing) and `header()` (RFC 6266 with a safe ASCII fallback). Inline needs both the `mode=inline` request and an allowlisted stored MIME type (the finfo-detected type of plan 09-02); everything else, SVG and HTML included, is `application/octet-stream` as attachment.
- The route is registered through `authenticatedRoutes()` with `SetNoReferrerPolicy`, `signed` and `throttle:document-files`, so panel authentication and two-factor run first, a bad signature is not counted and every 403 and 429 carries `Referrer-Policy: no-referrer`. `spaUrlExceptions(['*/admin/files/*'])` takes every file route out of SPA navigation.
- `document-files` limiter: `Limit::perMinute(kokpit.documents.download_rate_limit_per_minute)` keyed by user id with an IP fallback.
- The route walk now reaches the controller for the file route: `walkedUrl()` fills `{document}` with the canary document of the requested side, signed for the signed-in user.

## Route name

`ddev exec php artisan route:list --name=files.document` lists `GET|HEAD admin/files/documents/{document}` as `filament.admin.files.document` (DocumentFileController). Plans 09-05, 09-08, 09-12, 09-14 and 09-18 can rely on `DocumentUrl::ROUTE`.

## Task Commits

1. **Task 1: Tracer - an Admin opens a signed link and receives the stored bytes streamed by the app** - `8e6fa52` (feat)
2. **Task 2: Every refusal, the inline allowlist and the safe headers** - `8583518` (feat, TDD)
3. **Task 3: The route walk covers the file route with a link signed for the Partner** - `1b19653` (test, TDD)

The tracer gate: `<verify>` is automated-only and `human_verify_mode` is `end-of-phase`, so the tracer's tests, `route:list`, Pint and PHPStan were re-run end to end (green) before expansion.

## Files Created/Modified

- `app/Domain/Documents/Support/DocumentUrl.php` - user-bound short-lived signed links
- `app/Domain/Documents/Support/DocumentDisposition.php` - inline allowlist, download name, Content-Disposition header
- `app/Http/Controllers/DocumentFileController.php` - checked streaming of one document
- `app/Providers/Filament/AdminPanelProvider.php` - the authenticated file route and the SPA exception
- `app/Providers/AppServiceProvider.php` - the `document-files` limiter
- `tests/Feature/Documents/DocumentFileRouteTest.php` - 17 route tests
- `tests/Isolation/RouteWalkTest.php` - file route walk (side-aware, signed for the signed-in user) and 4 new cases

## Decisions Made

See `key-decisions` in the frontmatter. The Partner `view` grant is deliberately absent: `DocumentPolicy` inherits the denying `view` of `KokpitPolicy`, so a Partner of the own client gets 403 on the visible file until plan 09-08.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Own Content-Disposition instead of the adapter's filename handling**
- **Found during:** Task 1
- **Issue:** `FilesystemAdapter::response()` derives the ASCII fallback with `Str::ascii`; a name that transliterates to nothing, or contains a quote, backslash or percent sign, makes Symfony's `makeDisposition` throw or emit an unsafe header.
- **Fix:** `DocumentDisposition::header()` builds the value with its own sanitized fallback and the controller passes it in the `Content-Disposition` header, which makes the adapter skip its own handling.
- **Files modified:** `app/Domain/Documents/Support/DocumentDisposition.php`, `app/Http/Controllers/DocumentFileController.php`
- **Verification:** Czech-name and renamed-document route tests pass.
- **Committed in:** 8e6fa52

**2. [Rule 1 - Bug] Missing object answered with a broken stream**
- **Found during:** Task 1
- **Issue:** The plan says 404 when the media is missing; a media row whose object is gone would only fail inside the streaming callback, after headers were sent.
- **Fix:** The controller checks `exists()` on the disk first and answers 404.
- **Files modified:** `app/Http/Controllers/DocumentFileController.php`
- **Committed in:** 8e6fa52

**Total deviations:** 2 auto-fixed (1 missing critical, 1 bug). **Impact:** none on the contract; both harden the route.

## Authentication Gates

None.

## Issues Encountered

- Task 2's cases all passed on the first run because the tracer controller already implemented the full check order. To prove the tests bite, five mutations were run against the production code (inline always true, user binding removed, policy check removed, nosniff removed, UUID check removed); each made at least one route test fail, and every mutation was reverted.
- The 429 test lowers the per-minute value to 3 so the test stays fast; the default of 120 is pinned to the one config value by a second test.

## Known Stubs

None.

## Threat Flags

None. The new surface (file route, limiter) is the one covered by T-09-09 to T-09-13 of the plan's threat model.

## Verification

- `ddev exec vendor/bin/pest`: 4003 passed, 3 skipped (pre-existing), 0 failed
- `ddev exec vendor/bin/pest tests/Feature/Documents tests/Isolation`: 266 passed
- `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse`: clean; `scripts/check-sensitive.sh` clean on every staged set

## Next Phase Readiness

Ready for plan 09-05 (tabs calling `DocumentUrl` at click time), 09-08 (Partner `view` grant; the 403 case in `DocumentFileRouteTest` then becomes 200 and must be updated there) and 09-18 (archive route beside this one, under the same `/admin/files/` SPA exception).

## Self-Check: PASSED

- Files exist: `DocumentUrl.php`, `DocumentDisposition.php`, `DocumentFileController.php`, `DocumentFileRouteTest.php` (found)
- Commits `8e6fa52`, `8583518`, `1b19653` are ancestors of HEAD; `git rev-list --count bc6574e..HEAD` is 3
- All task acceptance criteria re-run: pass
