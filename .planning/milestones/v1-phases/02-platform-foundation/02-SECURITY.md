---
phase: "02"
slug: "platform-foundation"
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
block_on: high
created: "2026-10-08"
---

# Phase 2 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> Register taken from the 13 PLAN `<threat_model>` blocks (T-02-SC appears in three plans and is listed once per plan). Verified at ASVS level 1 by the security auditor; related test suites and linters were re-run.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Repository to public | Public AGPL-3.0 repository, CI scans everything | Source, docs, planning docs (fictional data only) |
| Developer machine to local services | DDEV web, db, redis, rustfs, mailpit | Local credentials from `.env`, never committed |
| Browser to panel | Filament panel on a single domain | Session, TOTP codes, recovery codes |
| Admin to Partner | Same panel, role-restricted view | Client-scoped records (Partner sees only own client) |
| App to PostgreSQL | Migrations, triggers, sequences | Money, document numbers, issued records |
| CI runner to GitHub | Workflow jobs and third-party actions | Repository contents, generated CI-only secrets |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation | Status |
|-----------|----------|-----------|----------|-------------|------------|--------|
| T-02-01 | Information disclosure | sensitive-allowlist entries for composer.lock | high | mitigate | Path-anchored allowlist entries with decision comments; CODEOWNERS on /scripts/ | closed |
| T-02-02 | Elevation of privilege | skeleton agent instruction files | medium | mitigate | No tracked agent files other than `.claude/CLAUDE.md`; no extra agent packages | closed |
| T-02-03 | Tampering | Filament compiled assets in public/ | medium | mitigate | Ignored and rebuilt via `filament:upgrade`; test-gitignore.sh verdicts | closed |
| T-02-04 | Denial of service | fresh clone without storage placeholders | medium | mitigate | Content-ignore with re-included placeholders; gitignore tests | closed |
| T-02-SC (02-01) | Tampering | Composer installs | high | mitigate | Explicit constraints, `composer audit --locked`, licence gate, package legitimacy audit | closed |
| T-02-05 | Tampering | tests/TestCase.php guard | high | mitigate | Throws unless pgsql and database name ends in `_test` | closed |
| T-02-06 | Tampering | RustFS and rc images, Redis add-on | medium | accept | Pinned by tag, not by digest; no `latest` tag | closed (accepted, AR-1) |
| T-02-07 | Information disclosure | .env.example, .ddev/ files | high | mitigate | No secrets in templates; tests for DDEV config and `.env.example`; hooks | closed |
| T-02-SC (02-02) | Tampering | ddev add-on, symfony/yaml | medium | mitigate | Official add-on, pinned version, covered by audit and licence gates | closed |
| T-02-08 | Denial of service | queue-worker and scheduler on a fresh clone | low | accept | Wait loop until `vendor/autoload.php` exists | closed (accepted, AR-2) |
| T-02-09 | Tampering | Spatie Role/Permission without a subclass | high | mitigate | App subclasses with UUID v7 keys, configured in `config/permission.php` | closed |
| T-02-10 | Information disclosure | morph `_type` columns holding class names | medium | mitigate | `enforceMorphMap`; schema convention tests | closed |
| T-02-11 | Elevation of privilege | users.client_id via mass assignment | high | mitigate | Explicit `#[Fillable]` on User; nothing writes client_id | closed |
| T-02-12 | Tampering | timestamps without time zone | medium | mitigate | UTC connection, timestamptz only, schema convention test | closed |
| T-02-13 | Spoofing | Sanctum findToken with integer-cast keys | high | mitigate | Custom PersonalAccessToken model; package model tests | closed |
| T-02-14 | Information disclosure | media, tags, activity and webhook rows | medium | mitigate | PartnerIsolated plus DeniesPartners on package models; AdminOnlyPolicy; canary tests | closed |
| T-02-15 | Information disclosure | activity log and webhook payload content | medium | transfer | Transferred to Phase 3 (FND-08 allowlist) and Phase 11 (webhook retention) in ROADMAP | closed |
| T-02-16 | Tampering | implicit or repeated rounding, float | high | mitigate | Single `RoundingMode::HalfUp` in Money; arch test on boundary | closed |
| T-02-17 | Tampering | arithmetic across currencies | high | mitigate | `assertSameCurrency`; unit tests | closed |
| T-02-18 | Tampering | integer overflow wrapping | medium | mitigate | `toInt` throws OverflowException | closed |
| T-02-19 | Tampering | duplicate or gapped document numbers | high | mitigate | `ON CONFLICT` + `SELECT ... FOR UPDATE`; unique key; concurrency test | closed |
| T-02-20 | Tampering | allocation outside a transaction | high | mitigate | LogicException when no transaction is open | closed |
| T-02-21 | Tampering | malformed or injected scope keys | medium | mitigate | Pattern and length check, bound parameters, CHECK constraint | closed |
| T-02-22 | Repudiation | concurrency test passing vacuously | high | mitigate | Exact 1..N assertion, at least 2 pids, mutation run with unlocked allocator | closed |
| T-02-23 | Tampering | raw-SQL or package update of an issued record | high | mitigate | Trigger guard raising KP001 on update and delete | closed |
| T-02-24 | Tampering | SQL injection through DDL helper identifiers | high | mitigate | Identifier patterns and length cap validated before `sprintf` | closed |
| T-02-25 | Elevation of privilege | superuser or replica role bypassing triggers | medium | transfer | Documented requirement for the production DB role (see W-4) | closed |
| T-02-26 | Tampering | TRUNCATE skipping row triggers | medium | mitigate | `kokpit_refuse_truncate` trigger; pilot tests | closed |
| T-02-27 | Repudiation | time zone or DST misdating | medium | mitigate | UTC storage; single display time zone Europe/Prague; format tests | closed |
| T-02-28 | Information disclosure | raw translation keys or English fallback | low | accept | Locale `cs`, fallback `en`; verified in the Czech UAT walk-through | closed (accepted, AR-3) |
| T-02-29 | Information disclosure | Admin password in process list, history or output | high | mitigate | No password option; hidden prompt; read via `Env`; output tests | closed |
| T-02-30 | Elevation of privilege | second Admin through a re-run or race | high | mitigate | Pre-check plus transaction with advisory lock | closed |
| T-02-31 | Elevation of privilege | 2FA enforcement off in production | high | mitigate | ProductionConfigGuard refuses boot without `KOKPIT_REQUIRE_ADMIN_2FA` (see W-1) | closed |
| T-02-32 | Spoofing | TOTP code replay and recovery-code reuse | high | mitigate | Filament AppAuthentication with recovery codes; Redis cache lock; enforcement middleware | closed |
| T-02-33 | Elevation of privilege | mass assignment on User (roles, client_id) | high | mitigate | Fillable limited; `assignRole` after create; `forceFill` only on TOTP columns | closed |
| T-02-34 | Elevation of privilege | misuse of the 2FA reset | medium | mitigate | CLI only; `--force` or confirmation; user id only in log (see W-6) | closed |
| T-02-35 | Spoofing | bcrypt truncation past 72 bytes | low | mitigate | 72-byte password maximum enforced in install | closed |
| T-02-36 | Information disclosure | cross-client reads (IDOR, list, count) | critical | mitigate | PartnerScope global scope, fail-closed; isolation tests | closed |
| T-02-37 | Information disclosure | unknown role, missing client_id or guest treated as allowed | critical | mitigate | `1 = 0` fallback; PartnerContext; KokpitPolicy base | closed |
| T-02-38 | Elevation of privilege | system flag lost or leaked | high | mitigate | Scoped binding; restored in `finally` | closed |
| T-02-39 | Information disclosure | DB::table() or bare withoutGlobalScopes() bypassing the scope | high | mitigate | Arch test scanner with empty allowlist (see W-2) | closed |
| T-02-40 | Elevation of privilege | Partner abilities granted by omission | high | mitigate | All twelve base abilities return false; arch tests (see U-1) | closed |
| T-02-41 | Elevation of privilege | Filament pages and widgets visible by default | high | mitigate | `AccessRules` default false; enforcement traits; `strictAuthorization` (see U-2) | closed |
| T-02-42 | Information disclosure | record routes with a foreign id (IDOR) | critical | mitigate | Scope plus policy; route-walk test expects 403 or 404 | closed |
| T-02-43 | Information disclosure | global search and Livewire snapshots | high | mitigate | `globalSearch(false)`; route walk checks raw bodies | closed |
| T-02-44 | Elevation of privilege | canary Resource or harness enabled in production | high | mitigate | Config flag plus `class_exists`; production guard (see W-1) | closed |
| T-02-45 | Repudiation | registry incomplete, or attribute and behaviour disagree | medium | mitigate | Attribute drives access; registry self-check tests | closed |
| T-02-46 | Elevation of privilege | new workflow jobs | high | mitigate | `permissions: {}`, per-job read-only, no credential persistence; actionlint and zizmor clean | closed |
| T-02-47 | Tampering | third-party actions | high | mitigate | 40-character SHA pins; Dependabot for actions | closed |
| T-02-48 | Tampering | licences incompatible with AGPL-3.0 | high | mitigate | Allowlist script in CI with tests | closed |
| T-02-SC (02-12) | Tampering | setup-php, service images, Composer in CI | high | mitigate | SHA pin, version-tagged service images, `composer audit` | closed |
| T-02-49 | Information disclosure | generated CI Admin password | low | accept | Generated in-step, never echoed, passed through environment | closed (accepted, AR-4) |
| T-02-50 | Information disclosure | contact e-mails or real data in the docs | high | mitigate | Private reporting channel, no address; repository file tests; hooks | closed |
| T-02-51 | Information disclosure | public vulnerability reports | medium | mitigate | SECURITY.md points to private reporting (see W-7) | closed |
| T-02-52 | Denial of service | Admin lockout after an APP_KEY rotation or a lost device | medium | mitigate | `kokpit:admin:reset-2fa` and `APP_PREVIOUS_KEYS` documented in README | closed |

*Status: open · closed · open — below high threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above `block_on` (high) count toward `threats_open`*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-1 | T-02-06 | Development-only images are pinned by version tag, not by digest. They never run in production and no `latest` tag is used. | Petr Gräf | 2026-10-08 |
| AR-2 | T-02-08 | Queue-worker and scheduler wait until `vendor/autoload.php` exists on a fresh clone; a short delay is acceptable. Clean-clone start confirmed in UAT test 1. | Petr Gräf | 2026-10-08 |
| AR-3 | T-02-28 | Locale is `cs` with `en` fallback; a missing translation shows English, not a raw key in normal use. Czech walk-through confirmed in UAT test 2. | Petr Gräf | 2026-10-08 |
| AR-4 | T-02-49 | The CI Admin password is generated inside the step and never printed or stored; the runner is ephemeral and the account exists only in the throwaway CI database. | Petr Gräf | 2026-10-08 |

*Accepted risks do not resurface in future audit runs.*

---

## Unregistered Threat Flags and Warnings (non-blocking, for later phases)

| ID | Item | Follow-up |
|----|------|-----------|
| U-1 | `register_permission_check_method` in `config/permission.php` registers a package `Gate::before` that runs before KokpitPolicy and grants abilities for same-named permissions. Latent: nothing creates or grants permissions yet. Limit recorded in CONTRIBUTING. | Register as a threat before any phase adds permissions. |
| U-2 | Filament profile and MFA set-up pages come from panel configuration, carry no `#[AccessRule]`, and are outside the registry test. They show only the user's own data. | Record as a known exception to T-02-41. |
| W-1 | ProductionConfigGuard runs only when `APP_ENV` is exactly `production`; a public instance with another environment name skips the 2FA and canary guards (review finding WR-06). | Fix in Phase 3. |
| W-2 | The escape-hatch scanner covers only `DB::table(` and bare `withoutGlobalScopes()`, not `withoutGlobalScope(PartnerScope::class)`, `newQueryWithoutScopes` or raw `DB::select` (WR-01). No such call on a tenant table today. | Extend scanner. |
| W-3 | PartnerScope turns `activitylog:clean` and `model:prune` into silent no-ops unless run as system (WR-04). | Handle in Phases 3 and 11. |
| W-4 | The production DB role requirement (non-superuser, non-table-owner) is written only on the sending side; ROADMAP Phase 3 does not name it. | Add to Phase 3 requirement. |
| W-5 | `Money::convert` accepts a zero or negative rate (WR-10). | Validate rate. |
| W-6 | 2FA reset: case-differing e-mails pick an arbitrary account (WR-07); sessions and remember token stay valid after reset (WR-08). | Fix in a later phase. |
| W-7 | Private vulnerability reporting depends on a repository setting; the maintainer checklist does not list it. Confirmed enabled in UAT test 4. | Add to CONTRIBUTING checklist. |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-08 | 55 | 55 (51 verified, 4 accepted) | 0 | gsd-security-auditor |

Verification runs: 428 Pest tests across Isolation, Arch, Unit, Feature, Concurrency; `test-gitignore.sh` 112 passed; `test-workflow.sh` 7 passed; `scripts/check-sensitive.sh --all` exit 0; actionlint clean; `zizmor --offline` no findings.

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-08

## Security Audit 2026-10-07

| Metric | Count |
|---|---|
| Threats found | 55 |
| Closed | 55 |
| Open | 0 |
