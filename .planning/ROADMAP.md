# Roadmap: Kokpit

## Overview

Kokpit is built in dependency order so that every later phase rests on conventions that are already enforced by failing tests. Phase 1 puts repository hygiene tooling in place before anything else lands in the public repository. Phases 2-3 are the foundation: UUID v7 and `timestamptz` schema conventions, money, gap-free numbering, default-deny Partner isolation with its canary test harness, then operations, deploy, storage and the technology spikes. Phases 4-8 deliver the working-day flow: clients and projects, tasks and kanban, time tracking, the time API, exchange rates and reports. Phases 9-11 close the money loop (documents and finance, invoicing, Stripe payments), which is the core value: tracked time becomes an issued, payable invoice in one pass, with no unbilled time or unpaid invoice slipping through unnoticed. Phase 12 audits the Partner view across every screen, route and endpoint.

**Mapping notes** (how the 97 v1 requirements were assigned, each to exactly one phase):

- Default rule: a requirement is traced to the phase that delivers the entity or capability it describes. Panels that need later modules are wired by the later phase's success criteria (listed below) and do not change the traced phase.
- Traced to the phase where the requirement first becomes fully observable: PR-05 to Phase 6 (needs tasks and time), CL-03 and FI-03 to Phase 10 (need invoices, documents and invoice payments), US-01 to Phase 12 (the permission matrix is only complete when every entity exists; the mechanism is built in Phase 2).
- Dashboard (RE-04) is delivered in Phase 8 with hours, unbilled per client and deadlines. The overdue-invoices tile is wired in Phase 10 and the unresolved-payments tile in Phase 11.
- System page (FND-10) is built in Phase 3. Its last-rate-date, unsent-invoice-e-mail and unprocessed-webhook indicators are wired in Phases 8, 10 and 11.
- File attachments on projects, tasks and comments (the "files" part of PR-01, TA-01 and TA-04) are delivered once, with download route and Partner visibility rules, by the Documents work in Phase 9 (DO-01). Confirmation before deleting an issued invoice PDF (part of DO-03) is proven in Phase 10.
- PR-02 key suggestion and uniqueness are in Phase 4; the freeze after the first task is proven in Phase 5. TI-05 automatic billing and the TI-08 rate/amount snapshot on billing are exercised in Phase 10 (IN-02, IN-03). The invoice reference on transactions (FI-01) is added in Phase 10.
- Foundation is split into Phases 2 and 3 because its 19 requirements cannot be verified through 2-5 observable criteria in one phase. The isolation harness (FND-18) stays in Phase 2. Invoicing (Phase 10) is the largest feature phase and is expected to be planned as several plans in waves (for example issue/numbering/snapshots/PDF, then bill-from-time, then payments and Partner view).
- Numbering note: the brief calls hygiene "step 0" and foundation "phase 1". In this roadmap hygiene is Phase 1 and the brief's foundation is Phases 2-3, so a brief reference to "phase 1" (queue driver and S3 provider decisions) now means Phase 3.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Repository Hygiene** - Tooling that keeps secrets and real data out of the public repository, in place before anything else is committed (completed 2026-10-08)
- [x] **Phase 2: Platform Foundation** - Installable Laravel + Filament app with enforced UUID/timestamp/money/numbering conventions, Czech UI and default-deny Partner isolation (completed 2026-10-08)
- [ ] **Phase 3: Operations Foundation** - Typed settings, audit trail, resilient background jobs, health page, release deploy, private storage and technology spikes
- [ ] **Phase 4: Clients and Projects** - Clients, contacts, ARES lookup, Partner invitations, and projects with keys, billing terms and client visibility
- [ ] **Phase 5: Tasks and Kanban** - Tasks and subtasks with per-project keys, comments, list filters, drag-and-drop boards and Partner task access
- [ ] **Phase 6: Time Tracking** - Always-visible timer, manual entries, exact durations, billed locking, timesheet and project time totals
- [ ] **Phase 7: REST API** - Token-secured `/api/v1` for time entries, timer and lookups with the same rules as the UI
- [ ] **Phase 8: Exchange Rates and Reports** - Daily CNB rates, time and billing reports in client currency and CZK, exports, work report PDF and dashboard
- [ ] **Phase 9: Documents and Finance** - Files on private S3 with Partner visibility rules, central document listing, and income/expense overview in CZK
- [ ] **Phase 10: Invoicing** - Bill-from-time drafts, numbered immutable invoices, proformas and credit notes, PDF with QR, e-mail, manual payment and Partner invoice view
- [ ] **Phase 11: Stripe Payments** - Payment links, signed idempotent webhooks, automatic matching, and duplicate and unmatched payment handling
- [ ] **Phase 12: Partner Audit** - Automated proof that the Partner view leaks nothing and that every route, screen and endpoint has an explicit role rule

## Phase Details

### Phase 1: Repository Hygiene

**Goal**: Nothing sensitive can enter the public repository, and the tooling that guarantees it exists before any application code or planning docs are committed
**Depends on**: Nothing (first phase)
**Requirements**: HYG-01, HYG-02, HYG-03, HYG-04, HYG-05, HYG-06, HYG-07
**Success Criteria** (what must be TRUE):
  1. Staging a file that contains a non-example e-mail, an 8-digit company-ID-like number, an IBAN or account number, a public IP, a hosting hostname or a key prefix makes `scripts/check-sensitive.sh` fail and name the file and line; a clean staged change passes
  2. With `KOKPIT_DENYLIST` pointing at a local file outside the repository, terms from that file also block a commit; without the variable (as in CI) the script still runs with generic patterns only
  3. After running `scripts/install-hooks.sh`, committing a staged secret is rejected locally by the lefthook pre-commit hook (sensitive-content check plus gitleaks), and env files, local AI/IDE settings, storage, logs, dumps, exports and the local-data directory stay untracked
  4. The CI step scanning full git history with `.gitleaks.toml` and the same sensitive-content checks fails on a planted fake secret and passes on a clean branch
  5. `CLAUDE.md` and CONTRIBUTING state the fictional-data-only rule and the review procedure (`git status`, `git diff --staged`, script run), and GitHub secret scanning with push protection is enabled and documented

**Plans:** 11/11 plans complete (01-07 to 01-11 are gap closure from 01-VERIFICATION.md)

Plans:
**Wave 1**
- [x] 01-01-PLAN.md — Tracer: staged fake key blocked by the lefthook-wired scanner; hardened CI with `CI Passed`; hook installed (wave 1)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 01-02-PLAN.md — Full D-02 generic rule set with reviewed allowlist and mawk parity (wave 2)
- [x] 01-03-PLAN.md — Local denylist (`KOKPIT_DENYLIST`), explicit files and `--history` mode, CI history step (wave 2)
- [x] 01-04-PLAN.md — Fictional-data rule in CLAUDE.md and CONTRIBUTING, GitHub settings checklist, `.gitignore` review (wave 2)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 01-05-PLAN.md — gitleaks in hook and pinned CI full-history scan; workflow lint gate, Dependabot, CODEOWNERS (wave 3)

**Wave 4** *(blocked on Wave 3 completion)*
- [x] 01-06-PLAN.md — Phase gate on the real repository; owner decision on unpushed history and codebase map (wave 4, checkpoint)

**Gap closure wave 1** *(from 01-VERIFICATION.md, gaps 1 to 3)*
- [x] 01-07-PLAN.md — Content-complete scanner input: attributes, NUL bytes, UTF-16, textconv, type changes, renames and merge commits can no longer hide content (gap 1, CR-01, WR-02, WR-05)
- [x] 01-08-PLAN.md — Second layer: git-attributes guard, CI gitleaks with text and merge diffs, CODEOWNERS for the attribute file and hygiene docs (gap 1, WR-02, WR-06)

**Gap closure wave 2** *(blocked on gap closure wave 1)*
- [x] 01-09-PLAN.md — Denylist folds diacritics on both sides, locale-free, and scans the exempt paths (gap 2, CR-02, WR-01, WR-08)
- [x] 01-10-PLAN.md — Home paths in both layers incl. Windows form; boundary, IČ, ASIA, interval self-test and narrowed planning exemption (gap 3, CR-03, WR-03, WR-04, WR-07, IN-03)

**Gap closure wave 3** *(blocked on gap closure wave 2)*
- [x] 01-11-PLAN.md — CONTRIBUTING matches the code, credential ignore rules, decision for every review finding, full phase gate (WR-06 checklist, WR-09)

### Phase 2: Platform Foundation

**Goal**: A running, installable Laravel + Filament application whose data conventions, money handling, numbering and Partner default-deny access are enforced by failing tests before any feature is built
**Depends on**: Phase 1
**Requirements**: FND-01, FND-02, FND-03, FND-04, FND-05, FND-06, FND-11, FND-12, FND-13, FND-14, FND-17, FND-18, FND-20
**Success Criteria** (what must be TRUE):
  1. A fresh checkout following only the README and `.env.example` starts with `ddev start` (versioned `.ddev/` config: PHP 8.5, PostgreSQL 18, Redis, Mailpit, RustFS S3 storage, queue worker and scheduler) and boots on PostgreSQL; the install command creates the Admin without a default password; the Admin enables two-factor authentication, logs in to the single Filament SPA panel and sees Czech text (Filament, validation, enum labels) with Czech date, number and currency formats and times shown in `Europe/Prague`
  2. Every push runs CI on PostgreSQL with tests, static analysis, formatting, secret scan and a dependency licence allowlist (AGPL-compatible only), and the repository carries `LICENSE` (AGPL-3.0, matching `composer.json`), README, SECURITY.md, CONTRIBUTING.md and an `.env.example` that stays in sync
  3. Schema tests fail the build if any own or package table (media, tags, activity log, permission pivots, Sanctum tokens, webhook calls, sessions) has a non-UUID v7 key or morph column, a timestamp that is not `timestamptz`, or a morph type outside the enforced morph map; a pilot immutability trigger, CHECK and partial index are proven by raw-SQL tests
  4. Money is stored as integer minor units plus ISO 4217 currency through one value object with a single documented rounding point, and the sequence allocator hands out gap-free, duplicate-free numbers under real parallel-process tests on PostgreSQL
  5. A Partner test account sees nothing by default (policies plus global scopes, not UI hiding); the canary harness with two fictional clients passes; a registry test fails when any Resource, Page, Widget or relation manager lacks an explicit access rule

**Plans:** 13/13 plans complete (strictly sequential: every plan from 02-02 on runs Pest in the single DDEV project against the shared `kokpit_test` database)

Plans:
**Wave 1**
- [x] 02-01-PLAN.md — Skeleton merge through the hygiene gate; owner decisions on the composer.lock allowlist and the SPDX licence id (wave 1, checkpoints)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 02-02-PLAN.md — DDEV (PHP 8.5, PostgreSQL 18, Redis, Mailpit, RustFS, daemons), guarded PostgreSQL test harness, `.env.example` sync, Pint and Larastan level 8 (wave 2)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 02-03-PLAN.md — UUID v7, timestamptz and enforced morph map on core and permission tables, catalogue schema tests (wave 3)

**Wave 4** *(blocked on Wave 3 completion)*
- [x] 02-04-PLAN.md — Sanctum, notifications, webhook calls, media, tags and activity log under the conventions; rule R9 (wave 4)

**Wave 5** *(blocked on Wave 4 completion)*
- [x] 02-05-PLAN.md — Money value object over brick/money with one HALF_UP rounding point and a two-column cast (wave 5)

**Wave 6** *(blocked on Wave 5 completion)*
- [x] 02-06-PLAN.md — Sequence allocator with real parallel-process proof; owner confirms the counter contract (wave 6, checkpoint)

**Wave 7** *(blocked on Wave 6 completion)*
- [x] 02-07-PLAN.md — Immutability trigger pattern, constraint pilot and raw-SQL test helper (wave 7)

**Wave 8** *(blocked on Wave 7 completion)*
- [x] 02-08-PLAN.md — Czech localisation and Europe/Prague display (wave 8)

**Wave 9** *(blocked on Wave 8 completion)*
- [x] 02-09-PLAN.md — Roles, `kokpit:install`, mandatory Admin TOTP and CLI reset (wave 9)

**Wave 10** *(blocked on Wave 9 completion)*
- [x] 02-10-PLAN.md — Partner default-deny data layer, policy base and model declarations (wave 10)

**Wave 11** *(blocked on Wave 10 completion)*
- [x] 02-11-PLAN.md — Panel access declarations, registry test and canary harness with route walk (wave 11)

**Wave 12** *(blocked on Wave 11 completion)*
- [x] 02-12-PLAN.md — CI jobs (tests on PostgreSQL 18, static analysis, dependencies with licence allowlist) behind `CI Passed` (wave 12)

**Wave 13** *(blocked on Wave 12 completion)*
- [x] 02-13-PLAN.md — README, SECURITY.md, CONTRIBUTING development conventions and the phase gate with manual checks (wave 13)

### Phase 3: Operations Foundation

**Goal**: The app is operable and deployable: settings, audit trail, background work, health visibility, release deploy and file storage all work and fail loudly rather than silently
**Depends on**: Phase 2
**Requirements**: FND-07, FND-08, FND-09, FND-10, FND-15, FND-16, FND-19
**Success Criteria** (what must be TRUE):
  1. Admin edits typed settings (supplier data, bank accounts per currency, VAT mode, default rate and currency, payment terms, numbering patterns, online-payment toggle) in Czech, and changes to allowlisted attributes of tasks, projects, invoices and time entries appear in the activity log while non-allowlisted attributes never do
  2. A deliberately failing queued job is retried with backoff, ends up in failed jobs and raises an Admin alert that does not depend on the queue; the Admin System page shows failed jobs, oldest pending job and scheduler heartbeat, with slots for last rate date, unprocessed webhooks and unsent invoice e-mails that later phases fill
  3. A published release or a manual dispatch deploys to Zerops through the protected `production` environment with manual approval, no other trigger can deploy, `zerops.yml` contains no secrets and describes build, deploy, worker, scheduler and migrations, and the manual GitHub settings checklist is documented
  4. Configuring S3-compatible private storage through environment variables only, a smoke test uploads a file and fetches it through a temporary URL
  5. Spike results are recorded as decisions: PDF engine (multi-page report with Czech diacritics and QR), kanban library versus custom board (queue driver Redis, PostgreSQL 18 and RustFS S3 storage are already decided)

**Plans:** 16/19 plans executed (the two spikes run in parallel in wave 1; every application plan after them is serialized because each runs Pest in the single DDEV project against the shared `kokpit_test` database)
**UI hint**: yes

Plans:
**Wave 1**
- [x] 03-01-PLAN.md — PDF engine spike outside the repository: Dompdf versus the Chromium engine behind spatie/laravel-pdf, decision record (FND-19, D-14, D-16)
- [x] 03-02-PLAN.md — Kanban spike outside the repository: custom wire:sort board versus Flowforge, build-or-buy decision record (FND-19, D-15, D-16)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 03-03-PLAN.md — Tracer: UUID v7 settings storage with fail-closed Partner scope, system-context settings migrations, supplier settings (FND-07, D-01)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 03-04-PLAN.md — Admin-only Czech settings page with the supplier tab, data-layer validation, boot-time 403 before mount() (FND-07, D-02)

**Wave 4** *(blocked on Wave 3 completion)*
- [x] 03-05-PLAN.md — Default rate and currency via a no-rounding Money::fromMajor (FND-07, D-02)

**Wave 5** *(blocked on Wave 4 completion)*
- [x] 03-06-PLAN.md — VAT mode, payment terms and online-payment toggle, one-transaction save across tabs (FND-07, D-02)

**Wave 6** *(blocked on Wave 5 completion)*
- [x] 03-07-PLAN.md — Bank accounts per currency with format-driven fields and an own IBAN rule (FND-07, D-03, D-04)

**Wave 7** *(blocked on Wave 6 completion)*
- [x] 03-08-PLAN.md — Numbering engine: token grammar, SequenceAllocator::peek preview, fixed KEY-N task numbers (FND-07, D-05)

**Wave 8** *(blocked on Wave 7 completion)*
- [x] 03-09-PLAN.md — Numbering patterns on the settings page with live previews and the locked task pattern (FND-07, D-05)

**Wave 9** *(blocked on Wave 8 completion)*
- [x] 03-10-PLAN.md — Activity log allowlist attribute and source labels with null causer (FND-08, D-06, D-08)

**Wave 10** *(blocked on Wave 9 completion)*
- [x] 03-11-PLAN.md — Allowlist architecture test, refused pruning, production guard for the activity log (FND-08, D-06, D-09)

**Wave 11** *(blocked on Wave 10 completion)*
- [x] 03-12-PLAN.md — Admin activity overview and reusable history relation manager, boot-time denial for relation managers and widgets (FND-08, D-07)

**Wave 12** *(blocked on Wave 11 completion)*
- [x] 03-13-PLAN.md — Job base with retries, backoff and idempotence declaration; queue-independent throttled Admin alerts (FND-09, D-10, D-11)

**Wave 13** *(blocked on Wave 12 completion)*
- [x] 03-14-PLAN.md — Job contract: after-commit dispatch, system-context reads, architecture test, production queue guard (FND-09, D-10)

**Wave 14** *(blocked on Wave 13 completion)*
- [x] 03-15-PLAN.md — Health indicator registry with six slots and the Admin System page (FND-10, D-12, D-13)

**Wave 15** *(blocked on Wave 14 completion)*
- [x] 03-16-PLAN.md — Real failed-jobs, oldest-pending and scheduler indicators, scheduler and worker heartbeats (FND-10, FND-09, D-12, D-13)

**Wave 16** *(blocked on Wave 15 completion)*
- [ ] 03-17-PLAN.md — Private S3 storage check command and s3 test group against RustFS locally and in CI (FND-16, D-17)

**Wave 17** *(blocked on Wave 16 completion)*
- [ ] 03-18-PLAN.md — zerops.yml with once-per-deploy migrations, protected release deploy workflow, settings checklist, Zerops rehearsal checklist (FND-15, D-18)

**Wave 18** *(blocked on Wave 17 completion)*
- [ ] 03-19-PLAN.md — Conventions and operations documentation, per-phase .gitignore review and the phase gate

### Phase 4: Clients and Projects

**Goal**: Admin can maintain clients, contacts and projects with their billing terms, and invite a client to a restricted Partner account that sees only projects flagged visible to it
**Depends on**: Phase 3
**Requirements**: US-02, CL-01, CL-02, CL-04, CL-05, PR-01, PR-02, PR-03, PR-04
**Success Criteria** (what must be TRUE):
  1. Admin creates, edits and archives clients with billing data, stage, currency, rate, payment terms, invoice e-mail and language, online-payment flag, tags and several contacts with primary and billing flags; archived clients disappear from pickers and lists but can be restored
  2. Entering a fictional company ID and pressing the ARES button fills the client form; on a lookup error or timeout the message appears next to the field and the form stays unchanged
  3. Admin creates projects with name, status, description, dates, priority, tags, billing type, hourly rate, fixed price and time estimate; a unique 2-6 letter uppercase key is suggested from the name and a duplicate key is rejected
  4. From the client detail Admin invites a Partner account by e-mail, and the invited person sets a password and logs in
  5. A Partner sees only projects of their own client that are flagged client-visible, never sees rates, prices or estimates in lists, details, selects or search, and never sees another client's canary data

**Plans**: TBD
**UI hint**: yes

### Phase 5: Tasks and Kanban

**Goal**: Admin organises work as tasks and subtasks with per-project keys and a drag-and-drop board, and a Partner can raise and discuss tasks in visible projects without seeing anything internal
**Depends on**: Phase 4
**Requirements**: TA-01, TA-02, TA-03, TA-04, TA-05, TA-06, TA-07, KB-01, KB-02, KB-03
**Success Criteria** (what must be TRUE):
  1. Admin creates tasks and one-level subtasks with title, status, description, dates, priority, assignee, tags and a todo checklist, plus per-task billing type, fixed price, rate override and estimate
  2. Each new task receives the next `KEY-N` from its project counter; creating tasks in parallel never duplicates or skips a number, deleted numbers are never reused, a task can be found and opened by its key in search and URL, and the project key can no longer be changed after the first task
  3. Admin filters the list by client, project, status, priority, assignee, tag and due date, and adds comments to tasks and subtasks, optionally marked internal
  4. Admin drags cards on the per-project and the global kanban board; status and position persist immediately and survive a reload, and the global board filters by client, assignee, tag and priority
  5. A Partner creates tasks and comments in visible projects but cannot change status or priority or move cards, sees a read-only task list, never sees internal comments or another client's tasks, and Admin is notified of Partner tasks and comments

**Plans**: TBD
**UI hint**: yes

### Phase 6: Time Tracking

**Goal**: Admin tracks exact time against clients, projects and tasks with a timer or manual entries and always knows what is billable, billed and unbilled
**Depends on**: Phase 5
**Requirements**: TI-01, TI-02, TI-03, TI-04, TI-05, TI-06, TI-07, TI-08, TI-09, PR-05
**Success Criteria** (what must be TRUE):
  1. A timer is visible on every screen and can be started from a task in at most two clicks; starting a timer stops the running one, and concurrent starts never leave two running timers for one user
  2. Admin creates and edits entries manually (from, to, client, project, task, description), can save an entry with only a client, and the database rejects entries whose client, project and task disagree or whose end is before the start
  3. The billable flag defaults to true, is pre-set to false for non-billable projects and tasks and can be overridden; entries marked billed manually or in bulk are locked until billing is cancelled; durations are exact seconds and the effective rate resolves task, then project, then client, then global default
  4. Admin sees a daily and weekly timesheet with totals, and a timer left running unusually long is flagged to the user
  5. Project detail (Admin only) shows tasks, estimate versus actual and billed versus unbilled time, and a Partner sees no time, rates or prices anywhere, proven by the canary tests

**Plans**: TBD
**UI hint**: yes

### Phase 7: REST API

**Goal**: External tools can read and write time entries and drive the timer through a versioned, token-secured API that applies the same rules as the UI
**Depends on**: Phase 6
**Requirements**: AP-01, AP-02, AP-03, AP-04, AP-05
**Success Criteria** (what must be TRUE):
  1. Admin creates and revokes API tokens with `time:read` and `time:write` abilities in a token management screen, a Partner cannot create tokens, and a request whose token lacks the needed ability is refused
  2. An API client lists time entries with filters and creates, reads, updates and deletes them, and the API refuses what the UI refuses (locked billed entries, inconsistent client/project/task, end before start)
  3. An API client starts and stops the timer and reads the currently running one, and repeating a request with the same idempotency key does not create a duplicate
  4. An API client resolves clients, projects and tasks by UUID, key and name through read-only lookups, and addresses a task by its `KEY-N`
  5. Excess requests receive a rate-limit response, and published OpenAPI documentation describes every endpoint

**Plans**: TBD
**UI hint**: yes

### Phase 8: Exchange Rates and Reports

**Goal**: Admin sees where time and money stand per client, project and period in client currency and CZK, can export and hand over work reports, and CNB rates feed every conversion
**Depends on**: Phase 6
**Requirements**: EX-01, EX-02, EX-03, RE-01, RE-02, RE-03, RE-04, RE-05
**Success Criteria** (what must be TRUE):
  1. A scheduled daily job downloads CNB rates idempotently (a rerun creates no duplicates), weekends and holidays use the previous rate, per-unit amounts (for example 100 units) convert correctly, and Admin can run the download manually and backfill history
  2. Admin sees a rates overview; when a download fails Admin is alerted without depending on the queue, the last known rate keeps being used, and the System page shows the last rate date; a conversion helper returns the CZK amount for a given currency and date
  3. Admin sees a time report per client and project for a period with breakdown by task, day and user, split billable versus non-billable and billed versus unbilled with amounts in client currency and CZK, and the effective hourly rate for fixed-price projects
  4. Admin requests a CSV or XLSX export that is built in the background and delivered by notification with spreadsheet-safe cells, and generates a multi-page PDF work report with correct Czech diacritics
  5. The dashboard shows today's and this week's hours, unbilled time per client and upcoming task deadlines

**Plans**: TBD
**UI hint**: yes

### Phase 9: Documents and Finance

**Goal**: Files live on private storage under Admin control with correct Partner visibility, and Admin can record income and expenses and see the money picture in CZK
**Depends on**: Phase 8
**Requirements**: DO-01, DO-02, DO-03, FI-01, FI-02
**Success Criteria** (what must be TRUE):
  1. Admin uploads files to clients, projects, tasks, comments, transactions or leaves them unattached, including the files on projects and tasks that those forms offer
  2. A Partner sees and downloads only files flagged visible on projects and tasks they can access, never attachments of internal comments and never another client's files
  3. Admin uses a central document listing with filters and sorting, downloads single files or a ZIP built in the background, bulk-deletes files, and sees storage totals overall and per client
  4. Every download goes through the app with a permission check and a short-lived signed URL (no public bucket URL), and deleting a record also removes its stored object
  5. Admin records income and expense transactions with hierarchical categories, currency, CZK amount, optional client and project and receipts as attachments, and sees an overview per month and year, per category and per client in CZK

**Plans**: TBD
**UI hint**: yes

### Phase 10: Invoicing

**Goal**: Admin turns unbilled time into an issued, immutable, payable invoice in one pass and follows it through payment, and Partner sees their own issued documents
**Depends on**: Phase 6, Phase 8, Phase 9
**Requirements**: IN-01, IN-02, IN-03, IN-04, IN-05, IN-06, IN-07, IN-08, IN-09, IN-10, CL-03, FI-03
**Success Criteria** (what must be TRUE):
  1. Admin creates a draft from unbilled billable time (client, period, projects, grouping per project or task, fixed sums) plus manual items and edits the items; two drafts can never bill the same time entry; cancelling or deleting a draft, or storno, returns the entries to unbilled and unlocked
  2. Issuing allocates the next number only at issue from separate invoice, proforma and credit-note series (gap-free under concurrent issuing), freezes supplier, customer and bank snapshots, rates, amounts and the CNB rate with CZK total for client-currency invoices, and afterwards only payment state and internal note can change, even through raw SQL; non-VAT-payer mode is the default
  3. At issue a PDF is generated from the snapshots only, in the client's invoice language, with a SPAYD QR code for CZK bank transfer, stored in documents (deleting it needs explicit confirmation), and sent by queued e-mail with an optional work report; unsent invoice e-mails show on the System page
  4. Admin records a manual payment (transfer or cash) with a warning if already paid, which creates an income transaction with editable date and category; overdue status is derived and listed in an overdue overview and as a dashboard tile; a paid proforma converts to a linked invoice
  5. The client detail shows projects, unbilled time and amount, invoices, documents and client accounts, and a Partner sees only their own issued invoices and proformas (list, detail, PDF) read-only, never drafts, internal notes, linked time or rates

**Plans**: TBD
**UI hint**: yes

### Phase 11: Stripe Payments

**Goal**: Issued invoices can be paid by card through Stripe, payments are matched automatically, and duplicate or unmatched payments are surfaced instead of lost
**Depends on**: Phase 10
**Requirements**: PA-01, PA-02, PA-03, PA-04, PA-05, PA-06, PA-07, PA-08
**Success Criteria** (what must be TRUE):
  1. Issuing an invoice with online payment enabled creates a single-use Stripe Payment Link in a background job; a Stripe failure never blocks issuing and alerts Admin; online payment can be disabled per client and per document
  2. A "Pay by card" button appears in the e-mail, the PDF and the invoice detail (Admin and Partner) and is hidden once paid; the link is deactivated when the invoice is paid by any route or stornoed, and can be regenerated after the old one is deactivated
  3. The webhook endpoint rejects a bad signature, stores a valid event under a unique event id, answers immediately and processes it in a job; a repeated event is ignored
  4. A matching payment (invoice id, with link id as fallback, correct amount and currency) creates the payment, marks the invoice paid, creates the income transaction, deactivates the link and notifies Admin; a second payment of the same invoice is stored as a duplicate with no income or invoice change and alerts Admin
  5. Unmatched payments and refund events are recorded and alerted, Admin works through a payments overview with duplicate and unmatched filters and manual resolution, unresolved payments show as a dashboard tile and unprocessed webhooks on the System page, and old webhook events are pruned

**Plans**: TBD
**UI hint**: yes

### Phase 12: Partner Audit

**Goal**: The Partner view is proven airtight across every screen, route and endpoint before release
**Depends on**: Phase 11
**Requirements**: US-01, AUD-01, AUD-02
**Success Criteria** (what must be TRUE):
  1. An automated matrix test enumerates every Resource, Page, Widget, relation manager and API endpoint against Admin and Partner and confirms each behaves as the permission matrix specifies
  2. Canary tests prove a Partner never sees time, rates, prices, finance, internal comments or another client's data in the UI, global search, selects, exports, notifications, activity log, downloads and API, for every entity
  3. A route audit confirms every non-public route requires authentication, the public routes are an explicit allowlist, and an anonymous request to any other route is refused
  4. The audit suite runs in CI and fails the build when a new screen, route or endpoint is added without an explicit role rule

**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Repository Hygiene | 11/11 | Complete    | 2026-10-08 |
| 2. Platform Foundation | 13/13 | Complete    | 2026-10-08 |
| 3. Operations Foundation | 16/19 | In Progress | - |
| 4. Clients and Projects | 0/0 | Not started | - |
| 5. Tasks and Kanban | 0/0 | Not started | - |
| 6. Time Tracking | 0/0 | Not started | - |
| 7. REST API | 0/0 | Not started | - |
| 8. Exchange Rates and Reports | 0/0 | Not started | - |
| 9. Documents and Finance | 0/0 | Not started | - |
| 10. Invoicing | 0/0 | Not started | - |
| 11. Stripe Payments | 0/0 | Not started | - |
| 12. Partner Audit | 0/0 | Not started | - |
