---
phase: 02-platform-foundation
plan: 01
subsystem: infra
tags: [laravel-13, filament-5, composer, gitignore, hygiene, spdx]

requires:
  - phase: 01-repository-hygiene
    provides: lefthook pre-commit hook (sensitive-content check and gitleaks), scripts/sensitive-allowlist.txt, test-gitignore.sh verdict harness
provides:
  - Laravel 13 skeleton merged into the repository root, committed through the pre-commit hook
  - Single Filament 5 panel (id admin, path /admin, SPA mode, login) with / redirecting to /admin
  - composer.json kokpit/kokpit, php ^8.5, license AGPL-3.0-only, and a tracked composer.lock
  - Sanctum, spatie permission, medialibrary, tags, activitylog, webhook-client, brick/money, Pest 5, PHPUnit 13, Larastan, laravel-lang installed
  - Content-ignore .gitignore rules so every storage and bootstrap/cache placeholder is trackable (fresh clone installs)
  - Two path-scoped composer.lock allowlist entries approved by the maintainer
affects: [02-02, 02-03, 02-04, 02-09, 02-11, 02-12, 02-13]

actuals:
  tokens: 19000
  tasks: 1
  commits: 1

tech-stack:
  added: [laravel/framework 13.35.0, filament/filament 5.10.0, livewire/livewire 4.4.7, pestphp/pest 5.3.0, phpunit/phpunit 13.3.6, larastan/larastan 3.13.0, brick/money 0.15.2, brick/math 1.0.0, laravel/sanctum 4.3.3, spatie/laravel-permission 8.3.0, spatie/laravel-medialibrary 11.23.9, spatie/laravel-tags 4.12.0, spatie/laravel-activitylog 5.1.1, spatie/laravel-webhook-client 3.7.0, laravel-lang/common 6.8.0]
  patterns:
    - "Content-ignore, never directory-ignore: wildcard, re-include child directory, content-ignore child, re-include its .gitignore"
    - "Collision fixes at the source (queue config, welcome view, agent files) instead of allowlist entries"

key-files:
  created:
    - composer.json
    - composer.lock
    - artisan
    - app/Providers/Filament/AdminPanelProvider.php
    - bootstrap/providers.php
    - routes/web.php
    - config/queue.php
    - .env.example
    - .editorconfig
    - .gitattributes
    - phpunit.xml
  modified:
    - .gitignore
    - scripts/tests/test-gitignore.sh
    - scripts/sensitive-allowlist.txt

key-decisions:
  - "Task 1 decision recorded verbatim: path-scoped"
  - "Task 2 decision recorded verbatim: agpl-only (SPDX AGPL-3.0-only)"
  - "config/queue.php keeps only the sync and redis connections, default redis; the SQS connection with a hosting URL default is removed at the source"
  - "The skeleton's pao package, agent instruction files, README, Node build files, welcome view and ExampleTest files are not imported"

requirements-completed: [FND-01, FND-14]

coverage:
  - id: D1
    description: "Laravel 13 skeleton with Filament 5 merged and committed through the lefthook hook (sensitive-content check and gitleaks) without bypass flags"
    requirement: "FND-14"
    verification:
      - kind: other
        ref: "git commit 805824b with lefthook sensitive-content and gitleaks both passing; scripts/check-sensitive.sh --all exits 0"
        status: pass
    human_judgment: false
  - id: D2
    description: "A fresh git clone of the committed tree runs composer install to completion on PHP 8.5"
    requirement: "FND-01"
    verification:
      - kind: integration
        ref: "git clone + composer install in a temp dir, then test -f storage/framework/views/.gitignore and bootstrap/cache/.gitignore"
        status: pass
    human_judgment: false
  - id: D3
    description: "Single Filament SPA panel (admin, /admin, login) and / redirect"
    requirement: "FND-01"
    verification:
      - kind: other
        ref: "php artisan route:list --path=admin lists admin/login; route / resolves to RedirectController; grep -- '->spa()' AdminPanelProvider.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "composer.json named kokpit/kokpit, php ^8.5, license AGPL-3.0-only, composer validate --strict passes"
    requirement: "FND-01"
    verification:
      - kind: other
        ref: "composer validate --strict"
        status: pass
    human_judgment: false
  - id: D5
    description: ".gitignore content-ignore rules and test-gitignore.sh verdicts for every storage and bootstrap/cache placeholder, runtime content, Filament assets and the PHPStan cache"
    verification:
      - kind: unit
        ref: "bash scripts/tests/run.sh (test-gitignore.sh: 112 assertions, 0 failed; summary PASS 9 FAIL 0)"
        status: pass
    human_judgment: false

duration: 4min
completed: 2026-10-07
status: complete
commits: 1
plan_head_before: 8c2b4b86b461cef521b0f68379ec77388ddceb69
plan_head_after: 805824b050bc876b226e15ee1e6081b658f36e1f
---

# Phase 2 Plan 01: Platform skeleton Summary

**Laravel 13.35 skeleton with a single Filament 5.10 SPA panel at /admin, all phase packages locked in composer.lock, committed through the Phase 1 hook with only two maintainer-approved path-scoped allowlist entries, and a fresh clone that installs.**

## Performance

- **Duration:** 4 min (resumed dispatch; Tasks 1 and 2 were owner decisions made before it)
- **Started:** 2026-10-07T14:37:08Z
- **Completed:** 2026-10-07T14:41:02Z
- **Tasks:** 3 of 3 (Tasks 1 and 2 decision checkpoints resolved by the owner, Task 3 executed)
- **Files modified:** 51 in the commit (50 excluding composer.lock)

## Accomplishments

- Skeleton merged with the six Phase 1 collisions fixed at their source before the first `git add`: no `composer.lock` allowlist beyond the approved two, no welcome view, SQS queue connection removed, no skeleton README, no agent instruction files, no Node build.
- Filament 5 panel `admin` at `/admin` with `->login()` and `->spa()`; `/` redirects to `/admin`; the panel provider is registered in `bootstrap/providers.php`.
- `.gitignore` now content-ignores `storage/` and `bootstrap/cache/` so all 13 skeleton placeholder `.gitignore` files are tracked; Filament published assets and the PHPStan tmpDir stay ignored.
- `scripts/tests/test-gitignore.sh` gained ignored verdicts (runtime content, Filament assets, caches) and trackable verdicts (every placeholder); 112 assertions pass.
- Fresh clone proof: `git clone` plus `composer install` completes, including the `filament:upgrade` post-autoload script.

## Task Commits

1. **Task 1: Maintainer approves the composer.lock allowlist entries** - no commit (decision checkpoint, resolved: `path-scoped`)
2. **Task 2: Owner chooses the SPDX licence id** - no commit (decision checkpoint, resolved: `agpl-only`)
3. **Task 3: Tracer - Laravel 13 skeleton with Filament 5 merged** - `805824b` (feat)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

## Decisions

Recorded verbatim so plan 02-13 asserts the same licence value.

- **Task 1 (composer.lock allowlist), option id:** `path-scoped`
  - Added to `scripts/sensitive-allowlist.txt`, each preceded by a comment naming this decision:
    - `email ;; ^composer\.lock$ ;; ^[^@ ]+@[^@ ]+$`
    - `public-ip ;; ^composer\.lock$ ;; ^[0-9.]+$`
  - No other allowlist entry was added.
- **Task 2 (SPDX licence id), option id:** `agpl-only`
  - `composer.json` `license` = `AGPL-3.0-only`. Plan 02-13 must assert exactly `AGPL-3.0-only` and must NOT add an "or any later version" grant to the README.

## Resolved package versions

| Package | Version |
|---|---|
| laravel/framework | v13.35.0 |
| filament/filament | v5.10.0 |
| livewire/livewire | v4.4.7 |
| pestphp/pest | v5.3.0 |
| phpunit/phpunit | 13.3.6 |
| larastan/larastan | v3.13.0 |
| brick/money | 0.15.2 |
| brick/math | 1.0.0 |
| laravel/sanctum | v4.3.3 |
| spatie/laravel-permission | 8.3.0 |
| spatie/laravel-medialibrary | 11.23.9 |
| spatie/laravel-tags | 4.12.0 |
| spatie/laravel-activitylog | 5.1.1 |
| spatie/laravel-webhook-client | 3.7.0 |

Skeleton: laravel/laravel v13.11.0 (the skeleton's `phpunit/phpunit ^12.5` constraint was raised to `^13.3` by the Pest 5 install; `nunomaduro/collision` stays at `^8.6`).

## Placeholder .gitignore files found and tracked

`bootstrap/cache/.gitignore`, `database/.gitignore` (sqlite content, not a storage placeholder), `storage/app/.gitignore`, `storage/app/private/.gitignore`, `storage/app/public/.gitignore`, `storage/framework/.gitignore`, `storage/framework/cache/.gitignore`, `storage/framework/cache/data/.gitignore`, `storage/framework/cache/locks/.gitignore`, `storage/framework/sessions/.gitignore`, `storage/framework/testing/.gitignore`, `storage/framework/views/.gitignore`, `storage/logs/.gitignore`.

The plan's minimum list did not include `storage/framework/.gitignore`, `storage/framework/cache/data/.gitignore`, `storage/framework/cache/locks/.gitignore` or `database/.gitignore`; all four ship in the skeleton and are covered by trackable verdicts too.

## Files Created/Modified

- `composer.json`, `composer.lock` - project manifest (kokpit/kokpit, php ^8.5, AGPL-3.0-only) and locked dependency set
- `app/Providers/Filament/AdminPanelProvider.php` - the single panel (admin, /admin, login, SPA)
- `bootstrap/providers.php` - registers the panel provider
- `routes/web.php` - `Route::redirect('/', '/admin')`
- `config/queue.php` - `sync` and `redis` connections only, default `redis`
- `.env.example` - skeleton copy with `QUEUE_CONNECTION=redis` and the Vite line dropped (plan 02-02 rewrites it)
- `.gitignore` - content-ignore rules, ignored Filament assets and PHPStan cache
- `scripts/tests/test-gitignore.sh` - new ignored and trackable verdicts
- `scripts/sensitive-allowlist.txt` - the two approved composer.lock entries
- `.editorconfig`, `.gitattributes`, `phpunit.xml`, `artisan`, `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `routes/`, `storage/`, `tests/TestCase.php` - merged skeleton

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Filament installer appended unanchored asset rules to .gitignore**
- **Found during:** Task 3 (step 3, `filament:install`)
- **Issue:** `filament:install` added `/public/css/filament`, `/public/fonts/filament` and `/public/js/filament` to `.gitignore` in its own order, outside the file's comment convention.
- **Fix:** Replaced them with the trailing-slash directory forms under "Dependencies and build output", with a comment, as step 4 specifies.
- **Files modified:** `.gitignore`
- **Verification:** test-gitignore.sh verdicts for `public/js/filament/forms/forms.js`, `public/css/filament/filament/app.css`, `public/fonts/filament/...`
- **Committed in:** `805824b`

**2. [Rule 1 - Bug] Config rewrite script emptied config/queue.php**
- **Found during:** Task 3 (step 6)
- **Issue:** A scripted regex edit of `config/queue.php` returned null and wrote an empty file (caught immediately; nothing staged).
- **Fix:** Rewrote the file by hand with the `sync` and `redis` connections, `batching` and `failed` kept.
- **Files modified:** `config/queue.php`
- **Verification:** `php artisan route:list` boots, scanner clean, committed content reviewed in `git diff --staged`
- **Committed in:** `805824b`

**3. [Rule 3 - Blocking] Pint reformatted bootstrap/providers.php after filament:install**
- **Found during:** Task 3 (step 7)
- **Issue:** The generated provider list failed Pint's `fully_qualified_strict_types` fixer.
- **Fix:** Ran `vendor/bin/pint` once as the plan prescribes and staged the result.
- **Files modified:** `bootstrap/providers.php`
- **Committed in:** `805824b`

---

**Total deviations:** 3 auto-fixed (1 bug in own tooling, 2 blocking)
**Impact on plan:** None on scope; no extra allowlist entries, no scope creep.

## Issues Encountered

- The secret-read guard blocked one Bash call that ran `sed` against the local `.env`. The local `.env` was instead regenerated with `cp .env.example .env` and `php artisan key:generate`; it stays ignored and unread.
- `resources/views/` is empty after removing the welcome view, so git does not track it; later plans that add views create it.
- `KOKPIT_DENYLIST` was not set in this session, so the scanner ran with generic patterns only (as in the hook). The denylist check is a local-developer control outside the repository.

## Known Stubs

None.

## Threat Flags

None. The new network surface is the Filament login route at `/admin/login`, which the plan's threat model already covers (single panel, authentication hardening lands in plans 02-09 and 02-11).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 02-02 (DDEV, PostgreSQL 18, Redis, RustFS, Pest suites, `.env.example` rewrite, `config/kokpit.php`) can build on this tree. The host has PHP 8.5 with `pdo_pgsql` but no PostgreSQL server; DDEV arrives in 02-02.
- Plans 02-03 and 02-04 must publish and edit the Sanctum, permission, medialibrary, tags, activitylog and webhook-client migrations/config before the first migrate (UUID keys); nothing was published here.
- Plan 02-13 asserts licence `AGPL-3.0-only`.

## Self-Check: PASSED

- composer.json, composer.lock, artisan, AdminPanelProvider.php, routes/web.php, config/queue.php, test-gitignore.sh, sensitive-allowlist.txt: all present and tracked
- Commit `805824b` is an ancestor of HEAD
- `scripts/check-sensitive.sh --all` exit 0, `bash scripts/tests/run.sh` PASS 9 FAIL 0, `composer validate --strict` valid, `admin/login` listed, fresh-clone `composer install` exit 0
- All acceptance criteria of Task 3 re-run and passing

---
*Phase: 02-platform-foundation*
*Completed: 2026-10-07*
