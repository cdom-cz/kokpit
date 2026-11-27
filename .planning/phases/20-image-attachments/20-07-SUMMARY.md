---
phase: 20-image-attachments
plan: 07
subsystem: knowledge
tags: [route, controller, authorisation, streaming, csp, rate-limit, partner-isolation]
status: complete

requires:
  - phase: 20-image-attachments
    provides: KnowledgeArticleImage model and fixtures (20-01), InternalImageSrcSanitizer::PATTERN (20-04)
provides:
  - GET /admin/knowledge/attachments/{attachment} (filament.admin.knowledge.attachment), the only delivery path of a stored image
  - KnowledgeAttachmentController (ROUTE, path(), __invoke())
  - rate limiter knowledge-attachments and kokpit.knowledge.image_rate_limit_per_minute
  - route walk and channel probe declarations for the new route parameter
affects: [20-08, 20-09, 20-10]

tech-stack:
  added: []
  patterns:
    - "unsigned, session-bound file route inside authenticatedRoutes (access dies with the session)"
    - "304 answered only after the authorisation, so an ETag never reveals an image to a caller without access"

key-files:
  created:
    - app/Http/Controllers/KnowledgeAttachmentController.php
    - tests/Feature/Knowledge/KnowledgeAttachmentRouteTest.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
    - app/Providers/AppServiceProvider.php
    - config/kokpit.php
    - tests/Isolation/RouteWalkTest.php
    - tests/Support/ChannelProbe.php

key-decisions:
  - "Followed rulings R-20-07-1 to R-20-07-4 verbatim; the weak validator prefix (W/) is accepted when comparing If-None-Match."

requirements-completed: [IMG-02]

plan_head_before: 60d9f014f4d2484cced6cabe7b30db1ae49a0ab6
plan_head_after: 5b9affc2a727216d2d6637aa18bcf2d2325cee4f
commits: 2

actuals:
  tokens: 15000
  tasks: 3
  commits: 2

metrics:
  completed: 2026-11-27
---

# Phase 20 Plan 07: Authorised image route Summary

**One unsigned, session-bound route streams stored knowledge images from the private disk with the D-12 header set and its own sandbox CSP; Partners, guests, stale links and foreign pending images are refused, and the Partner audit walks the route.**

## What was built

- **`KnowledgeAttachmentController`** (`ROUTE`, `path()`, `__invoke()`), fixed check order: user must exist and pass `viewAny` on the image model (Partner 403 before any lookup); UUID shape (404); row by lowercase id (404); pending image only for its uploader (404 otherwise), bound image through `KnowledgeArticle::withTrashed()` and the `view` policy of the article (archived included); `If-None-Match` equal to the quoted sha256 gives 304 (only after the authorisation); then `Storage::disk()->response()` with exactly Content-Type (stored), Content-Length, `Cache-Control: private, max-age=300`, ETag, nosniff, `Content-Security-Policy: default-src 'none'; sandbox`, `Content-Disposition: inline` (no file name) and `Vary: Cookie`. 404 when the object is missing. No URL method of the disk, no redirect, no logging.
- **Route** `GET /knowledge/attachments/{attachment}` in the `authenticatedRoutes` closure after the cash receipt route, middleware `SetNoReferrerPolicy` then `throttle:knowledge-attachments`, no `signed`, name `knowledge.attachment` (full: `filament.admin.knowledge.attachment`, path `/admin/knowledge/attachments/{uuid}`, matches `InternalImageSrcSanitizer::PATTERN`).
- **Limiter** `knowledge-attachments` in `AppServiceProvider::boot()` (per user id, else IP) and config block `kokpit.knowledge.image_rate_limit_per_minute` = 600.
- **Feature tests** (`KnowledgeAttachmentRouteTest`, 15 cases): full header set and bytes, no file name/path/bucket in headers, path matches the sanitiser pattern and carries no signature, no `signed` middleware and the limiter present, guest redirect, logout then same link redirect, Partner 403 for any image/unknown id/conditional request and guest never 304, 404 for unknown/malformed id and missing object, own pending 200 and foreign pending 404 (also with the right ETag), archived article 200, 304 with empty body and 200 for another ETag, no CSP on the article view screen, 429 at limit 3, default config value.
- **Isolation:** `walkedKnowledgeImage()` and the `attachment` branch of `walkedUrl()` (RouteWalkTest), the same branch plus `walkKnowledgeImage()`, owner mapping, file-route sides and the `knowledge image a` / `knowledge image b` download probe requests (expected refused) in `ChannelProbe`; two new RouteWalkTest cases (Partner A 403 on both canary images, parameter filled only for this route) and the route name added to the "walks at least" list.

## Task commits

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 | Tracer: route, controller, limiter, config, Feature tests | 9fd3a931 |
| 2 | Refusals, ETag order, limiter tests | included in 9fd3a931 (see deviations) |
| 3 | Route walk and channel probe declarations | 5b9affc2 |

## Deviations from Plan

**1. [Process] Tasks 1 and 2 committed together.** The controller was written with the complete check order of R-20-07-2 from the start (the tracer path and the refusals share one method), and the Feature test file was written whole. Task 2 therefore had no separate diff; all its behaviours are covered by the tests in the Task 1 commit. No behaviour differs from the plan.

Otherwise none - plan executed as written.

## Verification status

Worktree only (CLAUDE.md: no migrations, no DB-backed suites, no `vendor/` here):

- `php -l` clean on every touched PHP file; Pint (main checkout's binary) passes on all touched files.
- All acceptance greps: `knowledge.attachment` 1, `throttle:knowledge-attachments` 1, `RateLimiter::for('knowledge-attachments'` 1, `default-src 'none'; sandbox` 1, `private, max-age=300` 1, `image_rate_limit_per_minute` 1 in config, `KnowledgeAttachmentController::ROUTE` in RouteWalkTest and ChannelProbe, `knowledge image a` 1 in ChannelProbe, `getETags`, `uploaded_by`, `withTrashed()` in the controller.
- `scripts/check-sensitive.sh` clean; lefthook (sensitive-content, gitleaks) passed on both commits.
- PHPStan could not run (no `vendor/larastan` in the worktree).

**Not run here, to run after the merge of wave 2 on the HEAD branch (DDEV, one process at a time, never `--parallel`):**

1. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/KnowledgeAttachmentRouteTest.php tests/Feature/Documents/DocumentFileRouteTest.php`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Isolation/RouteWalkTest.php tests/Isolation/CanaryRegistryTest.php`
3. `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Audit`
4. PHPStan.

Points worth watching: (a) the logout test posts to `Filament::getLogoutUrl()` then `auth()->forgetGuards()`; if the panel logout needs a different shape, adjust the test, not the controller; (b) the `Cache-Control` assertions check `private` and `max-age=300` separately, because Symfony may reorder directives; (c) the 304 is built as a plain Laravel `Response` with the full header set; confirm the headers survive Symfony's 304 preparation.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-20-22 to T-20-27 mitigated as specified).

## Self-Check: PASSED

- Files exist: controller, Feature test, the five modified files, this SUMMARY.
- Commits 9fd3a931 and 5b9affc2 are ancestors of HEAD.
