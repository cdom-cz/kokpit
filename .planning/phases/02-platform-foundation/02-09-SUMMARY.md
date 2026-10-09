---
phase: 02-platform-foundation
plan: 09
subsystem: identity
tags: [roles, install-command, totp, recovery-codes, filament-mfa, production-guard, pest]

requires:
  - phase: 02-platform-foundation
    provides: User with HasRoles and TOTP columns (02-03), config/kokpit.php and phpunit KOKPIT_REQUIRE_ADMIN_2FA=false (02-02), lang/cs and the enum label check (02-08)
provides:
  - App\Domain\Identity\RoleName (admin, partner) with Czech labels, RoleSeeder, DatabaseSeeder that seeds only roles
  - kokpit:install (single Admin, hidden prompt or KOKPIT_ADMIN_PASSWORD, no password option)
  - User implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
  - Panel wiring - profile, TOTP with recovery codes, EnsureAdminHasTwoFactor (Admin only, config switch), local initials avatar provider
  - ProductionConfigGuard (boot refusal when Admin 2FA enforcement is off in production)
  - kokpit:admin:reset-2fa (server-side reset for a lost device or rotated APP_KEY)
affects: [02-10 default-deny data layer (roles exist, role scope is safe once roles are created), 02-11 canary rule in ProductionConfigGuard, 02-13 phase gate (Czech and visual walk-through of the login, set-up, challenge and profile screens)]

actuals:
  tokens: 11300
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "Roles are findOrCreate'd before the first role query (the role scope throws on an empty database); a role-agnostic whereHas gives the cheap pre-check"
    - "One-shot secrets are read with Illuminate\\Support\\Env::get, never config()"
    - "Admin-only 2FA enforcement is a per-request middleware set through multiFactorAuthenticationRequiredMiddlewareName, because Filament evaluates isRequired once at route build"
    - "Unsafe-in-production switches are refused at boot by a pure, unit-testable guard class, and proven again in a real subprocess"

key-files:
  created:
    - app/Domain/Identity/RoleName.php
    - database/seeders/RoleSeeder.php
    - app/Console/Commands/InstallCommand.php
    - app/Console/Commands/ResetAdminTwoFactorCommand.php
    - app/Http/Middleware/EnsureAdminHasTwoFactor.php
    - app/Support/ProductionConfigGuard.php
    - app/Support/InitialsAvatarProvider.php
    - lang/cs/enums.php
    - lang/cs/kokpit.php
    - tests/Feature/Auth/InstallCommandTest.php
    - tests/Feature/Auth/TwoFactorEnforcementTest.php
    - tests/Feature/Auth/ResetTwoFactorCommandTest.php
    - tests/Unit/Support/ProductionConfigGuardTest.php
    - tests/Unit/Support/InitialsAvatarProviderTest.php
  modified:
    - app/Domain/Identity/Models/User.php
    - app/Providers/AppServiceProvider.php
    - app/Providers/Filament/AdminPanelProvider.php
    - database/seeders/DatabaseSeeder.php

key-decisions:
  - "Install refuses a second Admin twice: a role-agnostic pre-check before any prompt (so a second run never asks for a password) and the authoritative User::role check inside the transaction under pg_advisory_xact_lock after both roles were created"
  - "E-mail addresses are lower-cased on install and looked up case-insensitively by the reset command, so a typed address with different case cannot miss or duplicate an account"
  - "Role label for Admin stays 'Administrátor' as the plan says; the UI-SPEC says 'Správce' (see deviations)"
  - "ProductionConfigGuard treats anything other than boolean true as off, so a missing setting also refuses production"

patterns-established:
  - "Auth tests assemble addresses with exampleEmail() and passwords with Str::password(); TOTP codes come from the Google2FA library on the stored secret"
  - "Livewire login challenge state path is data.multiFactor.app.code (recovery: data.multiFactor.app.useRecoveryCode and .recoveryCode)"

requirements-completed: [FND-17, FND-06]

coverage:
  - id: D1
    description: "Roles admin and partner exist through the permission package, created idempotently by RoleSeeder and by the install command, with UUID v7 keys and Czech labels"
    requirement: "FND-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Auth/InstallCommandTest.php#creates both roles idempotently through the seeder, #labels both roles in Czech; tests/Feature/Localisation/EnumLabelsTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "kokpit:install creates exactly one Admin with a hashed password from the hidden prompt or from KOKPIT_ADMIN_PASSWORD, never prints the password, has no password option, and the Admin can sign in to the panel"
    requirement: "FND-17"
    verification:
      - kind: integration
        ref: "tests/Feature/Auth/InstallCommandTest.php#creates exactly one Admin from the interactive prompts, #reads the password from the environment variable without interaction, #fails without creating anything when the environment variable is empty, #has no password option or argument, #lets the created Admin sign in to the panel"
        status: pass
    human_judgment: false
  - id: D3
    description: "A second install, passwords under 12 characters or over 72 bytes, invalid and duplicate e-mails are refused and create nothing; a user without Admin or Partner role gets 403 on the panel"
    requirement: "FND-17"
    verification:
      - kind: integration
        ref: "tests/Feature/Auth/InstallCommandTest.php#refuses a second run and creates nothing, #rejects a password shorter than 12 characters, #rejects a password longer than 72 bytes..., #counts password length in bytes, #rejects an invalid e-mail address, #rejects an e-mail address that is already registered, #turns a user without a role away from the panel"
        status: pass
    human_judgment: false
  - id: D4
    description: "With enforcement on an Admin without TOTP is redirected to the set-up page (which renders without a loop) on every panel page; enforcement off, a Partner and an Admin with a secret pass; clearing the secret sends the Admin back"
    requirement: "FND-17"
    verification:
      - kind: integration
        ref: "tests/Feature/Auth/TwoFactorEnforcementTest.php#sends an Admin without a TOTP secret..., #renders the set-up page without another redirect, #keeps an Admin without a TOTP secret away from every panel page, #lets an Admin in without a secret when enforcement is off, #never forces a Partner..., #lets an Admin with a stored secret..., #sends the Admin back to set-up after the secret is cleared"
        status: pass
    human_judgment: false
  - id: D5
    description: "Login challenges an Admin with a secret for a code; a valid TOTP code or a one-time recovery code completes sign-in, a wrong code or a reused recovery code does not; secret and recovery codes are stored encrypted and hidden from toArray()"
    requirement: "FND-17"
    verification:
      - kind: integration
        ref: "tests/Feature/Auth/TwoFactorEnforcementTest.php#does not sign an Admin with a secret in until a valid code is given, #signs an Admin in with a recovery code and refuses to accept it twice, #stores the secret and the recovery codes encrypted and hides both"
        status: pass
    human_judgment: false
  - id: D6
    description: "The application refuses to boot in production with Admin 2FA enforcement off (unit, provider and real subprocess), and boots with it on"
    requirement: "FND-17"
    verification:
      - kind: unit
        ref: "tests/Unit/Support/ProductionConfigGuardTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Auth/TwoFactorEnforcementTest.php#refuses to boot the application provider in production with enforcement off, #refuses to start a real production process with enforcement off and starts with it on"
        status: pass
    human_judgment: false
  - id: D7
    description: "kokpit:admin:reset-2fa clears secret and recovery codes of the named user only, after confirmation or --force, refuses non-interactive use without --force, logs the user id and not the e-mail, and the Admin is sent to set-up again"
    requirement: "FND-17"
    verification:
      - kind: integration
        ref: "tests/Feature/Auth/ResetTwoFactorCommandTest.php"
        status: pass
    human_judgment: false
  - id: D8
    description: "The login, forced 2FA set-up, challenge, recovery-code and profile screens look and read as the UI-SPEC describes (Czech text, QR code and recovery codes rendered locally, focus and dark mode)"
    requirement: "FND-17"
    verification: []
    human_judgment: true
    rationale: "Tests prove behaviour and redirects, not rendered appearance; the manual Czech and visual walk-through belongs to the phase gate 02-13."

duration: 6 min
completed: 2026-10-07
status: complete
commits: 5
plan_head_before: 7bf4f315f5a70d0abe62544c32e013fc4913721d
plan_head_after: c0e4820163c08fd0ce397beb0b797c8c6a8e89a9
---

# Phase 2 Plan 09: Roles, install command and mandatory Admin TOTP Summary

**Roles admin and partner through the permission package, a `kokpit:install` that creates the single Admin from a hidden prompt or `KOKPIT_ADMIN_PASSWORD` (no password option, advisory lock, 12 to 72 byte rule), Filament built-in TOTP with recovery codes enforced for the Admin by a per-request middleware, a production boot guard, and a CLI reset for a lost device, all proven by 4 test files plus one avatar test file.**

## Performance

- **Duration:** 6 min (2026-10-07T19:22:34Z to 19:28:49Z)
- **Tasks:** 3 of 3
- **Files:** 18 (14 created, 4 modified)
- **Suite:** 262 passed (was 213), Pint and Larastan level 8 clean, `scripts/check-sensitive.sh` clean on every commit

## Accomplishments

- `RoleName` (`HasLabel`), `RoleSeeder` and a `DatabaseSeeder` that seeds only roles. The framework's default `Test User` seeding was removed: a seeded account without a role and with a known password contradicts D-05.
- `kokpit:install {--name=} {--email=}`: Czech hidden prompts, non-interactive mode reads `Env::get('KOKPIT_ADMIN_PASSWORD')` and fails closed when empty; validation (`email:rfc` unique, `Password::min(12)`, at most 72 bytes counted with `strlen`); `pg_advisory_xact_lock` and `findOrCreate` of both roles inside one transaction before the Admin existence check; the password is never echoed (asserted with `doesntExpectOutputToContain`).
- `User` implements `FilamentUser` (`canAccessPanel`: admin or partner role), `HasAppAuthentication` and `HasAppAuthenticationRecovery`.
- `EnsureAdminHasTwoFactor` extends Filament's middleware and is registered with `multiFactorAuthenticationRequiredMiddlewareName`; the set-up page renders without a loop, Partners are never forced, and the check follows the secret (clearing it sends the Admin back).
- `ProductionConfigGuard::check()` is called from `AppServiceProvider::boot()`; a real `php artisan --version` subprocess with `APP_ENV=production KOKPIT_REQUIRE_ADMIN_2FA=false` dies with the guard's message and starts with `true`.
- `kokpit:admin:reset-2fa {email} {--force}` clears both columns by `forceFill`, logs only the user id, finds the user case-insensitively, and is a no-op on refusal.
- Filament MFA method names as found in the installed version (filament/filament 5.x): `Panel::multiFactorAuthentication(array|Provider|Closure $providers, string|Closure|array|null $setUpRequiredAction, bool|Closure $isRequired = false)`, `Panel::multiFactorAuthenticationRequiredMiddlewareName(string|Closure $name)`, `Panel::profile(?string $page, bool $isSimple = true)`, `Panel::defaultAvatarProvider(string|Closure)`, `AppAuthentication::make()->recoverable()` (also `regenerableRecoveryCodes()`, `brandName()`, `recoveryCodeCount()`, `codeWindow()`, `generateRecoveryCodes()`, `saveRecoveryCodes($user, $codes)`), model traits `Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication` and `...InteractsWithAppAuthenticationRecovery` with contracts `Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication` and `HasAppAuthenticationRecovery`, base middleware `Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled`. The Livewire login challenge state path is `data.multiFactor.app.code` (`useRecoveryCode`, `recoveryCode` for recovery).

## Tracer gate

Task 1 (tracer) was committed and its `<verify>` re-run on the committed tree before expansion: install and enum-label tests green, Pint and Larastan clean. Expansion followed.

## Task Commits

1. **Task 1: Tracer, roles, install command and panel access** - `049abdb` (feat)
2. **Task 2 RED: failing enforcement, recovery-code and production-guard tests** - `4eb1982` (test)
3. **Task 2 GREEN: TOTP wiring, middleware, guard, avatar provider** - `ac05f3d` (feat)
4. **Task 3 RED: failing reset-command tests** - `15f5615` (test)
5. **Task 3 GREEN: kokpit:admin:reset-2fa** - `c0e4820` (feat)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

## TDD Gate Compliance

Tasks 2 and 3 followed RED then GREEN with separate commits; there are no REFACTOR commits (nothing to clean up).

- **Task 2 RED (`4eb1982`):** 12 of 18 tests failed. Semantic assessment: the enforcement tests failed on their own assertions (a 200 where a redirect to `multi-factor-authentication/set-up` was expected, 404 on `/admin/profile`, the user authenticated without a code, no RuntimeException in production); the two recovery-code tests failed with a `TypeError` because `User` did not implement `HasAppAuthenticationRecovery`, which is the missing contract under test. The guard tests were run against a temporary no-op stub of `ProductionConfigGuard` (kept out of the RED commit) so they failed on "exception expected", not on a missing class. The 6 that passed in RED are negative controls that already hold (enforcement off, Partner, Admin with secret, "boots in production with enforcement on", and two allow cases of the guard).
- **Task 3 RED (`15f5615`):** 9 of 10 failed against a no-op command stub (exit code 0, columns untouched, no log, no redirect). "only touches the named user" passes against the stub by design (a guard against over-reach), so it is not RED evidence.
- `gsd_run check tdd-red-evidence` was not run: `tdd_mode` is off and Pest console output is not a supported report format (same as earlier plans).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test queried the role scope on a database without roles**
- **Found during:** Task 1 (first test run)
- **Issue:** the duplicate-e-mail test asserted with `User::role('admin')`, which throws `RoleDoesNotExist` when no role exists (Pitfall 8, the same hazard the command works around).
- **Fix:** the test counts `model_has_roles` rows directly.
- **Files modified:** `tests/Feature/Auth/InstallCommandTest.php`
- **Committed in:** `049abdb`

**2. [Rule 2 - Missing critical] Early role-agnostic refusal before any prompt**
- **Issue:** the plan checks for an existing Admin only inside the transaction, so a second interactive run would first ask for name, e-mail and a password and only then refuse. Added a `whereHas('roles')` pre-check (safe without roles) at the top; the authoritative check under the advisory lock is kept.
- **Files modified:** `app/Console/Commands/InstallCommand.php`
- **Committed in:** `049abdb`

**3. [Rule 2 - Missing critical] Case handling of e-mail addresses**
- **Issue:** PostgreSQL compares `varchar` case-sensitively; an Admin created as `Jane@...` could not be reset or signed in by typing another case, and the unique rule would accept two cases of one address. Install lower-cases the address; the reset command looks up with `lower(email)`.
- **Committed in:** `049abdb`, `c0e4820`

**4. [Rule 2 - Missing critical] Local initials avatar provider (UI-SPEC A-4)**
- **Issue:** with `->profile()` and the user menu, Filament's default avatar provider sends the user's name to a third-party host on every page view. The orchestrator's instruction applies UI-SPEC A-4 to this plan; `App\Support\InitialsAvatarProvider` returns an inline SVG data URI, registered with `defaultAvatarProvider()`. File is not in the plan's `files_modified`.
- **Files modified:** `app/Support/InitialsAvatarProvider.php`, `app/Providers/Filament/AdminPanelProvider.php`, `tests/Unit/Support/InitialsAvatarProviderTest.php`
- **Committed in:** `ac05f3d`

**5. [Rule 2 - Missing critical] Positive control for the production subprocess test**
- **Issue:** a subprocess that merely fails could be failing for another reason. The test asserts the guard's own message for `false` and a successful start for `true`.
- **Committed in:** `ac05f3d`

### Plan adaptations and UI-SPEC conflicts

**6. [UI-SPEC vs plan] Admin role label is "Administrátor", not "Správce".** The plan (task 1 action) fixes `role_name.admin` = "Administrátor"; the UI-SPEC copywriting contract and cross-cutting rules say "Správce". Per the orchestrator's rule the plan wins. The CLI strings that the UI-SPEC quotes verbatim (A-5) use "správce" as the operator's word for the account ("Správce byl vytvořen...", "Zadejte jméno správce"), so the two terms now coexist; the owner may align them by changing one string in `lang/cs/enums.php`.

**7. [Plan adaptation] CLI strings follow UI-SPEC A-5 where it quotes them** (prompts "Zadejte e-mail správce", "Zadejte jméno správce", "Zadejte heslo (nejméně 12 znaků)", success "Správce byl vytvořen. Přihlaste se a nastavte dvoufázové ověření.", refusal "Správce již existuje. Druhý účet správce nelze vytvořit."). The login URL is printed on a separate line as the plan requires.

**8. [Plan adaptation] `PartnerContext::runAsSystem()`** appears in RESEARCH Pattern 7 for the install body, but the plan does not list it and the class does not exist before 02-10. The command runs without it; 02-10 must wrap the install and reset commands in the system context when the Partner scope lands (they query `User` and roles).

**9. [UI-SPEC not applied, out of scope for this plan]** Indigo primary colour (A-3), the custom dashboard, removal of the stock widgets, `globalSearch(false)`, the topbar and brand configuration. The orchestrator limited this plan to Surfaces 1 to 4, the copy contract and A-1, A-4, A-5, A-10; the login, set-up, challenge and profile surfaces come from stock Filament, whose Czech strings are unchanged.

**Total deviations:** 1 bug, 4 missing-critical additions, 4 adaptations or conflicts recorded
**Impact on plan:** no scope reduction; every plan truth is covered by a passing test.

## Issues Encountered

- A-1 gap (reported, not built, as instructed): there is no web password reset and no CLI password reset for a forgotten Admin password. The plan contains no CLI password command and Phase 4 brings the mail flow. Until then a forgotten Admin password can only be fixed by a database or tinker change by the operator. The planner should decide whether a `kokpit:admin:reset-password` belongs before Phase 4.
- Rendered appearance (Czech diacritics in Inter, dark-mode contrast of the primary button, QR code and recovery-code layout, focus ring) was not inspected in a browser; it is a visual check for the phase gate 02-13 (coverage item D8).
- The local DDEV `.env` still carries `APP_LOCALE=en` (see 02-08), so the dev panel shows English until the developer changes it.
- `APP_PREVIOUS_KEYS` handling for a rotated `APP_KEY` is only exercised through the reset command; the README documentation RESEARCH mentions belongs to a later docs plan.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. T-02-29 (password never an option, never printed; tested), T-02-30 (advisory lock plus in-transaction check; second run refused), T-02-31 (guard unit, provider and subprocess tests), T-02-32 (Filament replay protection and hashed recovery codes; reused recovery code refused in the login test), T-02-33 (`$fillable` unchanged, roles only through `assignRole`, TOTP columns only through `forceFill`), T-02-34 (CLI only, confirmation or `--force`, id-only log), T-02-35 (72-byte rule tested, counted in bytes).

## User Setup Required

None for the repository. To try it locally: `ddev artisan kokpit:install`, then sign in at `/admin` (set `KOKPIT_REQUIRE_ADMIN_2FA=true` in your own `.env` to see the forced set-up screen; the test suite keeps it off).

## Next Phase Readiness

- 02-10 can rely on both roles existing after `RoleSeeder`/`kokpit:install`; it must make the install and reset commands work under whatever system context the Partner scope needs.
- 02-11 adds the canary rule to `ProductionConfigGuard::check()` (same signature).
- 02-13 walks the four auth screens in a browser (coverage item D8).

## Self-Check: PASSED

- Created files exist (14 created files checked with `[ -f ]`).
- Commits `049abdb`, `4eb1982`, `ac05f3d`, `15f5615`, `c0e4820` are ancestors of HEAD; `git rev-list --count 7bf4f31..HEAD` is 5.
- Acceptance criteria re-run: enum cases, `Env::get('KOKPIT_ADMIN_PASSWORD')`, `pg_advisory_xact_lock`, `kokpit:install --help` lists `--name` and `--email` and has no `--password` line, `FilamentUser` and `InteractsWithAppAuthenticationRecovery` in `User`, panel greps (`multiFactorAuthenticationRequiredMiddlewareName`, `recoverable()`), middleware and guard greps, reset command greps (`forceFill`, `'user_id'`).
- `ddev exec vendor/bin/pest`: 262 passed; Pint and Larastan level 8 clean.
