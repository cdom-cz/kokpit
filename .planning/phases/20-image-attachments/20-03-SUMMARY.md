---
phase: 20-image-attachments
plan: 03
subsystem: deploy
tags: [gd, deploy-verify, zerops, readiness-gate, images]
requires: []
provides:
  - "App\\Domain\\Knowledge\\Images\\GdCapability (REQUIRED, missing())"
  - "fifth readiness check 'gd' of kokpit:deploy:verify"
  - "php85-gd in the Zerops run image"
affects: [20-image-attachments]
tech-stack:
  added: []
  patterns: ["injectable probe with a constructor test seam; check bound through the container"]
key-files:
  created:
    - app/Domain/Knowledge/Images/GdCapability.php
    - tests/Unit/Knowledge/GdCapabilityTest.php
  modified:
    - app/Console/Commands/DeployVerifyCommand.php
    - lang/cs/kokpit.php
    - tests/Feature/Operations/DeployVerifyCommandTest.php
    - zerops.yml
decisions:
  - "R-20-03-1..4 applied as planned; composer.json and composer.lock deliberately untouched in the worktree (post-merge step R-20-03-5)"
metrics:
  duration: "about 15 min"
  completed: 2026-11-27
status: complete
requirements: [IMG-02]
actuals:
  tokens: 6500
  tasks: 2
  commits: 2
plan_head_before: 8df70277797651f08d29980eecf394b528869a69
plan_head_after: c2a7ccce9014f684da14322729f5d709147e7b7b
---

# Phase 20 Plan 03: GD readiness gate Summary

A deploy whose PHP lacks GD, or whose GD cannot read and write PNG, JPEG, WebP and GIF, now fails `kokpit:deploy:verify` (a fifth check) instead of failing on the first image upload; the run image installs `php85-gd`.

## What was built

- `GdCapability` (`final`, pure): `REQUIRED` maps the five `gd_info()` keys to PNG, JPEG, WebP, GIF, GIF; `missing()` returns `['GD']` when the extension is absent, otherwise the unique format names whose key is not exactly `true`, in `REQUIRED` order. The constructor takes an optional info array as a test seam.
- `DeployVerifyCommand`: new `gd` check after the collation check, resolved through `app(GdCapability::class)` so tests can bind a stub; the failure reason names only the missing formats. Class docblock and `$description` mention the fifth check.
- `lang/cs/kokpit.php`: `steps.gd`, `gd_missing`, and `passed` now says all five checks passed.
- Tests: `GdCapabilityTest` (complete, WebP missing, GIF missing once, GIF half-missing, empty list, non-`true` values, real `gd_info()`); `DeployVerifyCommandTest` updated to five ok lines plus a failing case through a bound `GdCapability`.
- `zerops.yml`: `php85-gd` added to the `apk add` line (the only change).

## Tasks and commits

| Task | Name | Commit |
|------|------|--------|
| 1 (tracer) | GD check in the readiness gate | ece7ee01 |
| 2 | php85-gd in the run image | c2a7ccce |

## Deviations from Plan

None - plan executed as written. The tracer gate (re-running the Pest tests) could not run in the worktree (no vendor directory, no DB; project rule forbids the suite there); `php -l` on every PHP file and the greps passed, and the Pest run is deferred to the post-merge step below.

## Post-merge steps still required (on the HEAD branch, through DDEV)

1. `ddev composer require "ext-gd:*"`, then `ddev composer validate --no-check-publish`; review `git diff composer.json composer.lock` (platform entry and content-hash only; record the summary); `scripts/check-sensitive.sh composer.json composer.lock`; commit as `build(deps): declare the ext-gd platform requirement` through the hook. Not done in the worktree by design (R-20-03-5), so acceptance criterion `grep -cF '"ext-gd"' composer.json` = 1 is open until then.
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge/GdCapabilityTest.php tests/Feature/Operations/DeployVerifyCommandTest.php tests/Feature/Repo/ZeropsConfigTest.php` (no `--parallel`).
3. Run Pint on the five PHP files (not available in the worktree).

## Open assumptions

A1 (WebP support in the Zerops GD) and A7 (Alpine package name `php85-gd`) stay open until the first deploy rehearsal prints 'Knihovna obrázků GD: v pořádku' and 'všech pět kontrol prošlo' with no unknown-package error from `apk add`. The new check fails that deploy if either is wrong.

## Known Stubs

None.

## Threat Flags

None. T-20-09 mitigated (readiness check plus explicit package); T-20-10 accepted (no registry download).

## Self-Check: PASSED

- FOUND: app/Domain/Knowledge/Images/GdCapability.php, tests/Unit/Knowledge/GdCapabilityTest.php
- FOUND commits: ece7ee01, c2a7ccce
