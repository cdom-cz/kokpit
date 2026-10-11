---
phase: quick-261011-ekq
plan: 01
subsystem: storage-and-mail-config
tags: [s3, env, ses, production-guard, media-library]
requires: []
provides:
  - S3_* env keys for the object storage (AWS_* reserved for AWS services)
  - every stored file defaults to the s3 disk, enforced in production
  - Amazon SES mailer with a bounded client
affects: [config/filesystems.php, config/media-library.php, config/mail.php, config/services.php, app/Support/ProductionConfigGuard.php]
tech-stack:
  added: [aws/aws-sdk-php as a direct requirement (already locked at 3.399.2)]
  patterns: [production guard names the env key and never prints a value]
key-files:
  created: [tests/Feature/Operations/AwsCredentialSeparationTest.php]
  modified:
    - config/filesystems.php
    - config/media-library.php
    - config/mail.php
    - config/services.php
    - .env.example
    - .github/workflows/hygiene.yml
    - app/Support/ProductionConfigGuard.php
    - phpunit.xml
    - composer.json
    - composer.lock
    - CONTRIBUTING.md
    - tests/Feature/Repo/EnvExampleTest.php
    - tests/Feature/Repo/CiParityTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - tests/Unit/Support/ProductionConfigGuardTest.php
    - tests/Feature/Auth/TwoFactorEnforcementTest.php
    - tests/Isolation/PanelAccessTest.php
decisions:
  - "Storage uses S3_* names with its own us-east-1 region default so the AWS SDK never takes the AWS_* credentials or region meant for SES"
  - "Media library default disk is s3 (was public); phpunit.xml pins FILESYSTEM_DISK and MEDIA_DISK to local so the suite never writes to the bucket"
  - "ProductionConfigGuard refuses non-s3 default, Filament, media, conversions and Livewire disks, a missing S3 key pair, and SES without the AWS key pair"
  - "SES mailer has http timeout and connect_timeout from MAIL_TIMEOUT (10 s) and retries 1"
metrics:
  tasks: 3
  completed: 2026-10-11
status: complete
actuals:
  tasks: 3
  commits: 3
plan_head_before: ec11d1bad46c89bd94e6b04ea987762daeb4639a
plan_head_after: b60da59e264772fd51a6b9641c81e66eb5a1996d
commits: 3
---

# Phase quick-261011-ekq Plan 01: Prefix S3 storage env vars with S3_ Summary

The object storage now reads only `S3_*` env keys, every stored file lands on the s3 disk (production refuses to boot otherwise), and `MAIL_MAILER=ses` sends through the Amazon SES API on the standard `AWS_*` keys with a bounded client.

## Commits

| Task | Commit | Description |
| ---- | ------ | ----------- |
| 1 | 7303eaf | Rename the storage keys to S3_* (config, .env.example, CI env, deploy checklist, repo tests) |
| 2 | aa48dfa | Media library defaults to s3, phpunit pins, ProductionConfigGuard storage and SES checks |
| 3 | b60da59 | SES mailer with bounded SDK client, direct aws/aws-sdk-php requirement, docs, separation test |

## What changed

- `config/filesystems.php`: s3 disk reads `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY`, `S3_DEFAULT_REGION` (default `us-east-1`), `S3_BUCKET`, `S3_URL`, `S3_ENDPOINT`, `S3_USE_PATH_STYLE_ENDPOINT`; the default disk falls back to s3.
- `.env.example`: active S3_* block with the RustFS development defaults, `FILESYSTEM_DISK=s3` kept; `AWS_*` keys (and `AWS_SES_REGION`) appear only as commented SES keys.
- `config/media-library.php`: `MEDIA_DISK` defaults to s3 (it was the container-local public disk).
- `ProductionConfigGuard`: five new refusals after the CACHE_STORE check (FILESYSTEM_DISK, MEDIA_DISK, LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK, S3 key pair and bucket, SES key pair including failover and roundrobin members). Messages name the env key only and never a value.
- `config/mail.php` / `config/services.php`: SES mailer with `http.timeout`, `http.connect_timeout` (`MAIL_TIMEOUT`) and `retries` 1; `services.ses.region` reads `AWS_SES_REGION`, then `AWS_DEFAULT_REGION`, then `us-east-1`.
- `composer.json`: `aws/aws-sdk-php:^3.399` is now a direct requirement.
- CI: tests job env `S3_ENDPOINT`; CONTRIBUTING.md deploy checklist and Storage paragraph updated.

## Verification

- Full Pest suite: 2657 passed, 0 failed (run with the S3_* keys of `.env.example` as process env, in DDEV, including the s3 group against RustFS and both subprocess production boots).
- PHPStan: no errors. Pint: clean on every touched file. `scripts/tests/test-docs.sh`, `scripts/tests/test-workflow.sh`, actionlint and zizmor: clean.
- `composer check-licenses`, `composer validate`, `composer audit --locked`: clean. `aws/aws-sdk-php` is still locked at 3.399.2.
- `scripts/check-sensitive.sh` clean before each commit; lefthook (sensitive-content, gitleaks) passed on all three commits, never bypassed.

## Deviations from Plan

**1. [Rule 3 - Blocking/cleanliness] composer.lock phpdotenv URL churn reverted**
- **Found during:** Task 3
- **Issue:** `composer update --lock` also rewrote the phpdotenv repository and zipball URLs (upstream rename, same version and reference), so the lock diff was not limited to content-hash.
- **Fix:** Restored those two URL pairs by hand; the final diff is the content-hash only. No package version changed.
- **Files modified:** composer.lock
- **Commit:** b60da59

**2. Note on .env.example precondition:** `FILESYSTEM_DISK=s3` was already committed (`ec11d1b`) before this plan started, so the file had no uncommitted owner change; the commit simply keeps the line.

Otherwise the plan was executed as written.

## Owner follow-ups

1. Rename the storage keys in your local `.env`: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_USE_PATH_STYLE_ENDPOINT`, `AWS_ENDPOINT` and `AWS_URL` become their `S3_*` counterparts. Set `FILESYSTEM_DISK=s3`. The executor did not touch `.env`; the DDEV checks ran with the S3_* keys of `.env.example` as process env.
2. Before deploying this version to Zerops, rename the storage variables in the Zerops UI to `S3_*` and remove the old `AWS_*` storage variables. Otherwise the guard refuses to boot and the deploy stops while the previous version keeps serving. Leftover `AWS_*` storage values would also be read as SES credentials.
3. Optional SES setup: `MAIL_MAILER=ses`, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` (secret), and `AWS_DEFAULT_REGION` or `AWS_SES_REGION`.

## Note for the Documents phase

Livewire temporary uploads follow the default disk, so on s3 the browser uploads directly through a presigned URL. An s3 temporary upload disk therefore needs a browser-reachable endpoint (in DDEV the container endpoint `http://rustfs:9000` is not reachable from the browser), the same constraint as for downloads.

## Known Stubs

None.

## Threat Flags

None. No new network endpoint or trust boundary beyond the SES API client planned in the threat model.

## Self-Check: PASSED

- Created file `tests/Feature/Operations/AwsCredentialSeparationTest.php` exists.
- Commits 7303eaf, aa48dfa and b60da59 are on HEAD.

## Follow-up: guard reopened

The owner asked to keep `ProductionConfigGuard` open: neither S3 nor AWS configuration may be a boot condition. The five storage and SES refusals were removed again (guard, its tests, the two production-boot tests and the matching CONTRIBUTING.md text restored to the state before this task). Consequences: follow-up 2 (renaming the Zerops variables) is no longer enforced by the guard, but leftover `AWS_*` storage values would still be read as SES credentials, so the rename remains advisable.
