---
phase: 02-platform-foundation
plan: 04
subsystem: database
tags: [uuid-v7, sanctum, medialibrary, spatie-tags, activitylog, webhook-client, notifications, morph-map, pest, postgresql-18]

requires:
  - phase: 02-platform-foundation
    provides: schema rules R1 to R8, MorphMap, KokpitModel, ModelConventionsServiceProvider, PgSchema and ModelRules (plan 02-03)
provides:
  - personal_access_tokens, notifications, media, tags, taggables, activity_log and webhook_calls on uuid keys with uuidv7() defaults, uuid morph columns and timestamptz
  - HasUuids subclasses PersonalAccessToken (Identity) and Media, Tag, Activity, WebhookCall (Shared), each registered with its package
  - User uses Sanctum HasApiTokens (tokens are used from Phase 7)
  - Morph map with eight aliases and rule R9 (every stored morph type is a morph-map key) over every morph column found in the catalogue
  - PackageProbe and ProbeNotification test support, PackageModelsTest with one row per package public API
affects: [02-05, 02-09, 02-10, 02-11, phase-03, phase-07, phase-11]

actuals:
  tokens: 13900
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "Every package model is a HasUuids subclass registered in the package config; the base class is never referenced"
    - "Published package migrations are edited in place before the first migrate; uuid('id')->primary()->default(DB::raw('uuidv7()')), uuidMorphs and timestampsTz"
    - "Test-only host model (PackageProbe) provisions its table inside the test transaction and merges its alias into the morph map for that test only"
    - "R9 reads every <x>_type column that has an <x>_id sibling from pg_catalog, so a later morph column is checked without being listed"

key-files:
  created:
    - app/Domain/Identity/Models/PersonalAccessToken.php
    - app/Domain/Shared/Models/Media.php
    - app/Domain/Shared/Models/Tag.php
    - app/Domain/Shared/Models/Activity.php
    - app/Domain/Shared/Models/WebhookCall.php
    - config/media-library.php
    - config/tags.php
    - config/activitylog.php
    - config/webhook-client.php
    - database/migrations/2026_10_07_151910_create_personal_access_tokens_table.php
    - database/migrations/2026_10_07_151911_create_notifications_table.php
    - database/migrations/2026_10_07_152542_create_media_table.php
    - database/migrations/2026_10_07_152543_create_tag_tables.php
    - database/migrations/2026_10_07_152629_create_activity_log_table.php
    - database/migrations/2026_10_07_152631_create_webhook_calls_table.php
    - tests/Support/Probes/PackageProbe.php
    - tests/Support/Probes/ProbeNotification.php
    - tests/Feature/Schema/PackageModelsTest.php
  modified:
    - app/Domain/Identity/Models/User.php
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/ModelConventionsServiceProvider.php
    - tests/Feature/Schema/SchemaConventionsTest.php
    - tests/Support/PgSchema.php
    - .env.example

key-decisions:
  - "Published migration names: 2026_10_07_151910_create_personal_access_tokens_table, 2026_10_07_151911_create_notifications_table, 2026_10_07_152542_create_media_table, 2026_10_07_152543_create_tag_tables, 2026_10_07_152629_create_activity_log_table, 2026_10_07_152631_create_webhook_calls_table"
  - "Final morph map (8 aliases): user, role, permission, personal_access_token, media, tag, activity, webhook_call"
  - "Str::createUuidsUsing(Uuid::uuid7()) in ModelConventionsServiceProvider makes every framework generated uuid version 7, because the notification sender calls Str::uuid() (version 4)"
  - "The webhook-client add_attachments upgrade migration is not kept: the create stub already has the attachments column"
  - "The activity log and webhook payload content policies stay with Phases 3 and 11 (T-02-15 transfer)"

patterns-established:
  - "Package models: subclass with HasUuids, register in config, one MorphMap line, one entry in packageModelRegistry() (R8)"
  - "PackageProbe::provision() and PackageProbe::restoreMorphMap() for tests that need a media or tag host"

requirements-completed: [FND-02, FND-03]

coverage:
  - id: D1
    description: "Sanctum tokens have the form <uuid v7>|<secret>, findToken resolves the owner through the registered UUID subclass, tokenable_type stores 'user', and a wrong secret is rejected"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/PackageModelsTest.php (Sanctum tokens, token row and wrong secret cases)"
        status: pass
    human_judgment: false
  - id: D2
    description: "A database notification row has a version 7 id, uuid notifiable_id and notifiable_type 'user'"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/PackageModelsTest.php#stores one database notification with a version 7 id and the user alias"
        status: pass
    human_judgment: false
  - id: D3
    description: "Media and tag rows created through a test-only host model have version 7 ids and the host alias; taggables use uuid tag keys and cascade when a tag is deleted"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/PackageModelsTest.php (media, tag and taggables cascade cases)"
        status: pass
    human_judgment: false
  - id: D4
    description: "An activity with a user as subject and causer stores 'user' and uuid keys, a causer-less activity is allowed, and a webhook call has a version 7 id through the configured model"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/PackageModelsTest.php (activity, causer-less activity and webhook call cases)"
        status: pass
    human_judgment: false
  - id: D5
    description: "R8 covers all seven package models (roles, permissions, media, tags, activities, tokens and every webhook-client config entry); R9 proves every stored morph type is a morph-map key after an exercising seed, with self-checks that each rule can fail"
    requirement: "FND-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/SchemaConventionsTest.php#R8 and #R9 plus their self-checks"
        status: pass
    human_judgment: false
  - id: D6
    description: "The catalogue schema test (R1 to R6) passes over every package table with no new exempt entry"
    requirement: "FND-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Schema/SchemaConventionsTest.php#R1 to #R6, exempt map unchanged at four entries"
        status: pass
    human_judgment: false

duration: 11min
completed: 2026-10-07
status: complete
commits: 5
plan_head_before: f0ab3e32271ac2b71af58bd26c99e0f0865cdd0c
plan_head_after: c23c2a5acbe714404fff583290dc295761255747
---

# Phase 2 Plan 04: Package tables on UUID v7 Summary

**Sanctum tokens, database notifications, media, tags, the activity log and webhook calls moved onto UUID v7 keys, uuid morph columns and timestamptz through edited published migrations and five registered HasUuids subclasses, every one exercised through its package's own API, with rule R8 covering all seven package models and a new catalogue-driven rule R9 that every stored morph type is a morph-map alias.**

## Performance

- **Duration:** 11 min
- **Started:** 2026-10-07T15:18:00Z (approximate; not recorded at start)
- **Completed:** 2026-10-07T15:29:00Z
- **Tasks:** 3 of 3
- **Files modified:** 24 changed between plan start and end (18 created, 6 modified; 6 migrations, 5 models, 4 package configs)

## Accomplishments

- Six published migrations edited before they ever ran: `uuid('id')->primary()->default(DB::raw('uuidv7()'))`, `uuidMorphs` / `nullableUuidMorphs`, `foreignUuid('tag_id')` with cascade, `timestampTz` / `timestampsTz`. The catalogue test R1 to R6 passes over all of them with the exempt map still at four framework entries.
- `PersonalAccessToken`, `Media`, `Tag`, `Activity` and `WebhookCall` are `HasUuids` subclasses registered through `Sanctum::usePersonalAccessTokenModel()` and the four package config keys; `User` gains `HasApiTokens`. R8 now checks seven package models (roles, permissions, tokens, media, tags, activities and each `webhook-client` config entry).
- A token created through the Sanctum API reads `<uuid v7>|<secret>`, `findToken` resolves the same owner, and a wrong secret returns null (T-02-13).
- One row per package API (token, notification, media on a fake disk, tag attachment, activity with and without causer, webhook call) has a version 7 string id and, where a morph column exists, the alias as type.
- Rule R9 reads every `<x>_type` column with an `<x>_id` sibling from `pg_catalog`, runs an exercising seed (role, direct permission, token, notification, media, tag, activity) and asserts each distinct stored value is a key of `Relation::morphMap()`; it also asserts the eight expected columns were actually populated so the rule cannot pass vacuously.

## Final morph map

| Alias | Class |
|---|---|
| `user` | `App\Domain\Identity\Models\User` |
| `role` | `App\Domain\Identity\Models\Role` |
| `permission` | `App\Domain\Identity\Models\Permission` |
| `personal_access_token` | `App\Domain\Identity\Models\PersonalAccessToken` |
| `media` | `App\Domain\Shared\Models\Media` |
| `tag` | `App\Domain\Shared\Models\Tag` |
| `activity` | `App\Domain\Shared\Models\Activity` |
| `webhook_call` | `App\Domain\Shared\Models\WebhookCall` |

Published migrations (all under `database/migrations/`): `2026_10_07_151910_create_personal_access_tokens_table.php`, `2026_10_07_151911_create_notifications_table.php`, `2026_10_07_152542_create_media_table.php`, `2026_10_07_152543_create_tag_tables.php`, `2026_10_07_152629_create_activity_log_table.php`, `2026_10_07_152631_create_webhook_calls_table.php`.

## Task Commits

1. **Task 1: Tracer, Sanctum tokens and database notifications on UUID v7 keys** - `1a0ec66` (feat)
2. **Task 2 RED: failing media, tags and R8 registry tests** - `569de7f` (test)
3. **Task 2 GREEN: medialibrary and tags under the conventions** - `b4bbbee` (feat)
4. **Task 3 RED: failing activity log, webhook call and R9 tests** - `a928a18` (test)
5. **Task 3 GREEN: activity log and webhook calls under the conventions, R9 satisfied** - `c23c2a5` (feat)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

Tracer feedback gate: auto mode was active (`_auto_chain_active`); the tracer `<verify>` commands (Schema suite 37 passed, Pint clean, Larastan `[OK]`) passed on the exact tree that was committed, and the full suite was green again at every later task, so execution expanded.

## TDD Gate Compliance

Tasks 2 and 3 (`tdd="true"`): RED commits `569de7f` and `a928a18` precede GREEN commits `b4bbbee` and `c23c2a5`. No REFACTOR commit was needed.

- **Task 2 RED:** 4 failed, 36 passed. The target tests failed on the planned behavior: `relation "media" does not exist` and `relation "tags" does not exist` for the media, tag and taggables-cascade cases, and R8 reporting `Spatie\MediaLibrary\...\Media` and `Spatie\Tags\Tag` as registered instead of the subclasses. A first RED run failed on `DiskDoesNotExist` (the fake disk name was not in `filesystems.disks`); that was a test fixture fault, so it was fixed (use the configured `local` disk faked) and RED was re-run before committing.
- **Task 3 RED:** 7 failed, 39 passed (R9 cases passed). The target tests failed on the planned behavior: `Class App\Domain\Shared\Models\Activity does not exist`, `Class ...WebhookCall not found`, R8 reporting the two unregistered base models, and R1, R2 and R4 reporting `activity_log.id`, `webhook_calls.id` and the four stock `created_at`/`updated_at` columns because the stock published migrations were on disk (left out of the RED commit, edited in GREEN, as in 02-03).
- **R9 at RED:** R9 and its two self-checks passed at RED. That is expected: the morph map has been enforced since 02-03, so every stored type was already an alias; `Schema::morphUsingUuids()` also already turned the stock morph columns into uuid. The self-checks prove R9 can fail (a synthetic `App\Models\Legacy` value is reported).
- **Semantic assessment:** every counted failure was the planned behavior missing, not a syntax, discovery or fixture fault. The `gsd_run check tdd-red-evidence` classifier was not run: `tdd_mode` is not enabled and Pest's console output is not one of its supported report formats (as in 02-02 and 02-03).
- **GREEN:** the full suite passes (67 tests, 1198 assertions); `composer lint` and `composer stan` clean.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] Framework-generated uuids made version 7**
- **Found during:** Task 1 (notifications)
- **Issue:** the plan's truth requires the database notification id to be a version 7 UUID, but `NotificationSender` assigns `Str::uuid()`, which is version 4, so the uuidv7() column default is never used for notifications.
- **Fix:** `Str::createUuidsUsing(static fn () => Uuid::uuid7())` in `ModelConventionsServiceProvider::boot()`. Every framework-generated uuid (notification ids, queue job uuids) is now version 7. A first attempt calling `Str::uuid7()` inside the factory recursed infinitely, because `Str::uuid7()` itself consults the factory; the ramsey `Uuid::uuid7()` is called directly instead.
- **Files modified:** `app/Providers/ModelConventionsServiceProvider.php`
- **Verification:** `PackageModelsTest` notification case asserts the V7 pattern.
- **Committed in:** `1a0ec66`

**2. [Rule 3 - Blocking] `WEBHOOK_CLIENT_SECRET` added to `.env.example`**
- **Found during:** Task 3 (GREEN, full suite)
- **Issue:** the published `config/webhook-client.php` reads `env('WEBHOOK_CLIENT_SECRET')` without a default, so `EnvExampleTest` (the repository rule from 02-02) failed.
- **Fix:** documented the key as a commented, empty entry in the Kokpit section; no value, no secret.
- **Files modified:** `.env.example`
- **Verification:** `EnvExampleTest` and the full suite pass.
- **Committed in:** `c23c2a5`

**3. [Rule 1 - Bug] Webhook call test created through `WebhookCall::create` instead of `storeWebhook`**
- **Found during:** Task 3 (GREEN)
- **Issue:** `new WebhookConfig(config(...))` throws `InvalidConfig` because the stock `process_webhook_job` is an empty string until Phase 11 supplies a job class; the test was wrong, not the production code.
- **Fix:** the test creates the row through the model class resolved from `config('webhook-client.configs.0.webhook_model')`, which still proves registration and uuid keys.
- **Files modified:** `tests/Feature/Schema/PackageModelsTest.php`
- **Committed in:** `c23c2a5`

### Plan-reading notes (not deviations)

- The medialibrary publish also copied Blade views to `resources/views/vendor/media-library`; the plan asked only for the migration and config, so the views were deleted before staging.
- The webhook-client provider publishes a second migration, `add_attachments_to_webhook_calls_table`, which exits early when the column exists. The create stub already carries `attachments`, so only the create migration is kept.
- `packageModelRegistry()` in `SchemaConventionsTest.php` now holds the already-resolved registered class instead of a config key, so the Sanctum entry (`Sanctum::$personalAccessTokenModel`) and the per-config webhook entries fit the same shape.
- `PackageProbe` gained `provision()` and `restoreMorphMap()` so `PackageModelsTest` and the R9 seed share one setup path.
- Pint reformatted the published configs and migrations (strict types, import ordering), as in 02-03.

---

**Total deviations:** 3 auto-fixed (1 missing-critical, 1 blocking, 1 test bug)
**Impact on plan:** None on scope; the global uuid factory is a small cross-cutting effect worth knowing about (see Next Phase Readiness).

## Issues Encountered

- The first `pint` plus `pest` call exceeded the 120 s tool timeout and moved to the background; later calls were split and finished normally.
- `sed -i ''` semantics on macOS broke one bulk rename; the two occurrences were edited directly instead.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-02-13 (token spoofing through integer-cast keys) is mitigated by the registered `HasUuids` token subclass, the v7 prefix assertion and the `findToken` owner and wrong-secret tests. T-02-14 (media, tags, activity and webhook rows readable by a Partner) stays open for 02-10 and 02-11 as planned; no panel surface reads these models in this plan. T-02-15 (log and payload content) is transferred to Phases 3 and 11 as planned.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 02-05 onwards can rely on every package table following the conventions; any later package table is checked by R1 to R6 and any later morph column by R3 and R9 without listing it.
- `Str::createUuidsUsing` makes all `Str::uuid()` callers version 7. A test that calls `Str::freezeUuids()` and then `createUuidsNormally()` would reset the factory to version 4 for the rest of the process; none exists, and the provider re-applies it on each application boot.
- 02-10 must mark `Media`, `Tag`, `Activity` and `WebhookCall` deny-all for Partners (`DeniesPartners`) and 02-11 must cover them in the canary registry (T-02-14).
- `ddev composer test` (67 passed), `lint` and `stan` are green; DDEV containers are left running.

## Self-Check: PASSED

- Created files exist: the five models, the four package configs, the six migrations, `PackageProbe`, `ProbeNotification`, `PackageModelsTest`; the unneeded `resources/views/vendor/media-library` and `add_attachments_to_webhook_calls_table` migration are gone.
- Commits `1a0ec66`, `569de7f`, `b4bbbee`, `a928a18`, `c23c2a5` are ancestors of HEAD; `git rev-list --count f0ab3e3..HEAD` is 5.
- All acceptance criteria of Tasks 1, 2 and 3 re-run and passing; `ddev composer test`, `lint`, `stan` and `scripts/check-sensitive.sh` clean.
