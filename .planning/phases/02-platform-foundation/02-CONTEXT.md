# Phase 2: Platform Foundation - Context

**Gathered:** 2026-10-07
**Status:** Ready for planning

<domain>
## Phase Boundary

A running, installable Laravel + Filament application whose data conventions, money handling, numbering and Partner default-deny access are enforced by failing tests before any feature is built. Delivers FND-01 to FND-06, FND-11 to FND-14, FND-17, FND-18 and FND-20: DDEV environment, single Filament SPA panel, UUID v7 / `timestamptz` / morph map conventions with schema tests, `Money` value object, sequence allocator, Admin/Partner roles with default-deny, Czech localisation, DB constraint and immutability-trigger pattern, CI, licence files, install command with Admin 2FA, and the Partner isolation canary harness.

Typed settings, activity log, queue/health page, Zerops deploy, S3 smoke test and spikes belong to Phase 3. No client, project, task or invoice features here.

</domain>

<decisions>
## Implementation Decisions

Already fixed before this discussion (not re-opened): Laravel 13, Filament 5 (SPA, Livewire 4), PHP 8.5, PostgreSQL 18, Redis for queue/cache/sessions, RustFS S3, DDEV, UUID v7, Czech default locale, Partner as client account in the same panel, invoice number `{YYYY}{NNNN}`.

### Partner isolation
- **D-01:** One Partner account belongs to exactly one client: nullable `users.client_id` (null for Admin). A second contact of the same client gets a second account. — **Reversibility:** costly — moving to many-to-many later changes every scope, policy and canary test.
- **D-02:** Default-deny is enforced in the data layer by a shared trait/interface on tenant-scoped models plus a policy base class, both fail-closed: no authenticated user, a Partner without `client_id`, or an unknown role yields an empty result set and denied abilities. Admin is allowed through one explicit rule; every Partner permission is an explicit grant.
- **D-03:** The registry test uses a mandatory interface or attribute. Every Resource, Page, Widget and relation manager registered in the panel must declare its access rule (for example Admin-only or Partner-allowed); the test reflects over all registered classes and fails on any class without a declaration. No hand-maintained list.
- **D-04:** The canary harness starts in this phase with a small test-only tenant model that uses the isolation trait, plus two fictional clients with canary strings. Real models join the harness with one line each in later phases. No `clients` table is created in Phase 2 (that is Phase 4).

### Admin install and two-factor authentication
- **D-05:** The install command is interactive by default (e-mail, name, hidden password prompt) and accepts flags for automation. The password is never an argument; non-interactive use reads it from an environment variable. There is no default password and the command refuses to create a second Admin.
- **D-06:** Admin 2FA is mandatory from the first login: after signing in, the Admin is redirected to a 2FA setup screen and cannot reach the panel until it is enabled. Enforcement can be switched off by configuration for local development and tests only. Partner 2FA is not required.
- **D-07:** Use Filament's built-in TOTP multi-factor authentication with one-time recovery codes shown at setup. A lost device is recovered with a recovery code or with a CLI command that resets 2FA on the server. No third-party 2FA package.

### Money
- **D-08:** `Money` is a thin own value object (integer minor units plus ISO 4217 currency) with an Eloquent cast, delegating arithmetic and rounding to `brick/money` (MIT). The domain never uses the library API directly. — **Reversibility:** costly — every persisted amount and every call site uses the value object.
- **D-09:** Rounding mode is `HALF_UP`, applied in exactly one documented method, once per invoice line amount. Durations stay in exact minutes, hours times rate is computed in exact arithmetic, and no later step (sums, conversion to CZK) adds another rounding unless it produces a new document amount.
- **D-10:** Hourly rates are `Money` (minor units per hour). Exchange rates and any per-minute rate are stored as `NUMERIC(20,10)` and handled as decimals outside `Money`; only the rounded result becomes `Money`.

### Sequence allocator
- **D-11:** A counters table with `SELECT ... FOR UPDATE` inside the caller's transaction. A rollback rolls the counter back, so numbers are gap-free; PostgreSQL sequences and `MAX()+1` are rejected. Proven by real parallel-process tests on PostgreSQL. — **Reversibility:** one-way — issued numbers cannot be changed afterwards.
- **D-12:** The counter is identified by a generic scope key string, for example `invoice:2026` or `task:<project-id>`. The allocator does no resets itself: the year is part of the key and a new year is a new row starting at 1. One API serves tasks and invoices.

### Claude's Discretion
- Package migration strategy for UUID conversion (edit published migrations versus custom model subclasses), morph map key names, schema-test mechanics, CI job layout, PHPStan level, formatter and licence-allowlist tooling, DDEV daemon details and exact class/file names. Follow `.planning/research/STACK.md` and `.planning/research/PITFALLS.md`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Planning
- `.planning/ROADMAP.md` — Phase 2 goal and five success criteria
- `.planning/REQUIREMENTS.md` — FND-01 to FND-06, FND-11 to FND-14, FND-17, FND-18, FND-20
- `.planning/PROJECT.md` — Key Decisions table and constraints (security, data integrity, immutability)
- `.planning/phases/01-repository-hygiene/01-CONTEXT.md` — hygiene rules that apply to all new code and fixtures

### Research
- `.planning/research/SUMMARY.md` — stack and cross-cutting infrastructure rationale
- `.planning/research/STACK.md` — package versions, UUID migration notes, packages to avoid
- `.planning/research/ARCHITECTURE.md` — Domain Actions, DB-enforced invariants
- `.planning/research/PITFALLS.md` — Partner leakage surfaces, bigint morph columns, numbering races

### Repository rules
- `.claude/CLAUDE.md` — fictional-data-only rule, review procedure before commit
- `CONTRIBUTING.md` — contribution and hygiene procedure
- `scripts/check-sensitive.sh` — must pass on every commit of this phase

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `scripts/check-sensitive.sh`, `lefthook.yml`: hygiene tooling already guards every commit; test fixtures must use runtime-assembled fakes.

### Established Patterns
- None for application code: the repository has no Laravel app yet (greenfield). Phase 2 sets the conventions.

### Integration Points
- Laravel app is created in the repository root next to `scripts/`, `lefthook.yml`, `LICENSE` and `CONTRIBUTING.md`; `CONTRIBUTING.md` and `.gitignore` will need extending, not replacing.

</code_context>

<specifics>
## Specific Ideas

No specific requirements beyond the decisions above; open to standard Laravel/Filament approaches.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 2-Platform Foundation*
*Context gathered: 2026-10-07*
