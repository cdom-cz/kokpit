# Pitfalls Research

**Domain:** Single-tenant open-source CRM/ERP (time tracking, invoicing, Stripe payments) on Laravel + Filament SPA panel + PostgreSQL, with a restricted client role (Partner) sharing the admin panel, deployed to Zerops from a public GitHub repo
**Researched:** 2026-10-06
**Confidence:** MEDIUM-HIGH. Stripe, Laravel 12 UUIDv7, CNB, Zerops `zsc`/cron, Sanctum and Spatie UUID guidance were verified against official docs fetched during this research. Filament-internal behaviours (global search scoping, Livewire state contents, export job auth context), Zerops worker topology, Payment Link `price_data` rules and the Spatie package morph column details are from training knowledge and are tagged **[verify]**. Each tagged item should become an explicit test or a phase-research spike, not an assumption.

All example data below is fictional (`example.com`, "Acme Studio s.r.o.", company ID `12345678`).

Phase names below are the Active requirement groups from PROJECT.md (Repo hygiene, Foundation, Clients, Projects/Tasks, Kanban, Time tracking, REST API, CNB rates, Reports, Documents, Finance, Invoicing, Stripe, Partner audit). The roadmapper assigns numbers.

**Top 5 cross-cutting findings the roadmap must respect**

1. The Partner-isolation harness (canary fixtures + a test that walks every Filament Resource, Page, Widget, RelationManager, action and API route as a Partner) must start in **Foundation** and grow per phase. If it is only built in the final "Partner audit" phase, every earlier phase ships leaks that are expensive to retrofit.
2. Five "schema conventions" are cheap on day one and a rewrite later: uuid-typed morph columns everywhere, `timestamptz` everywhere (Laravel's default `timestamp()` is NOT timestamptz on PostgreSQL), a single money value object, a single sequence-allocation service, and DB-level immutability triggers. All five belong in **Foundation**, with architecture tests that fail CI on violations.
3. `spatie/laravel-webhook-client`'s default signature validator does NOT understand Stripe's `Stripe-Signature` (`t=...,v1=...`) scheme. A custom validator (or `stripe-php`'s `Webhook::constructEvent`) is mandatory.
4. Stripe Payment Links are reusable by default. "One-time" needs `restrictions.completed_sessions.limit = 1` plus deactivation on the Kokpit side, and duplicate-payment handling must still exist.
5. Zerops' own Git integration can deploy on push and would bypass the GitHub `production` environment approval gate. The deploy path must be GitHub Actions (+ `zcli`) only.

---

## Critical Pitfalls

### Pitfall 1: UUID v7 keys with bigint morph / foreign-key columns left behind by packages

**What goes wrong:**
Own tables use `uuid` primary keys, but package and framework migrations still create `bigint` columns: `morphs()` / `nullableMorphs()` in Spatie activitylog (`subject`, `causer`), medialibrary (`model`), Spatie tags (`taggables.taggable_id`), Laravel/Filament notifications (`notifiable`), Spatie permission (`model_has_roles.model_id`, `model_has_permissions.model_id`, role/permission ids), Sanctum (`tokenable`), plus `foreignId('user_id')` in the default `sessions` migration and in Filament's `exports` / `imports` / `failed_import_rows` tables. `php artisan migrate` succeeds. The failure appears at the first runtime insert: `invalid input syntax for type bigint: "0190..."`. Mixed states are worse: a morph column typed `uuid` that points at one model still keyed by bigint (e.g. a package model you forgot to subclass) fails only for that model.

**Why it happens:**
Package migrations are published once and nobody re-reads them; the failure is lazy (first write); package docs say "modify the migration if you use UUIDs" without a checklist (Spatie permission docs explicitly say it is "NOT A FULL LESSON ON HOW TO IMPLEMENT UUIDs" and that you must edit the published migration in two places). Package models (`Activity`, `Media`, `Tag`, `Role`, `Permission`, `WebhookCall`, Sanctum `PersonalAccessToken`) do not use `HasUuids` unless you subclass them AND register the subclass in each package's config (`activitylog.activity_model`, `media-library.media_model`, `permission.models.role|permission`, tags model config, `webhook-client.configs[].webhook_model`, `Sanctum::usePersonalAccessTokenModel()`). Forgetting the registration leaves the package using its own non-UUID base model, which inserts a row without an id.

**How to avoid:**
- Laravel 12+ `HasUuids` already generates UUIDv7 (verified: Laravel 12 upgrade guide; `HasVersion7Uuids` was removed, `HasVersion4Uuids` is the opt-out). Do not add a second v7 library. Use `uuid('id')->primary()` and `foreignUuid()->constrained()`; use `uuidMorphs()` / `nullableUuidMorphs()` for every morph.
- Edit every published package migration in the Foundation phase, in one commit, with a checklist: activitylog, permission (pivot `model_id` to uuid, optionally rename config `model_morph_key` to `model_uuid`), medialibrary, tags, notifications, Sanctum `tokenable`, sessions `user_id`, Filament exports/imports, webhook_calls.
- Decide explicitly which package-internal tables keep bigint primary keys (jobs, failed_jobs, activity_log.id, webhook_calls.id, personal_access_tokens.id are fine as bigint) versus which must be uuid. The brief says "UUID v7 keys everywhere incl. package morph columns": the morph *owner* columns must be uuid; package-internal surrogate keys may stay bigint. Write that decision into the Key Decisions table.
- `Relation::enforceMorphMap([...])` from day one. Without it, `*_type` stores FQCNs; renaming a class later orphans years of activity-log and media rows.
- Add an **architecture test** (Foundation) that introspects the PostgreSQL catalog: every `*_type`/`*_id` morph pair has a `uuid` id column; no `bigint` column is named `*_id` and references a uuid table; every model in the morph map uses `HasUuids`; every package model class referenced from config is the subclass. This test is the real prevention.
- If Zerops offers PostgreSQL 18+, optionally add `DEFAULT uuidv7()` on primary keys as a safety net for inserts that bypass Eloquent (native `uuidv7()` arrived in PostgreSQL 18) **[verify Zerops PG version]**.

**Warning signs:**
`invalid input syntax for type bigint/uuid` in logs; `null value in column "id"` on a package table; Filament global search or table search throwing `operator does not exist: uuid ~~* unknown` (searching the uuid `id` column with ILIKE: never make `id` searchable); route binding with a malformed id returning 500 instead of 404 (test it).

**Phase to address:** Foundation (schema conventions + architecture test). Re-verified when each package is introduced (Documents for medialibrary, Clients for tags, REST API for Sanctum).

---

### Pitfall 2: Filament SPA mode (`->spa()`) quirks that break the timer, kanban and custom JS

**What goes wrong:**
SPA mode is built on Livewire `wire:navigate`. Pages are swapped without a full reload, so:
- `DOMContentLoaded` handlers and inline `<script>` tags in custom Blade views run once or not at all; code that must run per page needs `livewire:navigated` or Alpine `x-init`.
- `setInterval`/`addEventListener` registered in global scripts accumulate on every navigation: the always-visible timer ticks 2x, 3x, ... after navigating around, or keeps ticking against a stale element.
- Third-party JS (SortableJS for kanban) is destroyed by Livewire DOM morphing unless the container is `wire:ignore` and re-initialised on `livewire:navigated`.
- If prefetch is enabled (`spa(hasPrefetching: true)`, hover prefetch), GET pages are fetched speculatively. Any page or link that has a side effect on GET (mark notification read, "start timer" link) fires on hover.
- Session expiry produces 419/redirect behaviour inside a persisted shell; the timer component polling after logout keeps erroring or shows stale data. After login as a different role in the same browser (Admin then Partner) persisted DOM can show the previous user's header data until a hard reload.
- Third-party Filament plugins lag behind Filament majors and are frequently not SPA-safe; code and AI-generated snippets from the v3 era (`Forms\Form`, `Tables\Table` signatures) do not compile on v4/v5 (`Schema`-based API).

**Why it happens:**
SPA mode is a one-line toggle that hides a different page lifecycle. Developers test by clicking once and never navigate 10 times.

**How to avoid:**
- Implement the timer as one Alpine component in a panel render hook with state from the server (`started_at` + server-time offset), cleaned up in Alpine `destroy`; no global `setInterval`. Include a Playwright/Dusk test: navigate across 10 pages, assert a single ticking interval and correct elapsed seconds.
- GET routes are side-effect-free; state changes are Livewire actions or POST. Leave prefetching off unless audited.
- Kanban: custom Livewire page with `wire:ignore` + init on `livewire:navigated`; server is source of truth after each move.
- Always full-reload on login/logout (`redirect(..., navigate: false)`), and gate the timer widget behind `auth()->check()`.
- Pin the exact Filament/Livewire versions and use version-matched docs (Context7 / `filamentphp.com/docs/<version>`) for every snippet; avoid third-party Filament plugins unless AGPL-compatible, maintained and SPA-tested.
- `visible()` / `hidden()` on actions and fields is UX only. Treat authorization as policies and verify by calling the Livewire action as the wrong role (see Pitfall 4).

**Warning signs:**
Timer ticks faster after navigation; duplicate event listeners in DevTools; kanban stops dragging after visiting another page; console errors after session timeout; actions triggered when merely hovering links.

**Phase to address:** Foundation (panel config + render-hook timer shell), then Kanban and Time tracking (JS-heavy features).

---

### Pitfall 3: Filament authorization is permissive by default (no policy = allowed; Pages and Widgets default to visible)

**What goes wrong:**
Filament delegates to Laravel Policies when one exists for the model; when none exists the action is allowed **[verify per Filament version]**. Custom Pages (`canAccess()`), Widgets (`canView()`), RelationManagers (`canViewForRecord()`) and navigation items default to visible. A Partner who is merely "not shown the menu entry" can still load `/admin/time-entries`, a dashboard widget with revenue totals, or a relation manager tab with rates, by URL or because the widget is auto-discovered.

**Why it happens:**
Developers build for the Admin first and add the Partner later by hiding things. Hiding is not authorizing.

**How to avoid:**
- Default-deny architecture: all Resources extend an `AdminResource` base whose `canViewAny()` returns `auth()->user()->isAdmin()`; Partner-facing screens are **separate classes** (`Partner\ProjectResource`, `Partner\TaskResource`) that expose only Partner-safe columns/fields from a narrowed query, instead of one shared Resource with `visible()` toggles. Same for Pages and Widgets (`AdminPage`, `AdminWidget` bases).
- A policy for every model, generated in the same commit as the model; a test fails if a registered Filament Resource/Page/Widget/RelationManager lacks an explicit access rule.
- Architecture test: every class in `app/Filament/**` extends one of the approved bases.
- `User::canAccessPanel()` checks `is_active` and role; deactivated users must lose access on the next request and have their sessions and tokens invalidated.
- Gate::before for Admin is fine; never add a `Gate::before` that returns `false`/`true` for unrelated abilities.

**Warning signs:**
A Partner test account can open a URL that is not in its menu; a new Resource appears in the Partner's nav because nobody set `canViewAny`; widgets rendering for both roles.

**Phase to address:** Foundation (bases + registry test). Partner audit finalizes.

---

### Pitfall 4: Partner data leaks through secondary Filament surfaces (search, selects, relation managers, exports, notifications, widgets, activity log, Livewire state)

**What goes wrong:**
Partner isolation is implemented on the main list/edit pages and forgotten on the many side channels. The leak list for this exact product:

| Surface | How it leaks | Prevention |
|---------|--------------|------------|
| **Global search** | Search results use the Resource's `getGlobalSearchEloquentQuery()` (defaults to the Resource query); result titles/details can include rate, amount, client name. A Resource without view/edit page is skipped; one with them is searchable by every role that passes `canViewAny` **[verify]** | Disable global search on all Admin resources for Partners; scope via model global scope so the query is safe regardless of path; never put money/rates in `getGlobalSearchResultDetails()`; test with canary strings |
| **Select / Radio / CheckboxList options** | `Select::relationship()`, `->options(Model::pluck(...))`, `->searchable()`, `->preload()` list unscoped rows: other clients, all users (emails of other Partners, admin account), all tags (Spatie tags are global, tag names reveal other clients). Posting a tampered id from another client (IDOR) succeeds when the option query was the only guard. `exists:`/`unique:` validation rules query the table directly and become **existence oracles** ("project key already taken") | Scope the options query (`modifyQueryUsing`) AND validate server-side with scoped `Rule::exists()->where(...)`; use scoped model queries; never expose users/tags lists to Partner; test tampered payload via Livewire `->set()` |
| **Relation managers** | The RM queries `$owner->relation()`, not the related Resource's `getEloquentQuery()`, so Resource-level scoping and `canViewAny` of the related Resource are bypassed; RMs default `canViewForRecord` to true; Attach/Associate actions list all records | RM `canViewForRecord` default-deny; apply global scope on the model, not only on the Resource; omit RMs from Partner-facing resources |
| **Exports (CSV/XLSX, Filament Export action, report exports)** | Export runs in a queue job with no authenticated user; any scope or policy that reads `auth()->user()` silently becomes "no filter" in the worker **[verify how Filament serializes the export query]**. Partner-created text lands in an Admin's CSV: **CSV/formula injection** (`=HYPERLINK(...)`, `@SUM(...)`) | Do not give Partners any export. Admin exports: pass explicit IDs/filters not ambient auth; prefix cells starting with `= + - @ \t \r` with `'`; test export as Partner is forbidden |
| **Notifications (DB, mail, Filament toasts)** | Recipient list computed as "everyone who can view the project" includes Partners; internal comments trigger Partner notifications; notification body interpolates rate/time/internal text; queued notifications re-render in a worker with no auth context | Compute recipients explicitly with an `isInternal` guard; separate templates for internal events; test that an internal comment creates zero Partner notifications |
| **Widgets / stats / charts** | Aggregates via `DB::table()` or raw SQL ignore Eloquent global scopes; `Cache::remember('dashboard.revenue', ...)` keyed without role/user serves Admin totals to Partner; widgets default to visible | `canView()` default-deny; no money widgets for Partner; cache keys include role + user + client id; no `DB::table` on client-owned tables outside a reviewed query layer |
| **Activity log** | `activity_log.properties` holds old/new attributes including rate, price, internal flag, notes; any "History" tab, timeline widget or audit page shows other users' diffs; `$hidden` does not stop logging; Spatie `LogOptions` logs everything unless `logOnly`/`logExcept` are set | Activity log is Admin-only; whitelist attributes with `logOnly([...])`; never log secrets (Stripe ids ok, keys/tokens/passwords never); make it append-only (Pitfall 14) |
| **Livewire state / snapshots** | Livewire serialises public component properties into the page's HTML snapshot and every update response. A form `fill($record->attributesToArray())` can put attributes of fields that are *hidden for this role* into `$data` **[verify per version]**; custom pages with public array/DTO properties containing full models leak the same way | Fill forms with a whitelisted array per role (`mutateFormDataBeforeFill`); keep sensitive data off public properties; run canary tests against **raw HTML and Livewire JSON**, not only rendered text |
| **Media / documents / PDFs** | `/documents/{media}` or a ZIP download built from client-supplied IDs; Media is not client-scoped; invoice PDF URLs by id | Authorize per id with a policy, always re-check on download; never trust ID lists |
| **Counts and badges** | Navigation badges, tab badges, "N results" counts from unscoped queries | Scope via model global scope; test counts with two clients |
| **Error and validation messages** | "Client X already has project key ABC" reveals data | Generic messages; scoped uniqueness queries |

**Why it happens:**
Each surface is a separate code path with its own query; scoping is applied to the one the developer looked at. UI hiding feels like security.

**How to avoid (the defence-in-depth stack, all three layers):**
1. Model-level global scope + denormalized `client_id` on every client-owned table (Pitfall 5).
2. Policy on every model, Partner-specific Resource classes (Pitfall 3).
3. **Canary test harness**, started in Foundation: seed two fictional clients; put unique canary strings (`CANARY_B_7f3a...`) in every field of client B (names, notes, comments, internal comments, rates, tags, document names, activity properties); as a Partner of client A, request every Filament route, every Livewire update (mount actions, search, select search, table search, export attempts), every API route, every download route, and assert the canary never appears in the **raw response body**, including Livewire snapshot JSON. Add a registry-walk test that enumerates Resources, Pages, Widgets, RelationManagers and actions so a newly added surface fails CI until it is classified.

**Warning signs:**
Any code path using `DB::table`, `Model::withoutGlobalScopes()`, raw SQL, `->options(Model::all())`, `Cache::remember` without role in the key, or a new Resource without a Partner decision.

**Phase to address:** Foundation (harness + bases), extended by every feature phase (each phase's "done" includes a canary test for its screens), finalized in Partner audit. Notifications and activity log: Foundation (policy decision), Projects/Tasks (comments with internal flag).

---

### Pitfall 5: Laravel global scope pitfalls for client isolation

**What goes wrong:**
- Scopes are **not applied** to `DB::table()`, raw SQL, `->join('clients', ...)` (the joined table's scope is not applied: reports "time per client" leak via joins), `insert/upsert`, validation rules (`exists`, `unique`), and `withoutGlobalScopes()`.
- Scope reads `auth()->user()`. In queue workers, scheduler, console, webhooks and tests there is no user. A scope that returns "unconstrained when no user" is fail-open for queued code dispatched on behalf of a Partner (the worker sees everything); one that returns "empty when no user" breaks Stripe webhooks, CNB jobs and invoice generation.
- `->when($user->client_id, fn ...)` style: a Partner with `client_id = NULL` (or no assigned clients) gets **no constraint** instead of no data. (`whereIn('client_id', [])` is safe in Laravel; `when(null, ...)` is not.)
- The scope on `User` (or anything auth resolves) triggers infinite recursion because `auth()->user()` loads the user through the same scope.
- Child tables (tasks, time entries, comments, documents) have no `client_id`; scoping via `whereHas('project')` is slow and easy to forget in one query.
- Authentication state leaks across tests/long-lived processes (`Auth::forgetUser()`, Octane/`queue:work` memory).
- `Model::query()->update()`/`delete()` apply scopes (good), but `updateOrCreate`, `increment` with joined queries or raw updates may not.

**Why it happens:**
"Global scope" feels like a global guarantee; it is only a convention for Eloquent builder queries on that model.

**How to avoid:**
- One trait `BelongsToClient` that registers `ClientScope`: constrains only when the current user is a Partner; Partner with no clients yields `whereRaw('1 = 0')` (fail closed); explicit and loud `Model::forAllClients()` named macro for internal code (replaces `withoutGlobalScopes()` in review).
- **Never rely on ambient auth inside jobs/notifications**: pass `client_id`/IDs explicitly and use the explicit macro. Add a CI grep/arch test: `withoutGlobalScopes` and `DB::table(` on client-owned tables only in whitelisted files.
- Do not apply the scope to `User`, `Role`, `Permission` (recursion); resolve Partner's client list from a cached `auth()->user()` property, not a query inside the scope.
- Denormalize `client_id` onto every client-owned table (tasks, subtasks, comments, time entries, documents, invoices) and enforce consistency with a composite FK, e.g. `projects UNIQUE (id, client_id)` and `tasks FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id)`. Indexed `client_id` also makes the scope cheap.
- Report queries live in a single reviewed query layer that applies the same constraint explicitly.
- Optional hardening for later (not v1): PostgreSQL row-level security with a `SET LOCAL app.client_id`; mind pooling semantics before adopting.

**Warning signs:**
`DB::table`/`join()` in report code; a Partner test passing only because the test user has a client set; jobs that read models without ids; scope code that calls `Auth::user()->clients()->get()` (recursion/N+1).

**Phase to address:** Foundation (trait + composite-FK pattern + arch test), reapplied in Clients/Projects/Tasks/Time/Invoicing, verified in Partner audit.

---

### Pitfall 6: Gap-free sequences (task keys `KEY-N`, invoice numbers) under concurrency

**What goes wrong:**
- `MAX(number)+1` or `count()+1` races: duplicate numbers or unique-constraint exceptions.
- PostgreSQL `SEQUENCE` / `AUTO_INCREMENT` is **not** gap-free: rolled-back transactions and crashes consume values.
- `lockForUpdate()` outside a transaction is a no-op (lock released immediately).
- Allocation inside a model event (`creating`) while the surrounding Filament create is not wrapped in the same transaction: a later failure rolls back the row but not necessarily the counter, or vice versa, leaving gaps or duplicates.
- Assigning the invoice number at **draft creation** instead of at issue time: deleted drafts create gaps.
- Retried job or double-clicked "Issue" allocates two numbers.
- Year rollover: the first invoice of January races to create the counter row; the year is derived from UTC (an invoice issued 00:30 on 1 Jan Prague time is still 31 Dec UTC and gets the wrong year in `{YYYY}{NNNN}`).
- Overflow of the 4-digit pad (invoice 10000) and key-format assumptions.
- The old tool's import sets counters wrong (`next_task_number` lower than max existing).

**Why it happens:**
Sequences are treated as a column default instead of a transactional resource; tests run in a wrapping transaction (`RefreshDatabase`), so concurrency is never exercised.

**How to avoid:**
- A single `SequenceAllocator` service. Counter rows (`projects.next_task_number`, `number_sequences(series, year, next_value)`) updated with `UPDATE ... SET next = next + 1 ... RETURNING` (or `SELECT ... FOR UPDATE`) **inside the same DB transaction that inserts the numbered row**. Allocation and insert live in one application action (`IssueInvoice`, `CreateTask`), not in model events or Filament hooks. Short transactions, set deadlock retry (`DB::transaction($cb, attempts: 3)`).
- Counter row for a new year via `INSERT ... ON CONFLICT DO NOTHING` followed by the locking update.
- Invoice number allocated at **issue** only (drafts have `NULL` number). Issued numbers are never reused; corrections via credit note (not delete/void-and-reuse).
- Year taken from the issue date in `Europe/Prague`.
- DB safety nets: `UNIQUE (series, number)`, `UNIQUE (project_id, number)`, `CHECK (next_task_number >= 1)`, `CHECK (status = 'draft' OR number IS NOT NULL)`.
- Idempotency for the issue action (status transition guarded by `WHERE status = 'draft'` and affected-row check).
- A **real concurrency test**: spawn N (e.g. 30-50) parallel PHP processes against a real database (no wrapping transaction; `DatabaseMigrations`/truncate) and assert numbers are exactly `1..N`. Plus a scheduled "sequence integrity" health command that flags gaps in issued numbers.
- Import tooling must set counters from `max(existing)+1` and have a CI-tested "importer sets counters" path (PROJECT.md already calls this out).

**Warning signs:**
`MAX(` in code; `lockForUpdate` without `DB::transaction`; numbers allocated in observers; tests that only pass with `RefreshDatabase`; duplicate-key errors under double-click.

**Phase to address:** Foundation (allocator + concurrency test harness, since both Projects/Tasks and Invoicing depend on it); Invoicing (issue-time allocation, year rollover); Projects/Tasks (task counter).

---

### Pitfall 7: Stripe webhook handling (signature, raw body, replay, idempotence, ordering, async payments, visibility)

**What goes wrong:**
- `spatie/laravel-webhook-client`'s `DefaultSignatureValidator` computes `hash_hmac('sha256', body, secret)` and compares to a header named `Signature`. Stripe sends `Stripe-Signature: t=<ts>,v1=<sig>[,v0=...]` over `"<ts>.<body>"`. The default validator rejects every real Stripe call; a developer "fixing" this by disabling validation lets anyone forge payments. Verified in the webhook-client docs; Stripe's requirements verified in Stripe docs.
- Body mutated before verification (re-encoding JSON, middleware that reads `$request->all()`): signature fails. Route inside the `web` group/CSRF/session, or inside the Filament panel prefix, or behind rate limiting that throttles Stripe bursts.
- Replay: valid payload re-sent later. Stripe libraries enforce a 5-minute timestamp tolerance by default; a tolerance of `0` disables the check; hand-rolled validators often skip it.
- Duplicates: Stripe may deliver the same event more than once (and sometimes two separate Event objects for the same underlying object). `webhook_calls` has no unique key on the Stripe event id by default, so a check-then-insert in application code races under two simultaneous deliveries.
- Event ordering is not guaranteed; `created` is not an ordering key.
- Treating `checkout.session.completed` as "paid": for delayed methods `payment_status` can be `unpaid`; the money arrives with `checkout.session.async_payment_succeeded` (or fails with `..._failed`).
- Test vs live mode secrets and `livemode` mismatches; `stripe listen` prints a different signing secret from the dashboard endpoint.
- Processing failures are invisible: `webhook_calls.exception` is populated but nobody looks, the invoice stays "unpaid", and the Core Value ("no unpaid invoice slipping through") is violated while the customer has paid.
- Webhook payloads contain customer personal data and persist forever.
- Subscribing the endpoint to all events: refunds/disputes (out of scope) arrive and are silently ignored while the invoice shows paid.

**How to avoid:**
- Custom `SignatureValidator` implementing Stripe's scheme (use `\Stripe\Webhook::constructEvent` with raw `$request->getContent()`, default tolerance, ignore non-`v1` schemes; support two active secrets for rotation), registered in `config/webhook-client.php`. Alternatively adopt `spatie/laravel-stripe-webhooks` (it wraps webhook-client and already validates Stripe signatures) **[verify AGPL-compat. and maintenance]**. Route is stateless: no session, no CSRF, own throttle that allows Stripe bursts, public URL fixed and unrelated to the panel.
- Store first, process later, return 200 fast: persist the `WebhookCall` row with a **unique index on Stripe `event.id`** (`INSERT ... ON CONFLICT DO NOTHING`; always return 2xx for duplicates) and dispatch an idempotent job. Layer 2 idempotency: `payments` has `UNIQUE (provider, provider_payment_intent_id)` (and/or checkout session id); processing is a no-op if the payment row exists. Use `data.object.id` + `type` as the secondary dedupe key.
- Handler handles events by fetching/validating current object state when order matters; only `payment_status = 'paid'` marks an invoice paid; handle async success/failure events.
- Persist `livemode`; reject events whose mode mismatches the environment.
- Subscribe only to required event types. For out-of-scope `charge.refunded`/`charge.dispute.created`, consider only *flagging* the payment in the overview ("handle in Stripe") rather than ignoring.
- Visibility: unprocessed/failed webhook calls older than N minutes appear on the dashboard and trigger an admin alert (Pitfall 18); a manual "reprocess" action; retention policy (`delete_after_days`) for payloads.
- Test with Stripe CLI fixtures: valid, bad signature, old timestamp, duplicate, out-of-order, async-paid, amount mismatch, unknown invoice, double payment.

**Warning signs:**
Signature validation toggled off "for local dev" in committed config; `webhook_calls` rows with non-null `exception`; payments overview empty while Stripe dashboard shows payments; two payments rows for one event id.

**Phase to address:** Stripe (all); Foundation (queue failure alerting, public-route/middleware conventions).

---

### Pitfall 8: Payment Links are reusable, and amount/currency/matching edge cases

**What goes wrong:**
A Payment Link is a public URL and, by default, can be paid many times by many people. "One link per invoice" quietly means "one link, paid twice" when the customer double-submits, forwards the URL, or two tabs are open. Stripe supports `restrictions.completed_sessions.limit` (verified in the Payment Link object docs: `restrictions.completed_sessions.limit` and `.count`), but a limit does not make the integration safe by itself. Related edge cases:
- Payment Link line items are fixed once created; changing an amount means a new link and deactivating the old one; links have no expiry of their own, so an unpaid-then-cancelled/credit-noted/manually-paid invoice keeps an active, payable URL (`active` flag exists for deactivation).
- Customer pays by other means (bank transfer recorded manually) after the link was sent: link still live.
- Matching a payment to an invoice by amount alone breaks with repeated amounts. Use the link id (`plink_...`, present on the Checkout Session as `payment_link`), `client_reference_id`, and/or metadata carrying the invoice UUID; verify amount and currency against the invoice's outstanding amount; handle over/under payment.
- Minor-unit exponent: Stripe amounts are minor units per currency (JPY 0 decimals, some currencies 3, a few quirky ones like ISK/HUF); never hardcode `* 100` (see Pitfall 10).
- Payment Links likely need a Product/Price per invoice (inline `product_data` may not be allowed for payment links) which clutters the Stripe account **[verify API shape]**.

**How to avoid:**
- Create the link with `restrictions.completed_sessions.limit = 1`, store `stripe_payment_link_id`, and deactivate (`active=false`) on: invoice paid (any method), cancelled, credit-noted, superseded.
- Still implement the planned duplicate/unmatched handling: second payment for a paid invoice becomes a `duplicate` payment row visible in the overview (manual refund in Stripe dashboard), unknown payment becomes `unmatched`. Never discard a webhook-confirmed payment.
- Match by link id / client_reference_id, then validate amount+currency; mismatch becomes `needs_review`, not auto-paid.
- Put per-environment Stripe keys only in env; test and live links never mixed.

**Warning signs:**
Two payment rows per invoice; links still active on paid invoices; matching logic using `amount_total` only.

**Phase to address:** Stripe. Research flag: confirm Payment Link creation API shape (Price/Product requirements, metadata propagation to Checkout Session / PaymentIntent) in a phase research spike.

---

### Pitfall 9: CNB exchange-rate edge cases (weekends, holidays, quantity per 100/1000 units, decimal comma, staleness)

**What goes wrong:**
- CNB publishes on working days around 14:30; a rate published on a day is valid for that day and following weekend/holidays (verified on cnb.cz). There is **no row for Saturday/Sunday/holidays**. Requesting `denni_kurz.txt?date=<weekend>` returns the previous working day's table (header carries the actual date), so keying by requested date creates duplicates or mislabeled rows.
- Many currencies are quoted per 100 units (JPY, HUF, KRW, PHP, THB, TRY, INR, ISK) and **IDR per 1000** (verified on cnb.cz). The file has an `množství` (amount) column; storing only `rate` and treating it as per-1-unit produces invoices that are off by 100x/1000x.
- Decimal comma (`23,456`) and `|` separators; first line is `DD.MM.YYYY #NNN`; parsing with `floatval` corrupts numbers.
- Scheduler runs before 14:30 Prague or in a DST-shifted hour and sees yesterday's data; the "idempotent" job stores nothing and a naive alert fires ("no rate for today") or, worse, nothing fires when the endpoint silently returns stale data.
- Lookup uses `rate_date = invoice_date` exactly: fails on weekends/holidays.
- Missing rate at issue time silently falls back to 1.0 or an old rate.
- Rates held as floats; converting using the *latest* rate at report time instead of the snapshot on the document.

**How to avoid:**
- Table `exchange_rates(currency, rate_date, units, rate NUMERIC(18,6) or string-decimal, source_sequence)` with `UNIQUE (currency, rate_date)`; `rate_date` is the date from the **file header**, not the requested date. Normalise to per-unit value only at use: `rate / units`, using decimal arithmetic (brick/money `BigDecimal` or bcmath), never float.
- Lookup: latest `rate_date <= :date`, error if older than a configured staleness (e.g. 7 days) rather than silently using it. Define in the requirements which date applies (issue date vs supply date); store `rate_date` and the rate itself as snapshot on the invoice.
- Scheduler: `->timezone('Europe/Prague')`, run after 14:30 with retries (e.g. 15:00, 16:00, next-morning catch-up); avoid 02:00-03:00 slots (DST). Failure alert plus a **staleness check** ("newest rate_date older than last working day") so a succeeding-but-empty job is also caught. CZK clients: no lookup needed (identity rate stored explicitly).
- Backfill command uses the yearly file endpoint with bounded request rate; idempotent upsert; tested with weekend/holiday fixtures and with JPY/HUF/IDR rows.
- Tests: 2027-03-28 style weekend request, JPY 100 units, IDR 1000 units, decimal comma, repeated import.

**Warning signs:**
Float/`double` columns for rates; no `units` column; rate lookup `where rate_date = ?`; invoices to JPY/HUF clients with CZK totals off by 100x.

**Phase to address:** CNB rates (and snapshot columns defined in Invoicing / Finance).

---

### Pitfall 10: Money representation and rounding (bigint minor units, per-currency exponents, hours x rate)

**What goes wrong:**
- `(int)(19.99 * 100)` is `1998`: float conversion at the form boundary. Czech locale input `12,5` parsed as `12`.
- Hardcoded two-decimal assumption; JPY has none and some currencies have three; Stripe has its own list of exponents.
- Time (seconds, never rounded) times an hourly rate yields fractional minor units: where and how rounding happens is not specified, so the invoice total, the sum of lines, the PDF and the Stripe link amount disagree by 1 haléř.
- Totals recomputed at PDF render time with different rounding than what was stored.
- Percentage discounts/fees rounded per line vs per total inconsistently; converted CZK amounts for finance overviews summed from rounded vs unrounded values.
- Money serialised to JSON as float; JS number precision; API mixing major and minor units.

**How to avoid:**
- One `Money` value object (use `brick/money` or `moneyphp/money`, both MIT) holding `int minorUnits + Currency`; parse form input from strings with a decimal parser; custom Eloquent cast; a Filament money input that only ever exchanges strings. Ban `float` for money via a static-analysis/arch rule.
- Define the rule once and document it: `amount_minor = round_half_up(seconds * hourly_rate_minor / 3600)` using integer arithmetic (`intdiv(2*n + d, 2*d)`), rounding per invoice line, totals = sum of stored line amounts. **Persist** line amounts and totals; PDF/email/Stripe read persisted values, never recompute. DB `CHECK (total_minor = ...)` can't span rows, so add a consistency test and an application-level assertion at issue time.
- Exponent from ISO 4217 data (via the money library), not literals. Stripe amounts converted through the same table.
- API returns `{ "amount": 123450, "currency": "CZK" }` as integers.
- Rate snapshot semantics for converted amounts (Pitfall 9); report conversions use per-transaction snapshots.

**Warning signs:**
`* 100`, `/ 100`, `round(`, `number_format(` in domain code; `float`/`decimal` money columns; PDF total differs from DB total in a test; Stripe link amount differs from invoice total.

**Phase to address:** Foundation (value object, casts, arch rule), Invoicing (rounding rule, snapshot), Finance (conversion), Stripe (amount parity test).

---

### Pitfall 11: Timezone and DST in time tracking (timestamptz, local dates, midnight and DST edges)

**What goes wrong:**
- Laravel's `$table->timestamp()` / `timestamps()` creates `timestamp(0) WITHOUT time zone` on PostgreSQL. The brief requires `timestamptz`; using Laravel defaults silently violates it, including in package tables (`activity_log.created_at`). Precision 0 also truncates fractions.
- "Which day does this entry belong to": `DATE(started_at)` uses the DB session timezone (UTC), so an entry at 00:30 Prague appears on the previous day in timesheets and reports. Grouping by month for invoicing has the same bug.
- Manual entry for a local time that does not exist (spring forward) or occurs twice (fall back). DST in Europe/Prague: **2026-10-25** (fall back; right after project start), **2027-03-28**, **2027-10-31**. Durations computed from local wall-clock values are off by one hour on those days; durations from UTC instants are right.
- Filament `DateTimePicker`/table columns default to the app timezone; if `app.timezone = UTC` the user sees UTC unless every column sets `->timezone()`.
- Timer elapsed computed in the browser from the browser clock (skewed/DST-changed device) instead of server time.
- Scheduled jobs at 02:30 skipped or run twice on DST nights.
- Entries spanning midnight; a timer left running for days.
- `Carbon::now()` mutable instances copied around; tests with real time.

**How to avoid:**
- Convention: `timestampTz(precision: 3 or 6)` / `timestampsTz()` everywhere incl. package migrations; arch test inspects the catalog for `timestamp without time zone`. `app.timezone = UTC`, set one display timezone (`FilamentTimezone::set('Europe/Prague')` plus per-component timezone) and one `Date::use(CarbonImmutable::class)`.
- Durations are `ended_at - started_at` in seconds computed from instants; stored `duration_seconds` integer (never rounded).
- Local-date logic only via `AT TIME ZONE 'Europe/Prague'` in SQL, but **filter** with raw range predicates on `started_at` (computed Prague day/month boundaries converted to UTC) so indexes remain usable; group with the expression. Document whether an entry spanning midnight is attributed to the start date.
- Manual entry validation: reject non-existent local times, resolve ambiguous times explicitly (pick first occurrence, show offset), cap maximum duration (e.g. warn above 24h), forbid future end.
- Server provides `now` with every timer response; client computes `serverNow - clientNow` offset; stop uses **server** time.
- Schedule with `->timezone('Europe/Prague')` and avoid 02:00-03:00 slots.
- Tests: fixtures around 2026-10-25, 2027-03-28 and 2027-10-31, entries at 23:30-00:30 Prague, entries across month boundary, `Carbon::setTestNow`.

**Warning signs:**
`DATE(started_at)`, `timestamp without time zone` in the catalog, browser-computed `Date.now() - startedAt`, per-column `->timezone()` missing, "off by one hour" bug reports in late March / late October.

**Phase to address:** Foundation (conventions + catalog test), Time tracking (rules and tests), Reports (grouping queries), CNB rates (scheduler time).

---

### Pitfall 12: Running-timer and billing races (one timer per user, double stop, billing while running)

**What goes wrong:**
- Check-then-insert "is a timer already running?" races: double click, two tabs, or UI + API (Sanctum) at once produce two running entries for one user.
- "Stop" executed twice (Livewire retry, API retry) overwrites `ended_at` with a later time; "start" switching timers leaves a gap or overlap between old end and new start.
- UI shows no timer while the API started one; the user starts another; confusion and lost time.
- Billing selects entries while a timer is stopping; entries added to two concurrent draft invoices; a running entry (no end) gets billed.
- Entry edited after billing through a path that skips the lock (bulk action, API, raw update).
- Task/project archived, moved, or client deactivated while the timer runs.
- Forgotten timer runs for days.

**How to avoid:**
- DB constraint is the authority: **partial unique index** `CREATE UNIQUE INDEX one_running_timer_per_user ON time_entries (user_id) WHERE ended_at IS NULL`. Catch `UniqueConstraintViolationException`; "start" is idempotent (returns the existing running timer) or returns 409 with the current timer.
- "Start" while another runs = one transaction: lock the user row (`FOR UPDATE`), stop the old entry at `now()`, start the new one at the same `now()`.
- "Stop" is conditional: `UPDATE ... SET ended_at = ? WHERE id = ? AND ended_at IS NULL`, check affected rows; second stop is a no-op returning the final state.
- Overlap rules (if required) via an exclusion constraint on `tstzrange(started_at, COALESCE(ended_at, 'infinity'))` with `btree_gist` **[verify extension is available on the Zerops PG service]**.
- Billing: `invoice_line_id` (or `invoice_id`) nullable FK on entries; link with `UPDATE ... WHERE invoice_line_id IS NULL AND ended_at IS NOT NULL RETURNING id` inside the issue/draft transaction and assert the affected set equals the selected set; `CHECK (invoice_line_id IS NULL OR ended_at IS NOT NULL)`.
- Billed locking enforced at DB level (Pitfall 13), not only in policies.
- UI refetches on `visibilitychange` and via a light poll so API-started timers show up; disable the button during the request but treat server state as truth.
- Scheduler job notifies about timers running longer than N hours; archive/move task blocks or auto-stops with a notification.
- Concurrency tests with parallel processes (as in Pitfall 6).

**Warning signs:**
`->first()` + `->create()` for timers; no partial unique index; billing code that loads entries into PHP and updates them by id later; support reports of "two timers".

**Phase to address:** Time tracking (core), REST API (idempotent start/stop endpoints), Invoicing (billing from entries).

---

### Pitfall 13: Immutability of issued invoices and billed time entries enforced only in application code

**What goes wrong:**
Policies/observers that block edits are bypassed by `Model::query()->update()`, `DB::table()`, `saveQuietly()`, `increment()`, mass actions, relationship `sync`, Filament inline edits, API endpoints added later, `touch()`, a data-fix tinker session, or cascading FKs (`ON DELETE CASCADE` from clients deletes issued invoices). Snapshots are taken, then the PDF is regenerated from *live* client/supplier/settings data and no longer matches what was sent. Marking an invoice "paid" is itself a change to an "immutable" row, so teams either loosen the rule everywhere or block legitimate transitions. Migrations/imports fight the protection. Activity log can be edited or deleted.

**How to avoid:**
- Define "immutable" precisely: *financial content* (number, issue date, supplier/customer snapshot, lines, amounts, currency, rate snapshot) is frozen; *state* columns (`status`, `paid_at`, `sent_at`, payment links) change only via explicit transitions.
- **PostgreSQL triggers** (`BEFORE UPDATE OR DELETE`) on `invoices`, `invoice_lines` and billed `time_entries` that compare protected columns with `IS DISTINCT FROM` when `OLD.status <> 'draft'` (or `OLD.invoice_line_id IS NOT NULL`) and raise an exception; `BEFORE DELETE` blocks deletion. FKs from clients/projects/tasks to billed records are `ON DELETE RESTRICT`. Soft-delete conflicts are decided up front (unique indexes with `deleted_at`).
- Migrations for triggers via raw SQL (`DB::unprepared`), tested with a test that attempts `DB::table('invoices')->update(...)` on an issued invoice and expects an exception.
- Snapshots: `supplier_snapshot` / `customer_snapshot` JSONB and line text at issue time; PDF rendered from snapshot only; store the generated PDF as the canonical media artifact with a sha256, regenerate only on explicit action.
- Corrections: credit note + new invoice only.
- Documented break-glass for imports/maintenance (`SET session_replication_role = replica` or a dedicated migration role), never in app runtime.
- Activity log append-only (trigger blocking UPDATE/DELETE).

**Warning signs:**
"Immutable" implemented as `abort_if` in an observer; PDF template reads `$invoice->client->address`; no raw-SQL test; `onDelete('cascade')` on invoice FKs.

**Phase to address:** Foundation (trigger pattern + test helper), Time tracking (billed locking), Invoicing (invoice snapshots/triggers).

---

### Pitfall 14: Secrets and real data leaking into a public repository from the first commit

**What goes wrong:**
- Anything committed is public forever (history, forks, caches); deleting the file later does not help; the only fix is rotation plus history rewrite.
- Pre-commit hooks live in `.git/hooks`, are not cloned, are skipped by `--no-verify`; CI is the only authoritative gate. `gitleaks/gitleaks-action` may require a license key for organization-owned repositories (check before depending on it); a scan of only the PR diff misses secrets already in history (`fetch-depth: 0`).
- Secret scanners find keys, not **instance-specific or real-person data**: real company IDs and names (ARES fixtures recorded from real lookups), emails, phone numbers, IPs and hostnames, Zerops project/service IDs, S3 bucket names and endpoints, Stripe `acct_`/`plink_`/`price_` ids, Sentry DSNs, personal absolute paths (`/Users/<name>/...`) in tooling files.
- Planning/tooling directories: `.claude/` (settings.local.json, absolute paths) and `.planning/` are currently untracked and must be either ignored or sanitized before the first commit that includes them; `.planning/codebase/` was already committed before step 0 and must be scanned by the new tooling, and if the repository is already public, treated as already published.
- Real ARES company IDs that pass the mod-11 checksum may belong to real companies; "fictional" checksum-valid IDs can collide with real ones.
- Seeders/fixtures derived from a production dump, screenshots in docs, test mail addresses on real domains.
- `.env.example` containing live-looking placeholders; docker/zerops config containing real ids; `composer.lock`/`package.json` referencing private registries.
- Stripe/Zerops/GitHub tokens echoed in CI logs.

**How to avoid:**
- Step 0 deliverables as already planned, plus: a **local denylist outside the repo** (real names, domains, IDs, paths) used by the pre-commit script and a CI-safe subset of generic patterns; custom gitleaks rules for `plink_`, `acct_`, `whsec_`, `sk_live_`, `rk_live_`, `pk_live_`, Zerops tokens, S3 endpoints, absolute home paths, Czech IČO-like 8-digit numbers outside an allowlist (`12345678`, `00000000`); allowlist file reviewed in PRs.
- CI: gitleaks (pinned binary or pinned action by SHA) on full history on every push/PR; enable GitHub secret scanning + push protection (free on public repos).
- Tests never call real ARES: `Http::fake()` with invented payloads; the ARES client validates the ID format (8 digits) before building the URL.
- `.gitignore` covers `.env*` (except `.example`), `.claude/`, `.idea/`, `storage` dumps, `*.sql`, coverage output; `CONTRIBUTING` and `CLAUDE.md` rule: fictional data only.
- If something leaks: rotate first, then rewrite history, then notify; document the runbook in SECURITY.md.

**Warning signs:**
Hook only; scan only on diff; fixtures with real-sounding Czech company names; planning files with local paths.

**Phase to address:** Repo hygiene (step 0), enforced continuously in CI; each fixture-adding phase (Clients/ARES, Stripe, Invoicing) re-checks.

---

### Pitfall 15: GitHub Actions and deploy security for a public repo

**What goes wrong:**
- `pull_request_target` (and `workflow_run`, `issue_comment`) run with base-repo secrets and a write token; checking out and executing the PR head there (`npm install`, `composer install` scripts, test run) hands secrets to any fork author.
- Script injection: `${{ github.event.pull_request.title }}`, `github.head_ref` (branch names), `github.event.inputs.*`, issue titles interpolated into `run:` blocks execute attacker-controlled shell.
- Third-party actions pinned to mutable tags (`@v4`): a compromised tag runs with your secrets. Missing top-level `permissions: contents: read`; `GITHUB_TOKEN` default write scope; `actions/checkout` persisting credentials.
- Deploy secrets (Zerops token) stored as repository secrets, reachable by any workflow, instead of **environment secrets** in the `production` environment.
- "Deploy only from a published release": the release tag can point to any commit (not necessarily on `main`); anyone with write access can publish a release; environment approvals set to "prevent self-review" lock out a solo maintainer, while leaving it off makes the approval ceremonial.
- `actions/cache` or artifacts shared from untrusted PR builds into the deploy build (cache poisoning); `workflow_dispatch` inputs unvalidated; self-hosted runners on a public repo.
- Dependabot / forks do not get secrets: CI that needs a secret to pass fails for contributors (tempting to "fix" with `pull_request_target`).

**How to avoid:**
- CI on `pull_request` (no secrets) only. Do not use `pull_request_target` at all; if ever needed, never check out or execute PR head code.
- Pass untrusted values via `env:` and reference `"$VAR"` in the script; never interpolate `${{ }}` of event data into `run:`.
- Pin every third-party action to a full 40-char commit SHA with a version comment; Dependabot `github-actions` ecosystem to update; CODEOWNERS on `.github/workflows/`; prefer first-party actions or inline scripts.
- Workflow defaults: `permissions: {}`/`contents: read`, grant per job; `persist-credentials: false`; `concurrency` group for deploys.
- Deploy workflow: `on: release: types: [published]` and `workflow_dispatch`; job `environment: production` (required reviewers); the Zerops token is an **environment secret**; first step verifies the release tag's commit is an ancestor of `main` (`git merge-base --is-ancestor`); restrict environment deployment branches/tags (`v*` protected tags); deploy from a clean build that does not reuse PR caches.
- Tool installs (zcli, gitleaks) pinned by version + checksum.
- Solo-maintainer note: required reviewer = the maintainer is still useful as a deliberate pause and audit trail; pair it with branch protection and 2FA.

**Warning signs:**
`pull_request_target` anywhere; `@v3`-style refs; `${{ github.event` inside `run:`; secrets at repo level; Zerops GitHub integration enabled alongside Actions deploy (Pitfall 16).

**Phase to address:** Repo hygiene (CI scan, workflow lint, pinning) and Foundation (deploy workflow). Add `actionlint`/`zizmor` to CI.

---

### Pitfall 16: Zerops deployment specifics (migrations, scheduler, worker, multi-container state, proxy, deploy gate)

**What goes wrong:**
- **Migrations**: running `php artisan migrate` in every container's `initCommands` races when more than one container starts; `--isolated` needs a shared cache store for its lock (a per-container file cache does not lock across containers). Verified: Zerops provides `zsc execOnce <key> -- <command>` which runs once per key across containers; keying with `${ZEROPS_appVersionId}` runs once per deployed version; if the command fails all containers report failure and the deploy fails (unless `--retryUntilSuccessful`).
- During a rolling deploy the **old code runs against the new schema**: renames/drops in the same release break running requests and queued jobs. Jobs serialized by new code can be consumed by the old worker.
- **Scheduler**: `schedule:run` in `run.crontab` with `allContainers: true` runs on every container (duplicate CNB/notification jobs); Zerops supports `allContainers: false` to run on one container (verified). Without it, `->onOneServer()` needs a shared cache lock.
- **Worker**: a long-running `queue:work` is not a web process; run it as a separate service built from the same repo (or via the supported process mechanism) **[verify current Zerops topology for queue workers]**; no one restarts it after deploy unless the container is replaced; `--max-time`/`--memory` set; job `timeout` must be lower than the connection `retry_after`, otherwise jobs run twice.
- **Ephemeral filesystem and per-container state**: file cache/sessions/rate-limit/Livewire temporary uploads on local disk break on 2+ containers (upload lands on container A, form submit on B); `Cache::lock`, `onOneServer`, `--isolated`, Spatie settings/permission caches all need a shared store.
- **Build vs runtime env**: `config:cache` run in the build step bakes build-time env, not runtime env; `APP_KEY` rotation breaks encrypted columns and sessions.
- **Trusted proxies**: TLS terminates at the platform load balancer; without `trustProxies` Laravel generates `http://` URLs, signed URLs fail validation, Filament assets/mixed content break, Stripe webhook URL and redirect loops appear.
- **Deploy gate bypass**: Zerops' native GitHub/GitLab integration can deploy on push/tag and ignores GitHub environment approvals.
- Roles/permissions seeded by a dev seeder never exist in production after deploy.
- Backups "handled by the database service" are assumed, never restore-tested.

**How to avoid:**
- `zerops.yml`: `initCommands: zsc execOnce ${ZEROPS_appVersionId} --retryUntilSuccessful -- php artisan migrate --force`; additive-only (expand/contract) migrations with a CI check for dangerous ops (drop/rename) and a documented two-release procedure.
- Scheduler: `crontab` entry with `allContainers: false` for `php artisan schedule:run` every minute; scheduler heartbeat visible in the admin UI (Pitfall 18). Jobs also `withoutOverlapping`.
- Worker as separate service/`setup`; `--max-time=3600 --tries=3 --backoff=...`; graceful SIGTERM; `retry_after` > longest job timeout.
- Shared store (Redis/Valkey or DB) for cache, sessions, locks; Livewire temp uploads to S3 (with CORS configured); logs to `stderr`; `filament:cache-components`, `config:cache`, `route:cache`, `view:cache`, `icons:cache` in `initCommands`/runtime phase, not build.
- `trustProxies(at: '*')` (platform LB only reaches the app); test signed URL and webhook URL in the deployed environment.
- Disable Zerops auto-deploy from Git; deploy only via the Actions workflow + `zcli push` with token in environment secrets.
- Idempotent `kokpit:install`/migration-based seeding for roles/settings; first-admin creation command, not a seeder with a default password.
- Quarterly restore drill documented in SECURITY/README.
- Queue driver decision (Phase 1): database driver on the same PostgreSQL gives atomic dispatch with the transaction and one fewer service; with Redis set `after_commit => true` (else jobs run before the row is visible and fail with `ModelNotFoundException`). Either way dispatch from inside a transaction must use `afterCommit()`.

**Warning signs:**
Duplicate scheduled notifications; "file not found" after upload with 2 containers; http links in emails; migration failing only in production; `config:cache` in `buildCommands`.

**Phase to address:** Foundation (deploy pipeline, queue, scheduler), then every migration-carrying phase (expand/contract discipline).

---

### Pitfall 17: S3 signed URLs, uploads and downloads (documents, invoice PDFs)

**What goes wrong:**
- Presigned URLs generated at **list render time** for every row and embedded in HTML/Livewire snapshots (leak + cost), or stored/cached beyond their expiry; long expiries ("7 days") become de facto public links.
- Presigning with the **internal** S3 endpoint: the signature covers the host, so a URL that works server-side is unreachable (or invalid when the host is rewritten) from the browser. Path-style vs virtual-host style (`AWS_USE_PATH_STYLE_ENDPOINT`) differs by provider.
- Bucket accidentally public or objects with public ACL; code calling `getUrl()` instead of `getTemporaryUrl()`; thumbnails/conversions on a public disk.
- Authorization done at link creation for a list, not at download: ZIP/bulk endpoints trust client-supplied IDs.
- ZIP download built in memory or in `/tmp` of an ephemeral container (large files exhaust memory/disk, timeouts); bulk delete removes DB rows but leaves orphaned objects (or vice versa).
- User-uploaded HTML/SVG served inline from the bucket origin (stored XSS); wrong content type; no size/MIME validation.
- Livewire temporary uploads on local disk with multiple containers (Pitfall 16).
- Sorting out CORS for direct browser uploads.

**How to avoid:**
- Documents are always downloaded through an app route (`/documents/{media}/download`) that runs the policy check, then 302s to a **short-lived (1-5 min)** presigned URL generated at click time, with `ResponseContentDisposition: attachment` and the original filename.
- Separate `endpoint` (internal) and public `url`/presign endpoint config; test presigned URL from outside the platform network in a deploy smoke test.
- Private bucket, block public access, no ACLs; custom `Media` URL generator that only supports temporary URLs; arch test forbids `getUrl()` on the media disk.
- ZIP: stream entries from S3 to the response (ZipStream), re-authorize **every** id server-side, cap count/size, perform in a queued job writing to S3 with a temporary link if large.
- Delete flow: soft-delete row, queued job removes objects, reconciliation command for orphans.
- Validate size, extension and sniffed MIME; deny inline rendering; random object keys.
- Invoice PDFs are immutable artifacts (Pitfall 13); consider bucket versioning.

**Warning signs:**
`Storage::url()`/`getUrl()` in code; presigned URLs visible in page source for all rows; works locally, 403/SignatureDoesNotMatch in production; memory spikes on ZIP.

**Phase to address:** Foundation (disk config, deploy smoke test), Documents (download route, ZIP, bulk delete), Invoicing (PDF storage).

---

### Pitfall 18: Queue and scheduler failures are invisible, so "nothing slips through unnoticed" is not true

**What goes wrong:**
- Failed jobs accumulate in `failed_jobs` silently; the scheduler or worker simply stops (container crash, cron misconfig) and nothing alerts.
- Alerting is itself a queued mail: when the queue is broken the alert is never sent.
- Jobs swallow exceptions (`try/catch` + log) and report success; `tries=1` with no `failed()` hook; `ShouldBeUnique`/`WithoutOverlapping` locks left behind after a crash block the next run.
- Specific to this product: CNB rate job succeeds but loads nothing (stale rates), Stripe webhook processing job fails (paid invoice shows unpaid), PDF/email job fails (invoice not delivered but marked "sent"), ARES/external timeouts.
- `queue:prune-failed` runs before anyone looked; webhook-client's `exception` column is never surfaced.

**How to avoid:**
- Foundation: `Queue::failing()` / `JobFailed` listener that records an admin-visible alert (DB notification + a synchronous mail or log channel, not queued); admin-only Filament page for failed jobs with retry/forget; dashboard "system health" widget: failed jobs count, oldest pending job age, **scheduler heartbeat** (last `schedule:run` tick stored by a scheduled task), **newest CNB rate date**, **unprocessed webhook calls older than N min**, **unsent invoice emails**.
- `queue:monitor` with thresholds scheduled; optional dead-man's-switch ping to an external monitor (URL in env, never committed) with `pingOnFailure`/`thenPing`.
- Domain-level states instead of fire-and-forget: `invoice.sent_at` set only after a successful send, `email_status = failed` visible with a retry action; webhook call status; PDF generation status.
- Jobs: explicit `$tries`, `$backoff`, `failed()` hook, no swallowed exceptions, unique-lock expiry (`uniqueFor`), `retry_after` > timeout.
- Prune failed jobs only after 30+ days.

**Warning signs:**
`failed_jobs` non-empty with nobody aware; `catch (Throwable) { Log::error(...); }` in jobs; mail alerts dispatched via the same queue.

**Phase to address:** Foundation (failure alerting + health widget skeleton); extended in CNB rates, Stripe, Invoicing (each contributes its health signal).

---

### Pitfall 19: Sanctum API (`/api/v1`) mixing session and token auth, Partner access and token hygiene

**What goes wrong:**
- Enabling `statefulApi()` lets the Filament session cookie authenticate API calls (CSRF surface; Partner session can reach API routes). Sanctum's `tokenCan()` **always returns true for first-party SPA (session) requests** (verified in Sanctum docs), so ability checks give no protection in that mode.
- Partner users can mint tokens or reach endpoints that return time/rates.
- Tokens never expire by default (verified); `last_used_at` gives no revocation; no expiry + public repo docs encourages leaving tokens around; tokens of deactivated users still work.
- Abilities declared but not enforced per route/policy; the time-entry endpoint skips the global scope/policy because it only checks "authenticated".
- Rate limiting by IP only (behind a proxy everyone shares one IP) or not at all on timer endpoints; OpenAPI docs page (e.g. Scramble) exposed publicly in production.
- Uuid `tokenable` morph left bigint (Pitfall 1).

**How to avoid:**
- API is token-only: do not call `statefulApi()`; API guard separate from the Filament web guard. Token creation UI and API access limited to Admin by policy; Partner has zero API access in v1.
- Abilities per endpoint group (`time:read`, `time:write`, `timer:control`) enforced with `ability`/`abilities` middleware **and** policies; `expiration` configured (e.g. 365 days) plus per-token expiry and `sanctum:prune-expired` scheduled; deactivation revokes tokens.
- Rate limiter keyed by token/user id; idempotent start/stop (Pitfall 12); strict JSON validation via Spatie data/form requests; consistent error format.
- Gate API docs behind admin auth or disable in production; keep the OpenAPI spec generated in CI and checked in only if free of instance data.
- Tests: token without ability gets 403; Partner token impossible; deactivated user token rejected; canary leak test on API.

**Warning signs:**
`statefulApi()` present; `auth:sanctum` as the only middleware; no `expiration` in config.

**Phase to address:** REST API (with Foundation auth decisions and Partner audit coverage).

---

## Moderate Pitfalls

### Pitfall 20: Synchronous ARES lookup blocks and breaks forms
**What goes wrong:** ARES is slow or down; a Livewire action waits on it; timeouts surface as 500s; unvalidated input forms URL.
**Prevention:** 3-5 s timeout, validate 8-digit ID (and checksum) before request, catch and degrade to manual entry with a clear Czech message, short cache, rate limit, admin-only action, `Http::fake()` in all tests.
**Phase:** Clients.

### Pitfall 21: Invoice language and PDF rendering in the wrong locale or fonts
**What goes wrong:** The panel locale is `cs`; a queued job rendering an English invoice or email runs under the default locale (mixed languages); number/date formatting differs (`1 234,56 Kc` vs `CZK 1,234.56`); PDF fonts lack Czech glyphs (r with caron, u with ring, e with caron); the chosen PDF driver (Chromium-based) is heavy or absent on the Zerops runtime.
**Prevention:** Render with `App::setLocale($client->invoice_language)` / `withLocale` inside the job; mailables `->locale()`; locale-aware formatters; embed a font with full Latin Extended-A; decide PDF driver and verify it in the deployed runtime in Foundation/Invoicing spike; snapshot test both languages; PDFs stored once.
**Phase:** Invoicing (spike in Foundation if Chromium is required).

### Pitfall 22: Kanban ordering with `eloquent-sortable` under concurrency
**What goes wrong:** `setNewOrder` rewrites many rows, two users/drag events interleave, positions duplicate; status change and position update are separate writes; unbounded columns; Partner reorder endpoint reachable via Livewire.
**Prevention:** One transaction per move updating status + position; fractional/gap positions (or per-column reindex under lock); partial column loading; authorize move per role (Partner cannot change status); server returns authoritative order.
**Phase:** Kanban.

### Pitfall 23: "Unbilled time / unpaid invoice" dashboards drift from the ledger
**What goes wrong:** The Core Value depends on these numbers, but dashboard queries re-implement rules (billable? fixed-price project? archived project? running timer? proforma counted as receivable? credit note offset? partial payment? due date in Prague time) differently from billing code.
**Prevention:** One query/definition object (or DB view) used by billing, reports and the dashboard; reconciliation test: sum of unbilled seconds on the dashboard equals what the "bill all" action would select; scheduled "unbilled older than N days" and "overdue invoices" notifications; overdue computed on `due_date` in Europe/Prague.
**Phase:** Reports, Invoicing.

### Pitfall 24: Spatie settings, permission and cache state across containers
**What goes wrong:** Settings cache or permission cache on file store gives stale supplier/rates data across containers; role cache not reset after seeding in migration; secrets stored in settings table unencrypted.
**Prevention:** shared cache store; call `permission:cache-reset` in deploy; keep Stripe/S3/SMTP secrets in env only; settings only for non-secret business data; snapshot values onto documents (Pitfall 13).
**Phase:** Foundation.

### Pitfall 25: Activity log volume and content
**What goes wrong:** Every timer tick or model touch logged; rows with large property blobs; PII and rates in diffs; log rows referencing deleted subjects; no retention plan.
**Prevention:** `logOnly`, `dontSubmitEmptyLogs`, `logOnlyDirty`; log domain events (timer started/stopped, invoice issued/paid) rather than attribute spam; prune policy; Admin-only (Pitfall 4).
**Phase:** Foundation.

### Pitfall 26: Czech collation and search
**What goes wrong:** Database collation (set at creation, not changeable cheaply) sorts Czech strings wrongly (diacritics, "ch" digraph) and `ILIKE` ignores accents poorly, so "Novak" does not find "Novak with accents".
**Prevention:** Check Zerops PG collation early; use `unaccent` extension (if allowed) or an ICU collation (`cs-CZ-x-icu`) for sorted columns; store a normalised search column when needed.
**Phase:** Foundation (decision), Clients/Projects (search).

### Pitfall 27: Webhook and external payload retention (privacy)
**What goes wrong:** `webhook_calls` and activity log keep customer personal data indefinitely.
**Prevention:** retention settings, minimal stored payload, document in SECURITY/README.
**Phase:** Stripe.

### Pitfall 28: Dependency and licence drift (AGPL compatibility)
**What goes wrong:** A paid/closed Filament plugin or PDF engine slips in; transitive licence incompatible with AGPL-3.0; AGPL network clause requires offering source to users (a "Source" link in the UI is good practice).
**Prevention:** CI licence check (`composer licenses`/allowlist), review each new package, footer link to the repository, LICENSE/NOTICE files.
**Phase:** Repo hygiene / Foundation, ongoing.

### Pitfall 29: Soft deletes and uniqueness
**What goes wrong:** Unique indexes ignore `deleted_at` (cannot recreate a deleted project key) or include it (key reused, breaking frozen `KEY-N` links).
**Prevention:** decide: project keys are never reused (plain unique, deleted projects keep their key); partial unique indexes only where reuse is intended; billed/issued data is never deleted.
**Phase:** Projects/Tasks.

---

## Minor Pitfalls

### Pitfall 30: UUID v7 details
**What goes wrong:** v7 ids encode creation time (visible in URLs/API); ordering by `id` is only approximately chronological within the same millisecond/process; developers use `ORDER BY id` as a strict tiebreaker or business sequence.
**Prevention:** order by explicit `created_at, id`; never use uuid order as a business sequence (use Pitfall 6); accept timestamp disclosure and note it in docs.

### Pitfall 31: Route binding and ids in logs
**What goes wrong:** malformed ids returning 500; ids in analytics.
**Prevention:** route `whereUuid`, test 404s.

### Pitfall 32: Filament bulk actions and "select all across pages"
**What goes wrong:** bulk delete/export over a filtered set with hidden records.
**Prevention:** scope via model global scope; policy per record in bulk; confirmation text.

### Pitfall 33: Stale docs and AI-generated code for older Filament/Laravel majors
**What goes wrong:** snippets compile on v3 but not on the installed major.
**Prevention:** version-matched docs lookup, pin versions, CI `composer outdated --direct` weekly.

### Pitfall 34: `.env.example` and README drift
**What goes wrong:** new env vars missing from `.env.example`; self-hosters cannot deploy.
**Prevention:** CI check that every `env('X')`/`config` env key appears in `.env.example`.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Keep package migrations as published (bigint morphs) | Faster scaffolding | Runtime insert failures, rewrite of every package table | Never (decide in Foundation) |
| Shared Admin Resource with `visible()` toggles for Partner | Less code | Permanent leak risk; every new field needs two decisions | Never for money/rates/finance; acceptable only for trivial presentation differences |
| Immutability via observers/policies only | Quick | Bypass by any raw query; audit integrity lost | Never |
| `MAX(n)+1` for numbers | Trivial | Duplicates under concurrency | Never |
| Float or `decimal` money columns | Familiar | Rounding drift between PDF/DB/Stripe | Never |
| `timestamp()` (without tz) | Laravel default | DST/day-boundary bugs; contradicts brief | Never |
| Skipping CI test with real parallel processes | Speed | Races reach production | Only for non-sequence, non-timer code |
| Queue driver `sync` in production | No worker to run | Slow requests, lost isolation of failures | Never |
| Alerting via the same queue | Simple | Silent total failure | Never |
| Manual-only restore testing | Less work | Unknown recoverability | Acceptable in MVP if documented and scheduled before first real invoice |
| Hardcoded Czech strings | Fast | i18n rework | Never (use `lang/cs` from first screen) |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| Stripe webhooks | Default webhook-client validator, body re-encoded, no event-id uniqueness | Custom Stripe validator on raw body, unique event id, 2xx for duplicates, queue processing |
| Stripe Payment Links | One reusable link per invoice, match by amount | `completed_sessions.limit = 1`, deactivate on settle, match by link id/reference, validate amount+currency |
| CNB | Key rates by requested date, ignore `množství`, float parse | Key by header date, store units, decimal parse, latest-on-or-before lookup with staleness cap |
| ARES | Real calls in tests, unvalidated id in URL, no timeout | Fake HTTP, 8-digit validation, short timeout, graceful fallback |
| S3-compatible storage | Internal endpoint presigned, public ACL, `getUrl()` | Public presign endpoint, private bucket, app route + short-lived temporary URL |
| Zerops | `migrate` in every container, scheduler on all containers, Git auto-deploy | `zsc execOnce`, `allContainers: false`, Actions-only deploy |
| GitHub Actions | Tag-pinned third-party actions, `pull_request_target` | SHA pinning, `pull_request`, environment secrets and approval |
| Sanctum | `statefulApi()` plus tokens, no expiry | Token-only API, abilities + policies, expiry + prune |
| Spatie activitylog/permission/medialibrary/tags | Published bigint morph migrations | uuid morph edits + subclass models in config + arch test |
| Filament Export / notifications | Rely on ambient `auth()` in queued jobs | Pass explicit ids/filters; compute recipients explicitly |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| `AT TIME ZONE` on the filtered column | Seq scans on time entries/reports | Filter with UTC range predicates, group by expression; index `(client_id, started_at)`, `(user_id, started_at)` | Tens of thousands of entries (a few years of one freelancer) |
| Scope via `whereHas` across tables | Slow lists, N+1 in Partner screens | Denormalized indexed `client_id` | Thousands of tasks/time entries |
| Presigning every row on list render | Slow tables, URL leakage | Presign at click | Lists above ~50 rows |
| Filament `preload()` on large selects | Slow forms, large HTML | Searchable async selects with scoped queries | Thousands of tasks/clients |
| Activity log on every update | Table growth, slow writes | Log domain events, retention | Months of timer edits |
| Unbounded kanban columns | Slow board, heavy Livewire payload | Limit/paginate per column | Hundreds of tasks per column |
| Cache-miss thundering in dashboard | Slow home page | Cache with role/user key, short TTL | Several widgets with aggregate queries |
| ZIP built in memory | OOM on ephemeral container | Streaming | Tens of MB |

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| UI hiding instead of authorization (HIGH) | Partner reaches admin data via URL/Livewire call | Default-deny bases, policies, canary tests |
| Fail-open global scope in queued code (HIGH) | Worker exposes cross-client data | Explicit ids; fail-closed scope for Partner; arch grep |
| Webhook signature disabled or default validator (HIGH) | Forged payments | Stripe-specific validator, tolerance, raw body |
| Secrets/instance data in public repo (HIGH) | Credential theft, privacy breach | Hook + CI gitleaks + denylist + rotation runbook |
| `pull_request_target` / `${{ }}` injection (HIGH) | Secret exfiltration, supply-chain compromise | Rules in Pitfall 15 |
| Long-lived presigned URLs (MEDIUM) | De facto public documents | Minutes-level expiry, click-time presign |
| CSV/formula injection in exports (MEDIUM) | Code execution in spreadsheets of admin | Prefix dangerous cells |
| Tokens without expiry, Partner tokens (MEDIUM) | Persistent access | Expiry, admin-only, revocation on deactivate |
| Existence oracles via `unique`/`exists` errors (LOW-MEDIUM) | Information disclosure | Scoped rules, generic messages |
| Livewire public properties holding sensitive data (MEDIUM) **[verify]** | Data in snapshot JSON | Whitelist fill, canary on raw payloads |
| Default admin credentials / seeded password (HIGH) | Takeover of self-hosted instances | Install command prompting for admin, no default |
| Missing security headers/CSP, no 2FA for admin (MEDIUM) | XSS/credential theft impact | Add headers middleware; consider Filament MFA for Admin |
| Unrestricted file upload types (MEDIUM) | Stored XSS, malware hosting | Allowlist, attachment disposition |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Timer state differs between UI and API | Lost or double-counted time | Server-truth, refetch on focus |
| Rounding hidden from the user | "Why is this 1 haléř off?" | One documented rule, stored values shown everywhere |
| Gap in invoice numbers after cancelled draft | Accounting confusion | Number only at issue; credit notes for corrections |
| Partner sees disabled/empty screens instead of tailored ones | Confusion, support load | Partner-specific resources and Czech copy |
| Silent failure of email/Stripe processing | Invoice looks sent/unpaid wrongly | Explicit statuses with retry actions |
| Czech decimal comma and date input errors | Wrong amounts | Locale-aware inputs, strict parsing, confirmation of totals |
| Weekend/holiday rate surprises | "Why is yesterday's rate used?" | Show rate date on the invoice and in the form |
| DST confusion (non-existent local time) | Rejected manual entries | Clear error with suggested alternative |

## "Looks Done But Isn't" Checklist

- [ ] **UUID everywhere:** Often missing package/framework tables (sessions, notifications, exports, tags, tokens) — verify catalog test passes and an insert into each table works.
- [ ] **Timestamps:** Often still `timestamp without time zone` in package tables — verify catalog test.
- [ ] **Partner isolation:** Often only list pages scoped — verify canary test covers global search, selects, relation managers, widgets, notifications, exports, downloads, API, Livewire JSON.
- [ ] **Gap-free numbers:** Often only tested sequentially — verify parallel-process test yields exactly 1..N, and year-rollover/Prague-timezone test.
- [ ] **Stripe webhook:** Often missing tolerance, event-id uniqueness, async-paid handling — verify fixtures: bad signature, old timestamp, duplicate, out-of-order, async paid, mismatch amount.
- [ ] **One-time payment link:** Often missing deactivation on manual payment/credit note — verify link `active=false` after each settlement path.
- [ ] **CNB job:** Often works on weekdays only — verify weekend/holiday, JPY/HUF/IDR, stale-rate alert.
- [ ] **Money:** Often float at the form boundary — verify `19.99`, `12,5` Czech input, JPY, rounding table test, PDF = DB = Stripe total.
- [ ] **Timer:** Often missing DB constraint — verify two parallel starts leave exactly one running entry; double stop is a no-op.
- [ ] **Immutability:** Often only observers — verify raw `DB::table()->update()` on an issued invoice and a billed entry fails.
- [ ] **Invoice PDF:** Often reads live client data — verify changing the client address after issue does not change the PDF; both languages; Czech glyphs.
- [ ] **Deploy:** Often passes once on one container — verify 2 containers: one migration run, one scheduler run, uploads work, signed URLs valid, https URLs.
- [ ] **Failure visibility:** Often alerts only in logs — verify an intentionally failing job, a stale CNB table and a stuck webhook all show in the health widget and notify the admin.
- [ ] **Repo hygiene:** Often hook only — verify CI fails on a planted fake key and on a denylisted string, scanning full history.
- [ ] **Actions:** Often tag-pinned — verify all `uses:` are SHA pinned and no `pull_request_target`/`${{ github.event` in `run:` (actionlint/zizmor in CI).
- [ ] **API:** Often session-authenticated by accident — verify cookie auth returns 401 on `/api/v1`, token without ability gets 403, expired token fails.

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Bigint morph columns discovered late | HIGH | New migration converting columns with data mapping (ids are not convertible: need mapping tables), re-register models; best avoided |
| Partner leak found in production | HIGH | Revoke sessions/tokens, hotfix scope/policy, audit logs for exposure window, notify affected client, add canary for the vector |
| Duplicate or gapped invoice numbers | HIGH | Legal/accounting review; credit notes; fix allocator; integrity command; never renumber issued invoices silently |
| Duplicate Stripe payment | MEDIUM | Refund in Stripe dashboard, mark payment `duplicate`, ensure link deactivated |
| Wrong CNB rate/units on issued invoice | HIGH | Credit note + reissue (immutable); fix parser; backfill; audit other invoices by currency |
| Money rounding drift | MEDIUM | Reconcile persisted vs recomputed totals; corrective credit notes where issued |
| Secret leaked in public repo | HIGH | Rotate secret immediately, rewrite history (`git filter-repo`), force-push, contact GitHub to purge caches if needed, post-mortem |
| Compromised workflow/action | HIGH | Rotate all environment secrets, review workflow runs, pin and re-audit |
| Migration broke production during deploy | MEDIUM | Roll back release via Zerops version, ensure expand/contract; restore from DB backup if destructive |
| Timers duplicated | LOW | Stop extras by script, add partial unique index (needs clean data first) |
| Silent job failures discovered late | MEDIUM | Replay failed jobs/webhook calls, reconcile payments with Stripe dashboard, add health signals |

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| 1 UUID/morph mismatch | Foundation | Catalog architecture test + insert smoke test per package table |
| 2 SPA-mode quirks | Foundation, Kanban, Time tracking | Browser test navigating 10 pages; single timer interval |
| 3 Permissive Filament authz | Foundation | Registry test: every Resource/Page/Widget/RM has explicit rule and approved base |
| 4 Secondary leak surfaces | Foundation (harness), every feature phase, Partner audit | Canary test over HTML + Livewire JSON + API |
| 5 Global scope pitfalls | Foundation | Trait tests incl. no-client Partner, queue context, grep for `withoutGlobalScopes`/`DB::table` |
| 6 Gap-free sequences | Foundation (allocator), Projects/Tasks, Invoicing | Parallel-process test; year rollover in Prague time |
| 7 Stripe webhooks | Stripe | Fixture suite incl. signature, tolerance, duplicates, async |
| 8 Payment Links | Stripe | Link limit=1, deactivation on all settle paths, duplicate payment test |
| 9 CNB | CNB rates | Weekend/holiday/units/decimal fixtures; stale alert test |
| 10 Money | Foundation, Invoicing, Stripe | Rounding table test; PDF=DB=Stripe parity; float ban rule |
| 11 Timezone/DST | Foundation, Time tracking, Reports | DST fixtures 2026-10-25, 2027-03-28, 2027-10-31; catalog test timestamptz |
| 12 Timer and billing races | Time tracking, REST API, Invoicing | Parallel start/stop test; billing double-select test |
| 13 Immutability | Foundation (pattern), Time tracking, Invoicing | Raw SQL update/delete blocked; PDF from snapshot |
| 14 Repo secrets/real data | Repo hygiene | CI fails on planted secret/denylisted string; full-history scan |
| 15 GitHub Actions security | Repo hygiene, Foundation | actionlint/zizmor in CI; SHA pins; environment approval exercised |
| 16 Zerops specifics | Foundation | 2-container deploy rehearsal: one migrate, one scheduler, uploads, https |
| 17 S3 signed URLs | Foundation, Documents | Smoke test presigned URL externally; no `getUrl()`; ZIP streaming test |
| 18 Failure visibility | Foundation, CNB, Stripe, Invoicing | Planted failures appear in health widget and alerts |
| 19 Sanctum API | REST API | Cookie auth rejected; abilities/expiry tests; Partner denied |
| 20 ARES | Clients | Timeout/failure fallback test |
| 21 Invoice locale/PDF | Invoicing | Snapshot tests cs/en; Czech glyph rendering in deployed runtime |
| 22 Kanban ordering | Kanban | Concurrent move test |
| 23 Dashboard drift | Reports, Invoicing | Reconciliation test dashboard vs billing selection |
| 24 Settings/permission cache | Foundation | 2-container stale-cache test |
| 25 Activity log volume | Foundation | Log only whitelisted events; prune command |
| 26 Czech collation | Foundation | Sort/search test with diacritics |
| 27 Payload retention | Stripe | Prune job test |
| 28 Licence drift | Repo hygiene | CI licence allowlist |
| 29 Soft delete uniqueness | Projects/Tasks | Key reuse test |

## Sources

- Laravel 12 upgrade guide, "Models and UUIDv7" (HasUuids returns UUIDv7; HasVersion4Uuids opt-out) — https://laravel.com/docs/12.x/upgrade — HIGH (official docs, fetched)
- Laravel Sanctum docs (tokens never expire by default; `tokenCan` true for first-party SPA; `sanctum:prune-expired`; model override) — https://laravel.com/docs/12.x/sanctum — HIGH (official docs, fetched)
- Spatie laravel-permission UUID guide and prerequisites (edit migration in two places; extend models with HasUuids; "not a full lesson") — https://spatie.be/docs/laravel-permission/v6/advanced-usage/uuid and /prerequisites — HIGH (official docs, fetched)
- Spatie laravel-webhook-client README (default `hash_hmac('sha256', body, secret)` validator, `Signature` header, stored `webhook_calls`, queued processing, `WebhookProfile`) — https://github.com/spatie/laravel-webhook-client — HIGH (official docs, fetched)
- Spatie laravel-activitylog upgrade notes (no UUID morph guidance; verify `nullableUuidMorphs` manually) — https://github.com/spatie/laravel-activitylog/blob/main/UPGRADING.md — MEDIUM
- Stripe webhook docs (raw body, `Stripe-Signature`, 5-minute tolerance, tolerance 0 disables, duplicates, no ordering guarantee, async handling, retries up to 3 days live, resend limits, roll secrets) — https://docs.stripe.com/webhooks — HIGH (official docs, fetched)
- Stripe Payment Link object (`restrictions.completed_sessions.limit/count`, `active`, `inactive_message`) — https://docs.stripe.com/api/payment-link/object — HIGH (official docs, fetched)
- CNB exchange-rate page (publication ~14:30 on working days, validity over weekends/holidays, `denni_kurz.txt`, per-100 and IDR per-1000 quotation) — https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/ — HIGH (official source, fetched)
- Zerops docs: `zsc execOnce` semantics (key, `${ZEROPS_appVersionId}`, failure behaviour, `--retryUntilSuccessful`), Laravel `initCommands` migrations with `--isolated`, `crontab` with `allContainers` — https://docs.zerops.io/references/zsc, https://docs.zerops.io/frameworks/laravel/migrations, https://docs.zerops.io/frameworks/laravel/cron (via search summary) — MEDIUM-HIGH. Worker topology and object-storage presign endpoints NOT verified.
- Filament global search docs (needs Edit/View page to link; override `getGlobalSearchEloquentQuery()`; eager loading) — https://filamentphp.com/docs/4.x/resources/global-search — MEDIUM (authorization specifics not documented in fetched excerpt)
- GitHub Actions hardening guidance (no `pull_request_target` + PR-head checkout, env indirection for event data, SHA pinning, environment protection, least-privilege `GITHUB_TOKEN`, CODEOWNERS on workflows) — GitHub docs "Security hardening for GitHub Actions" and community summaries from web search — MEDIUM (secondary summaries; matches well-known official guidance)
- Training knowledge, tagged **[verify]** in text: Filament permissive-by-default authorization, Livewire form state contents, export job auth context, Zerops worker topology, Payment Link `price_data` requirements, `btree_gist` availability, PostgreSQL 18 `uuidv7()`.
- Note on method: findings were gathered with direct WebFetch/WebSearch calls; the research-plan cache seam was not used for this pass, so no digests were written to the research store.

---
*Pitfalls research for: single-tenant CRM/ERP with time tracking, invoicing and Stripe on Laravel + Filament SPA + PostgreSQL + Zerops (public AGPL repo)*
*Researched: 2026-10-06*
