---
phase: quick-261011-ekq
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - config/filesystems.php
  - .env.example
  - .github/workflows/hygiene.yml
  - tests/Feature/Repo/EnvExampleTest.php
  - tests/Feature/Repo/CiParityTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - CONTRIBUTING.md
  - config/media-library.php
  - phpunit.xml
  - app/Support/ProductionConfigGuard.php
  - tests/Unit/Support/ProductionConfigGuardTest.php
  - tests/Feature/Auth/TwoFactorEnforcementTest.php
  - tests/Isolation/PanelAccessTest.php
  - composer.json
  - composer.lock
  - config/mail.php
  - config/services.php
  - tests/Feature/Operations/AwsCredentialSeparationTest.php
autonomous: true
requirements: [STORAGE-S3-ENV, STORAGE-ALL-S3, MAIL-SES]

estimate:
  tokens: 85000
  raw_tokens: 85000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "The s3 disk reads its settings only from the S3_-prefixed keys (key pair, region with a us-east-1 default, bucket, URL, endpoint, path-style flag). No config key of the object storage reads an AWS_* variable."
    - "The s3 Pest group (tests/Feature/Operations/StorageCheckTest.php) passes against RustFS in DDEV with the S3_* values from .env.example. The CI tests job points S3_ENDPOINT at its RustFS service."
    - ".env.example sets FILESYSTEM_DISK=s3 (the owner's uncommitted change, kept) and the S3_* block with the RustFS development defaults. AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION and AWS_SES_REGION appear only as commented keys for the SES mailer."
    - "Every file the application stores lands on the s3 disk: the default disk falls back to s3, the media library defaults to s3 (MEDIA_DISK), and Filament uploads and Livewire temporary uploads follow the default disk."
    - "In production the application refuses to boot unless all of these hold: the default disk and Filament's default disk are s3; the media library disk is s3; the conversions disk and the Livewire temporary upload disk are unset or s3; the s3 disk has a key, a secret and a bucket. Each refusal names the env key to fix and never prints a credential."
    - "MAIL_MAILER=ses sends through the Amazon SES API. Its client uses AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, and its region is AWS_SES_REGION, falling back to AWS_DEFAULT_REGION, then us-east-1. HTTP timeout, connect timeout and retries are bounded. Production refuses an SES mailer without the AWS key pair."
    - "aws/aws-sdk-php is a direct requirement in composer.json. Its locked version is unchanged, and composer check-licenses passes."
    - "The full Pest suite, Pint, PHPStan and scripts/check-sensitive.sh pass. Each task is committed through the lefthook hook, which is never bypassed."
  artifacts:
    - path: "config/filesystems.php"
      provides: "s3 disk on S3_* keys; the default disk falls back to s3"
      contains: "S3_ACCESS_KEY_ID"
    - path: ".env.example"
      provides: "Active S3_* block with the RustFS development defaults; commented AWS_* keys for SES"
      contains: "S3_ENDPOINT"
    - path: "config/media-library.php"
      provides: "Media library disk defaults to s3"
      contains: "MEDIA_DISK"
    - path: "app/Support/ProductionConfigGuard.php"
      provides: "Production refusals for local storage, a missing S3 key pair and SES without the AWS key pair"
      contains: "S3_ACCESS_KEY_ID"
    - path: "config/mail.php"
      provides: "SES mailer with a bounded AWS SDK client"
      contains: "'ses'"
    - path: "config/services.php"
      provides: "SES credentials on the standard AWS_* names with an AWS_SES_REGION override"
      contains: "AWS_SES_REGION"
    - path: "tests/Feature/Operations/AwsCredentialSeparationTest.php"
      provides: "Proof that S3_* feeds the storage, AWS_* feeds SES, and neither leaks into the other"
      contains: "config:show"
  key_links:
    - from: ".env.example S3_* block"
      to: "config/filesystems.php s3 disk"
      via: "env('S3_...') reads, enforced both ways by tests/Feature/Repo/EnvExampleTest.php"
      pattern: "S3_"
    - from: ".github/workflows/hygiene.yml tests job env S3_ENDPOINT"
      to: "RustFS service of the tests job"
      via: "CiParityTest compares the key pair and the endpoint with .env.example"
      pattern: "S3_ENDPOINT"
    - from: "config/mail.php ses mailer"
      to: "config/services.php ses credentials"
      via: "MailManager::createSesTransport merges services.ses under the mailer array and hands it to SesClient"
      pattern: "services.ses"
    - from: "app/Support/ProductionConfigGuard.php"
      to: "AppServiceProvider::boot()"
      via: "ProductionConfigGuard::check(isProduction, config)"
      pattern: "ProductionConfigGuard::check"
---

<objective>
Rename the object storage env keys from the AWS_* names to S3_* names: S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY, S3_DEFAULT_REGION, S3_BUCKET, S3_USE_PATH_STYLE_ENDPOINT, S3_ENDPOINT, plus the optional S3_URL. The storage is S3-compatible but not hosted at AWS. With the old names, its key pair would clash with real AWS credentials. Make sure every stored file lands on the s3 disk, and have production refuse to boot when it would not. Add the option to send mail through the Amazon SES API, using the standard AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and AWS_DEFAULT_REGION.

Purpose: two key pairs must never be confused. The AWS SDK falls back to AWS_* environment credentials and to the AWS region whenever explicit settings are missing. If the storage kept the AWS_* names, an SES key pair would also sign storage requests, and the reverse would happen too. On Zerops, container filesystems are replaced on every deploy and are not shared between containers. A file written to a local or public disk is therefore lost, or served without authorisation.

Output: a renamed storage config, an s3 default for every file store, production guard checks, an SES mailer with bounded timeouts, a direct aws/aws-sdk-php requirement, updated docs and tests. Three atomic commits.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@config/filesystems.php
@config/mail.php
@config/services.php
@app/Support/ProductionConfigGuard.php
@.env.example

Facts verified at planning time:
- Storage reads the AWS_* names only in config/filesystems.php (s3 disk, lines 52-64). config/services.php `ses` already reads AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and AWS_DEFAULT_REGION (default us-east-1). config/mail.php already has an `ses` mailer that only sets `transport`. config/cache.php `dynamodb` also reads AWS_*; it is a real AWS service and is not used (cache is redis), so leave it.
- aws/aws-sdk-php 3.399.2 is already in composer.lock, pulled in by league/flysystem-aws-s3-v3. Its licence, Apache-2.0, is on the allowlist in scripts/check-licenses.php. composer.json has `sort-packages: true`.
- config/media-library.php: `disk_name` => env('MEDIA_DISK', 'public'), `conversions_disk_name` => env('MEDIA_CONVERSIONS_DISK', null). The public disk is a container-local directory under storage/app/public.
- Filament reads `filament.default_filesystem_disk` => env('FILESYSTEM_DISK', 'local') itself (vendor/filament/support/config/filament.php:44). Livewire temporary uploads use `livewire.temporary_file_upload.disk` (env LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK) and fall back to filesystems.default on an empty value. On an s3 disk the browser uploads directly through a presigned URL (vendor/livewire/livewire/src/Features/SupportFileUploads/WithFileUploads.php:15). In unit tests Livewire uses the `tmp-for-tests` disk.
- MailManager::createSesTransport (vendor/laravel/framework/src/Illuminate/Mail/MailManager.php:257) merges `services.ses`, then version/service, then the mailer array (without `transport`). It passes the result to `new SesClient(...)` after addSesCredentials, which sets credentials only when both key and secret are non-empty. Otherwise the SDK uses its default credential chain, which includes AWS_* environment variables. SesTransport::ses() (Transport/SesTransport.php:118) returns the client. `http` and `retries` are valid AWS SDK client arguments (vendor/aws/aws-sdk-php/src/ClientResolver.php:250, 291).
- ProductionConfigGuard is called from AppServiceProvider::boot(). The production-boot tests are: tests/Unit/Support/ProductionConfigGuardTest.php (guardConfig() helper); tests/Feature/Auth/TwoFactorEnforcementTest.php, which has in-process cases at lines 183-226 and a subprocess `$run` closure at line 233; tests/Isolation/PanelAccessTest.php, which has a subprocess production canary case at line 338. Symfony Process children inherit the parent env, including the phpunit.xml `<env>` entries, and read the developer's .env.
- tests/Feature/Repo/EnvExampleTest.php parses `^#?\s*([A-Z][A-Z0-9_]*)=` as a documented key. It checks both directions: every documented key, active or commented, is read by some config env() call; every env() call without a default is documented. tests/Feature/Repo/RepositoryFilesTest.php:401-405 requires each listed production variable in backticks in CONTRIBUTING.md and documented in .env.example. tests/Feature/Repo/CiParityTest.php:140-178 ties the RustFS key pair, the CI endpoint and the path-style flag to .env.example.

Execution rules:
- Run every PHP and composer command inside DDEV (`ddev exec ...`, `ddev composer ...`). The host has no database. RustFS must run in DDEV for the s3 group.
- Never read, print or edit `.env`. A secret-read guard blocks it, and it holds the owner's values. The developer's .env still carries the old AWS_* storage names, so every DDEV test command in this plan passes the S3_* keys of .env.example as process env: `ddev exec 'env $(grep -E "^S3_" .env.example | xargs) ...'`. Renaming the keys in the local .env is an owner follow-up recorded in the SUMMARY.
- Fictional data only (CLAUDE.md). Assemble credential-like test values at runtime from fragments, for example implode('-', ['example', 'storage', 'key']), so no single line looks like a real value. Region names such as eu-central-1 are fine. Never write a real provider hostname.
- Commit procedure before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. lefthook runs the checker and gitleaks; never bypass it (no --no-verify, no LEFTHOOK=0). Commit prefixes: `feat(quick-261011-ekq): ...` for code and config, `docs(quick-261011-ekq): ...` for documentation-only commits. End each message with the attribution lines from the session's system reminder.
- Do not touch historical planning docs (.planning/phases, .planning/research). They describe the state at their time.
</context>

<tasks>

<task type="tracer">
  <name>Task 1: Rename the object storage keys to S3_* end to end (.env.example, config, CI, tests, deploy checklist)</name>
  <precondition>DDEV runs with the RustFS service (`ddev describe` lists rustfs) so the s3 Pest group can reach http://rustfs:9000</precondition>
  <files>config/filesystems.php, .env.example, .github/workflows/hygiene.yml, tests/Feature/Repo/EnvExampleTest.php, tests/Feature/Repo/CiParityTest.php, tests/Feature/Repo/RepositoryFilesTest.php, CONTRIBUTING.md</files>
  <read_first>config/filesystems.php; .env.example; .github/workflows/hygiene.yml lines 125-170 (tests job services and env); tests/Feature/Repo/EnvExampleTest.php; tests/Feature/Repo/CiParityTest.php lines 140-178; tests/Feature/Repo/RepositoryFilesTest.php lines 394-408; CONTRIBUTING.md lines 255-275</read_first>
  <action>
Seven files change; every edit is a mechanical rename plus small test additions.

1. config/filesystems.php, `s3` disk:
   - key reads S3_ACCESS_KEY_ID, secret S3_SECRET_ACCESS_KEY, bucket S3_BUCKET, url S3_URL and endpoint S3_ENDPOINT.
   - region reads S3_DEFAULT_REGION with the default 'us-east-1'. Without a default, the AWS SDK fills a missing region from AWS_REGION or AWS_DEFAULT_REGION, which now belong to SES.
   - use_path_style_endpoint reads S3_USE_PATH_STYLE_ENDPOINT and keeps the default false.
   - Keep `'throw' => true`, `'report' => false` and the existing comment.
   - Add a short comment above the disk: the storage is S3-compatible, not AWS. Its keys carry the S3_ prefix so the AWS SDK never mixes them with the AWS_* credentials of AWS services such as SES.
   - Change the default-disk fallback from 'local' to 's3' (`env('FILESYSTEM_DISK', 's3')`).
   - Leave config/cache.php as it is.

2. .env.example:
   - Keep the owner's uncommitted `FILESYSTEM_DISK=s3` line.
   - In the object storage section, rename the six active keys to S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY, S3_DEFAULT_REGION, S3_BUCKET, S3_USE_PATH_STYLE_ENDPOINT and S3_ENDPOINT. Keep the same values and the same order; they are the public RustFS development defaults.
   - Extend the section comment: the S3_* names are deliberate, because the storage is S3-compatible but not AWS.
   - In the trailing list of optional framework keys, replace the commented AWS_URL key with a commented S3_URL key.
   - At the end of the Mail section, after MAIL_FROM_NAME, add a commented AWS block: one or two prose comment lines saying the standard AWS_* names belong to AWS services only (the SES mailer) and the object storage never reads them; then the commented keys AWS_ACCESS_KEY_ID (empty), AWS_SECRET_ACCESS_KEY (empty) and AWS_DEFAULT_REGION (value us-east-1).
   - No active AWS_* line may remain, so a .env copied from the template never sets AWS credentials.
   - Prose comment lines must not begin with an UPPER_CASE token followed by `=` after the `# `. EnvExampleTest would count such a line as a documented key.

3. .github/workflows/hygiene.yml, tests job only:
   - In the job env, rename AWS_ENDPOINT to S3_ENDPOINT and keep the value http://127.0.0.1:9000.
   - In the comment above the rustfs service, name S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY.
   - Change nothing else: service, ports, images and ci-passed.needs stay as they are.

4. tests/Feature/Repo/CiParityTest.php:
   - The ciEnvExample docblock speaks of the S3_* values.
   - The key-pair test compares against S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY, expects the job env S3_ENDPOINT to be http://127.0.0.1:9000 and checks S3_USE_PATH_STYLE_ENDPOINT is 'true'. Keep the test names.

5. tests/Feature/Repo/EnvExampleTest.php:
   - In 'carries the DDEV-ready values the application relies on', replace AWS_USE_PATH_STYLE_ENDPOINT with S3_USE_PATH_STYLE_ENDPOINT => 'true' and add FILESYSTEM_DISK => 's3'.
   - Add the test 'keeps the AWS credentials commented out because they belong to AWS services, not the object storage'. The active values contain none of AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION, AWS_BUCKET, AWS_ENDPOINT, AWS_USE_PATH_STYLE_ENDPOINT or AWS_URL. The documented keys include AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY.
   - Add the test 'reads only S3 keys for the object storage'. Apply the file's configEnvReads() helper to the source of config/filesystems.php alone. The read keys include S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY, S3_DEFAULT_REGION, S3_BUCKET, S3_URL, S3_ENDPOINT and S3_USE_PATH_STYLE_ENDPOINT, and no read key starts with AWS_.

6. tests/Feature/Repo/RepositoryFilesTest.php: in the production-variable list of 'names the production environment variables for the Zerops UI and each is a documented key', replace AWS_BUCKET and AWS_ENDPOINT with S3_BUCKET and S3_ENDPOINT, and add S3_ACCESS_KEY_ID.

7. CONTRIBUTING.md, deploy checklist:
   - The storage line lists `FILESYSTEM_DISK` `s3`, `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY`, `S3_DEFAULT_REGION`, `S3_BUCKET`, `S3_ENDPOINT` and `S3_USE_PATH_STYLE_ENDPOINT` `true`, as references to the object storage service.
   - Add one sentence: the storage key pair goes into the S3_* names only, and any old AWS_* storage variable must be removed from the Zerops UI, because the AWS_* names belong to AWS services (SES) and the AWS SDK reads them from the environment.
   - In the secrets sentence, AWS_SECRET_ACCESS_KEY becomes S3_SECRET_ACCESS_KEY.
   - Keep every other phrase: RepositoryFilesTest and scripts/tests/test-docs.sh check wording.

8. Run the verify command. Then commit as `feat(quick-261011-ekq): prefix the object storage env keys with S3_`, following the commit procedure.
  </action>
  <verify>
    <automated>ddev exec 'env $(grep -E "^S3_" .env.example | xargs) php -d memory_limit=1G vendor/bin/pest tests/Feature/Repo tests/Feature/Operations/StorageCheckTest.php' && bash scripts/tests/test-workflow.sh && actionlint .github/workflows/hygiene.yml && zizmor --offline .github/workflows</automated>
  </verify>
  <done>
- tests/Feature/Repo passes, including both new EnvExampleTest cases and the CiParityTest key-pair case.
- The s3 group (StorageCheckTest) passes against RustFS using only S3_* process env.
- test-workflow.sh, actionlint and zizmor are clean.
- .env.example has FILESYSTEM_DISK=s3, the six active S3_* keys and only commented AWS_* keys.
- Committed through the hook.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Keep every stored file on the s3 disk and refuse production otherwise (media library default, test pins, ProductionConfigGuard)</name>
  <files>config/media-library.php, phpunit.xml, app/Support/ProductionConfigGuard.php, tests/Unit/Support/ProductionConfigGuardTest.php, tests/Feature/Auth/TwoFactorEnforcementTest.php, tests/Isolation/PanelAccessTest.php</files>
  <read_first>app/Support/ProductionConfigGuard.php; tests/Unit/Support/ProductionConfigGuardTest.php; tests/Feature/Auth/TwoFactorEnforcementTest.php lines 183-246; tests/Isolation/PanelAccessTest.php lines 315-350; config/media-library.php lines 30-50; phpunit.xml lines 29-50</read_first>
  <behavior>
    - Production with filesystems.default 'local', 'public', '', null or 'S3' (case differs) is refused with a message naming FILESYSTEM_DISK. The same holds for filament.default_filesystem_disk.
    - Production with media-library.disk_name 'public', 'local' or null is refused with a message naming MEDIA_DISK. media-library.conversions_disk_name 'public' is refused (MEDIA_DISK); null and 's3' are allowed.
    - Production with livewire.temporary_file_upload.disk 'local' is refused with a message naming LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK; null, '' and 's3' are allowed.
    - Production with filesystems.disks.s3 key, secret or bucket missing, empty or non-string is refused with a message naming S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY and S3_BUCKET.
    - Production whose default mailer uses the ses or ses-v2 transport is refused when services.ses key or secret is missing or empty, with a message naming AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY. The same rule applies to every member mailer when the default mailer is failover or roundrobin. smtp without any SES keys is allowed.
    - No refusal message contains a configured credential value. Assert this with the fragments the test passes in.
    - Outside production, every one of these bad values is allowed.
    - Every existing guard test keeps its expected message. The new checks run after the CACHE_STORE check, so earlier refusals win.
    - Real production processes started by the subprocess tests still boot when the storage env is valid.
  </behavior>
  <action>
1. config/media-library.php: `disk_name` defaults to 's3' (env MEDIA_DISK). Add a one-line comment: the public disk is a container-local directory served without authorisation, so documents and invoice PDFs belong in the private bucket. `conversions_disk_name` stays null, so conversions follow the original's disk.

2. phpunit.xml: add `<env>` entries FILESYSTEM_DISK=local and MEDIA_DISK=local next to the existing ones. The test suite must never write to the bucket by accident, for the same reason MAIL_MAILER=array keeps mail off the network. The s3 Pest group addresses the s3 disk explicitly and is unaffected. Real environment variables still win over these entries.

3. app/Support/ProductionConfigGuard.php: append five checks after the CACHE_STORE check, in this order. Each throws its own RuntimeException; the message starts with "Refusing to boot in production:", names the env key to fix, gives the reason in one sentence, and never includes a configured value.
   a. FILESYSTEM_DISK: `filesystems.default` and `filament.default_filesystem_disk` must both be exactly 's3'. Filament reads FILESYSTEM_DISK itself, with its own local fallback. Reason: container filesystems are replaced on every deploy and not shared between containers, so a file on a local disk is lost.
   b. MEDIA_DISK: `media-library.disk_name` must be exactly 's3', and `media-library.conversions_disk_name` must be null or 's3'.
   c. LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK: `livewire.temporary_file_upload.disk` must be null, '' or 's3'. Livewire falls back to the default disk on an empty value. An upload parked on one container is missing when the next request reaches another container.
   d. S3 key pair: `filesystems.disks.s3.key`, `.secret` and `.bucket` must be non-empty strings. The message names S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY and S3_BUCKET, and says that without its own key pair the AWS SDK falls back to the AWS_* credentials meant for SES.
   e. SES key pair: resolve the transport of the default mailer from `mail.mailers.<mail.default>.transport`. A missing mailer entry means not SES. For failover and roundrobin, also resolve each mailer listed under `mailers`. If any resolved transport is 'ses' or 'ses-v2', `services.ses.key` and `services.ses.secret` must be non-empty strings. The message names AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, and says the Admin alert mail would otherwise fail only at send time.
   Add private static helpers in the style of isPublicHttpsUrl, for example a non-empty-string test and a helper that collects mailer transports. Use strict comparisons throughout. Update the class docblock to mention the storage and mail credentials.

4. tests/Unit/Support/ProductionConfigGuardTest.php:
   - Extend guardConfig() so its default is a valid production shape:
     - storage: filesystems.default 's3'; filesystems.disks.s3 key, secret and bucket built from runtime fragments; filament.default_filesystem_disk 's3'; media-library disk_name 's3' and conversions_disk_name null; livewire.temporary_file_upload.disk null
     - mail: mail.mailers with smtp => transport smtp and ses => transport ses; services.ses key and secret from fragments
   - Give each new value a named parameter so it can be overridden.
   - Rebuild 'treats a missing canary setting as off in production' from the valid shape minus the canary key. It is the only explicit-Repository case that expects success.
   - The existing dataset 'allows production with a real mail transport' must keep passing for 'ses', because the default shape carries the SES keys.
   - Add the behaviour cases above. Use datasets where a check has several bad values; keep one test that passes every bad value with isProduction false.

5. tests/Feature/Auth/TwoFactorEnforcementTest.php:
   - Extend the config() call in 'boots the application provider in production with enforcement on' with filesystems.default 's3', filament.default_filesystem_disk 's3', media-library.disk_name 's3', and the s3 disk key, secret and bucket from fragments.
   - Add one in-process case 'refuses to boot the application provider in production with a local default disk', which expects FILESYSTEM_DISK.
   - In the subprocess `$run` closure env, add FILESYSTEM_DISK=s3, MEDIA_DISK=s3, S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY and S3_BUCKET, the last three from fragments. The child inherits the phpunit.xml local pins and must not depend on the developer's .env.

6. tests/Isolation/PanelAccessTest.php: in the production canary subprocess env (line 338), add the same five entries.

7. Write the new guard tests first and watch them fail. Implement. Run the full suite, PHPStan and Pint. Commit as `feat(quick-261011-ekq): keep every stored file on the s3 disk and guard it in production`.
  </action>
  <verify>
    <automated>ddev exec 'env $(grep -E "^S3_" .env.example | xargs) php -d memory_limit=1G vendor/bin/pest' && ddev exec composer stan && ddev exec vendor/bin/pint --test app/Support/ProductionConfigGuard.php config/media-library.php tests/Unit/Support/ProductionConfigGuardTest.php tests/Feature/Auth/TwoFactorEnforcementTest.php tests/Isolation/PanelAccessTest.php</automated>
  </verify>
  <done>
- The full Pest suite reports 0 failed, including every old and new ProductionConfigGuardTest case and both subprocess production boots.
- PHPStan and Pint are clean.
- media-library defaults to s3.
- phpunit.xml pins FILESYSTEM_DISK and MEDIA_DISK to local.
- Committed through the hook.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Send mail through the Amazon SES API on the standard AWS_* keys (direct SDK requirement, bounded client, docs)</name>
  <files>composer.json, composer.lock, config/mail.php, config/services.php, .env.example, CONTRIBUTING.md, tests/Feature/Operations/AwsCredentialSeparationTest.php</files>
  <read_first>config/mail.php; config/services.php; vendor/laravel/framework/src/Illuminate/Mail/MailManager.php lines 250-312; vendor/laravel/framework/src/Illuminate/Mail/Transport/SesTransport.php around line 118; .env.example Mail section; CONTRIBUTING.md line 77 (Storage paragraph) and lines 255-275 (deploy checklist); composer.json require block</read_first>
  <behavior>
    - With services.ses key, secret and region set from test fragments, Mail::mailer('ses') resolves to Illuminate\Mail\Transport\SesTransport. Its ses() client returns the configured access key from getCredentials()->wait()->getAccessKeyId() and the configured region from getRegion(). Purge the mailer before resolving so a cached instance does not answer.
    - config('mail.mailers.ses') carries http timeout and connect_timeout equal to config('mail.mailers.smtp.timeout'), and retries equal to 1.
    - The test runs `php artisan config:show filesystems.disks.s3` in a subprocess with APP_ENV=testing, S3_ACCESS_KEY_ID set to fragment A, AWS_ACCESS_KEY_ID set to fragment B and AWS_DEFAULT_REGION set to a region other than us-east-1. The output contains A, and contains neither B nor that region.
    - `php artisan config:show services.ses` with the same env contains B and the AWS_DEFAULT_REGION value, and does not contain A. A second run that also sets AWS_SES_REGION shows that region instead.
  </behavior>
  <action>
1. Composer:
   - Run `ddev composer require "aws/aws-sdk-php:^3.399" --no-update`, then `ddev composer update --lock --no-interaction`.
   - aws/aws-sdk-php must stay at the version locked today (3.399.2), and the composer.lock diff must be limited to content-hash. If the solver wants to change any package version, revert both files and report it in the SUMMARY instead of accepting the churn.
   - Why a direct requirement: the SES transport uses the SDK's SesClient directly, and relying on the flysystem adapter's transitive dependency would break silently if the adapter ever dropped it.
   - Run `ddev composer check-licenses` (Apache-2.0 is allowed and AGPL-compatible) and `ddev composer audit --locked`.

2. config/mail.php, `ses` mailer:
   - Keep transport 'ses'.
   - Add the AWS SDK client options: `http` with timeout and connect_timeout both from (int) env('MAIL_TIMEOUT', 10), and `retries` 1.
   - Add a comment in the style of the smtp timeout comment: the Admin alert for a failed job is sent synchronously inside the queue worker and must stay far below the 60 s job timeout; the SDK default has no request timeout; two attempts at MAIL_TIMEOUT seconds stay well inside the limit.
   - Leave the default mailer fallback and the failover and roundrobin entries unchanged.

3. config/services.php, `ses`:
   - key and secret stay on AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY.
   - region reads AWS_SES_REGION and falls back to env('AWS_DEFAULT_REGION', 'us-east-1').
   - Add a comment: these are the standard AWS names and only AWS services read them; the object storage uses its own S3_* keys.

4. .env.example, in the commented AWS block of the Mail section:
   - Add a prose comment saying that setting MAIL_MAILER to ses sends mail through the Amazon SES API with these credentials. The active MAIL_MAILER=smtp for Mailpit stays.
   - Add a comment line saying the next key is optional, for when SES runs in a region other than AWS_DEFAULT_REGION, followed by the commented key AWS_SES_REGION (empty).
   - The same prose-line rule as Task 1 applies.

5. CONTRIBUTING.md:
   a. Storage paragraph (line 77): keep its text and append the following.
      - Every stored file goes to the s3 disk:
        - the default disk, FILESYSTEM_DISK, which falls back to s3
        - Filament uploads, which follow FILESYSTEM_DISK
        - the media library, MEDIA_DISK, which defaults to s3
        - Livewire temporary uploads, which follow the default disk. On s3 the browser uploads directly through a presigned URL, so the reachable-endpoint note for downloads applies to uploads as well.
      - ProductionConfigGuard refuses production otherwise and requires the S3 key pair.
      - phpunit.xml pins FILESYSTEM_DISK and MEDIA_DISK to local so the suite never writes to the bucket by accident; the s3 Pest group uses the s3 disk explicitly.
   b. Deploy checklist, mail line: add the alternative `MAIL_MAILER` `ses`, which sends through the Amazon SES API with `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `AWS_DEFAULT_REGION` or `AWS_SES_REGION`. The guard refuses SES without the key pair, and the SMTP keys are then not needed.
   c. Deploy checklist, storage line: add `MEDIA_DISK` and `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`, each unset or `s3` (guard).
   d. Secrets sentence: add `AWS_SECRET_ACCESS_KEY` when SES is used.
   e. Keep every phrase the doc tests check.

6. tests/Feature/Operations/AwsCredentialSeparationTest.php:
   - Write the behaviour cases first.
   - Build every credential-like value from runtime fragments.
   - Run subprocesses with Symfony Process and PHP_BINARY, following the artisanProcess pattern in tests/Isolation/PanelAccessTest.php.

7. Run the full suite, PHPStan, Pint and the documentation shell check. Commit as `feat(quick-261011-ekq): send mail through the Amazon SES API on the standard AWS keys`.
  </action>
  <verify>
    <automated>ddev exec 'env $(grep -E "^S3_" .env.example | xargs) php -d memory_limit=1G vendor/bin/pest' && ddev exec composer stan && ddev exec vendor/bin/pint --test config/mail.php config/services.php tests/Feature/Operations/AwsCredentialSeparationTest.php && ddev composer check-licenses && ddev composer validate --no-check-publish && ddev exec bash scripts/tests/test-docs.sh</automated>
  </verify>
  <done>
- The full Pest suite reports 0 failed, including AwsCredentialSeparationTest.
- composer.json requires aws/aws-sdk-php, `ddev composer show aws/aws-sdk-php` still reports 3.399.2, and composer.lock changed only in content-hash.
- The licence check and composer validate pass.
- MAIL_MAILER=ses is documented in .env.example and CONTRIBUTING.md.
- Committed through the hook.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| environment -> application config | Operator-set variables (Zerops UI, local .env) become credentials for two different providers |
| application -> object storage | Every stored file crosses here; the bucket is private |
| application -> Amazon SES API | Outgoing mail, including the synchronous Admin alert from the queue worker |
| repository -> public | AGPL repository; nothing instance-specific may be committed |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-ekq-01 | Spoofing | config/filesystems.php s3 disk, config/services.php ses | high | mitigate | Separate prefixes (S3_* for storage, AWS_* for SES). The s3 region has its own us-east-1 default, so the SDK never takes the AWS region. The guard refuses production without the S3 key pair, which would otherwise let the SDK sign storage requests with the SES pair. The guard also refuses SES without its key pair. AwsCredentialSeparationTest proves neither value leaks into the other config. |
| T-ekq-02 | Information Disclosure | config/media-library.php default disk | high | mitigate | The media library defaulted to the public disk, which is served under /storage without authorisation. Its default becomes the private s3 disk. The guard refuses production with a media, conversions, default or Filament disk other than s3. |
| T-ekq-03 | Information Disclosure | ProductionConfigGuard messages | medium | mitigate | Messages name env keys only and never interpolate configured values. Unit tests assert that no fragment appears in any message. |
| T-ekq-04 | Denial of Service | config/mail.php ses mailer | medium | mitigate | Bounded http timeout and connect_timeout (MAIL_TIMEOUT, 10 s) and retries 1, so a hanging SES endpoint cannot stall the worker past the 60 s job timeout. |
| T-ekq-05 | Denial of Service | Zerops deploy after the rename | medium | accept | A deploy before the owner renames the Zerops variables is refused at boot by the guard (fail-closed); the previous version keeps serving. The SUMMARY lists the rename as an owner follow-up. |
| T-ekq-06 | Information Disclosure | .env.example, tests, docs, commits | medium | mitigate | Only the public RustFS development defaults already in the repository, runtime-assembled test fragments and example.com values. scripts/check-sensitive.sh and gitleaks run in the lefthook hook on every commit and are never bypassed. The local .env is never read or edited. |
| T-ekq-SC | Tampering | composer require aws/aws-sdk-php | high | mitigate | No new code is downloaded: the package is already locked at 3.399.2 as a transitive dependency, and only the root requirement and content-hash change. The official AWS SDK is Apache-2.0 and on the licence allowlist. `composer check-licenses` and `composer audit --locked` run in the verify step and in CI. |
</threat_model>

<verification>
- `ddev exec 'env $(grep -E "^S3_" .env.example | xargs) php -d memory_limit=1G vendor/bin/pest'` reports 0 failed, including the s3 group against RustFS.
- `ddev exec composer stan` and `ddev exec vendor/bin/pint --test` are clean.
- `bash scripts/tests/test-workflow.sh`, `actionlint .github/workflows/hygiene.yml` and `zizmor --offline .github/workflows` pass.
- `ddev composer check-licenses` passes, and `ddev composer show aws/aws-sdk-php` reports the version locked before the change.
- `scripts/check-sensitive.sh` is clean before each of the three commits.
</verification>

<success_criteria>
- The object storage is configured only through S3_* keys, in .env.example, config, CI and the deploy checklist. The AWS_* keys are reserved for AWS services and documented only as commented SES keys.
- Every file store (default disk, Filament, media library, Livewire temporary uploads) resolves to s3 at runtime, and production refuses to boot otherwise.
- MAIL_MAILER=ses works with the standard AWS_* keys and a bounded client, and production refuses SES without the key pair.
- Three green, hook-checked commits.
</success_criteria>

<output>
Create `.planning/quick/261011-ekq-prefix-s3-storage-env-vars-with-s3-store/261011-ekq-SUMMARY.md` when done.

The SUMMARY must contain an "Owner follow-ups" section with these items:
1. Rename the storage keys in your local `.env`: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION, AWS_BUCKET, AWS_USE_PATH_STYLE_ENDPOINT, AWS_ENDPOINT and AWS_URL become their S3_* counterparts. Set FILESYSTEM_DISK=s3. The executor did not touch `.env`; the DDEV checks ran with the S3_* keys of .env.example as process env.
2. Before deploying this version to Zerops, rename the storage variables in the Zerops UI to S3_* and remove the old AWS_* storage variables. Otherwise the guard refuses to boot and the deploy stops while the previous version keeps serving. Leftover AWS_* storage values would also be read as SES credentials.
3. Optional SES setup: MAIL_MAILER=ses, AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY (secret), and AWS_DEFAULT_REGION or AWS_SES_REGION.

Also record the Livewire presigned-upload note for the Documents phase: an s3 temporary upload disk needs a browser-reachable endpoint.
</output>
