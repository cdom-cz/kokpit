# Architecture Research

**Domain:** Single-tenant CRM/ERP (client -> project -> task -> time -> invoice -> payment), Laravel + Filament SPA panel, roles Admin/Partner, PostgreSQL, UUID v7 keys
**Researched:** 2026-10-06
**Confidence:** MEDIUM overall. Patterns (isolation layers, locking, state machine, idempotent pipeline) are HIGH. Package-specific details are tagged inline: HIGH = verified against current docs, MEDIUM = documented but version details unverified, LOW = judgement call to re-check in the phase.

All names, companies, IDs and e-mail addresses in this document are fictional (`Example Ltd`, `example.com`).

## Version Baseline (verified 2026-10)

| Item | Finding | Confidence |
|------|---------|------------|
| Laravel | 13.x is current (docs list 12.x as "old version"). `HasUuids` generates **UUIDv7 by default** (Laravel 12 and 13 docs). Use `Str::uuid7()` for raw inserts. | HIGH |
| Filament | v5 exists; it is v4 plus Livewire v4 support, "no functional changes" otherwise; works with Laravel 12 and 13. The 4.x docs remain valid for v5 behaviour. | HIGH |
| spatie/laravel-permission | v7 for Laravel 12-13 (PHP 8.3+). UUID needs edited migration + custom Role/Permission models with `HasUuids`. | HIGH |
| spatie/laravel-activitylog | v5: PHP 8.4+, Laravel 12+. `activity_log` now has `attribute_changes` JSON column, **batch support removed**, `properties` only holds custom data. Default migration uses `nullableMorphs` (bigint) -> must be edited to `nullableUuidMorphs`; `id` too. | HIGH |
| spatie/laravel-webhook-client | `webhook_calls` default migration uses `bigIncrements('id')`, no external-id column, no uniqueness. Extension points: `WebhookProfile`, `SignatureValidator`, `RespondsToWebhook`, `ProcessWebhookJob`, custom `WebhookCall` model. Invalid signature = event + no row stored. Model is `MassPrunable` (`delete_after_days`). | HIGH |
| spatie/laravel-pdf | v2; drivers: Browsershot (default, needs Node + Chromium), Gotenberg, Cloudflare, WeasyPrint, DOMPDF (pure PHP), Chrome. Driver choice is a deployment decision on Zerops. | HIGH |
| spatie/laravel-medialibrary | v11 doc line seen; `media` and `model_id` morph need UUID edit and custom `Media` model (config key `media_model`). Re-check for a v12 before phase 10. | MEDIUM |
| Filament kanban plugins | `mokhosh/filament-kanban` upstream supports Filament 3 / Laravel 10-12; v4/v5 support exists only via third-party forks. | MEDIUM |
| PostgreSQL | Needs: partial unique indexes, CHECK, composite FK, generated columns, `FOR UPDATE`, `timestamptz`. PG 18 adds native `uuidv7()` (usable as column DEFAULT for raw inserts); depends on Zerops offering PG 18. | MEDIUM |
| Stripe | Payment Link `metadata` is copied to Checkout Sessions it creates. Events can be duplicated, arrive out of order, retried up to 3 days (live). Verify with raw body + `Stripe-Signature`, 5 min default tolerance. | HIGH |
| CNB | Rates published every working day around 14:30 Prague time, valid for following weekend/holidays; `daily.txt?date=DD.MM.YYYY`; amount per 1, 100 or 1000 units depending on currency. | HIGH |

## Standard Architecture

### System Overview

```
┌──────────────────────────────────────────────────────────────────────────┐
│                           PRESENTATION (thin)                             │
│  ┌────────────────────┐ ┌─────────────────────┐ ┌──────────────────────┐ │
│  │ Filament Admin     │ │ Filament Partner    │ │ REST /api/v1         │ │
│  │ resources, pages,  │ │ allowlisted         │ │ Sanctum, Admin-only  │ │
│  │ kanban, timer      │ │ resources/pages     │ │ time + timer         │ │
│  └─────────┬──────────┘ └──────────┬──────────┘ └──────────┬───────────┘ │
│            │   Livewire/HTTP       │                       │             │
│  ┌─────────┴───────────────────────┴───────────────────────┴───────────┐ │
│  │ Stripe webhook route (spatie/webhook-client): verify -> store -> 2xx │ │
│  └─────────────────────────────────┬────────────────────────────────────┘ │
├────────────────────────────────────┼─────────────────────────────────────┤
│                      DOMAIN (app/Domain/*, no Filament imports)           │
│  Actions (transactional use-cases)  ·  Policies  ·  Global scopes         │
│  Clients · Projects · Tasks · TimeTracking · Rates · Reports              │
│  Documents · Finance · Invoicing · Payments        Shared kernel:         │
│                                                    Money, Sequences,      │
│                                                    PartnerContext, Audit  │
├────────────────────────────────────┼─────────────────────────────────────┤
│                     ASYNC (queue + scheduler)                             │
│  KokpitJob base: idempotent · backoff · WithoutOverlapping · failed()     │
│  CNB fetch · PDF · e-mail · Stripe link · Stripe event · exports · ZIP    │
├────────────────────────────────────┼─────────────────────────────────────┤
│                              DATA / EXTERNAL                              │
│  ┌────────────────────────────┐ ┌───────────┐ ┌─────────────────────────┐│
│  │ PostgreSQL (source of      │ │ S3 bucket │ │ Stripe · CNB · ARES ·   ││
│  │ truth: FK, UNIQUE, partial │ │ (private) │ │ SMTP                    ││
│  │ indexes, CHECK, triggers)  │ │           │ │                         ││
│  └────────────────────────────┘ └───────────┘ └─────────────────────────┘│
└──────────────────────────────────────────────────────────────────────────┘
```

Principle: **Filament, API and jobs are adapters; invariants live in Domain Actions and in the database.** Every state change (start timer, move task, issue invoice, apply payment) is one Action class called by Filament, the API and jobs alike. Nothing important lives in Filament form callbacks, Livewire methods or model observers.

### Component Responsibilities

| Component | Responsibility | Typical Implementation |
|-----------|----------------|------------------------|
| Shared kernel | `Money`, `Currency`, `MoneyMath` (the single rounding point), `SequenceAllocator`, `PartnerContext`, `KokpitModel` base, `KokpitJob` base, UUID/morph conventions | Plain PHP classes + abstract Eloquent base |
| Identity | User, roles (Admin/Partner), Partner -> client link, API tokens | spatie/permission v7 (UUID-edited), Sanctum |
| Clients | Client, contacts, tags, ARES lookup, `invoice_language`, currency | Models + Actions; `AresClient` HTTP adapter (sync) |
| Projects | Project, key, visibility flag, billing terms (separate 1:1 table) | `Project` + `ProjectBillingTerms` (Admin-only table) |
| Tasks | Task/subtask, per-project counter, todos, comments (internal flag), status, position | `AllocateTaskNumber`, `MoveTask`, `ChangeTaskStatus` Actions |
| TimeTracking | Time entries, running timer, manual entries, billed lock, timesheet | `StartTimer`, `StopTimer`, `RecordManualEntry`; DB constraints |
| Rates | CNB daily rates, backfill, converter | `ExchangeRate` model, `FetchCnbRates` job, `CurrencyConverter` |
| Reports | Aggregations, CSV/XLSX, work-report PDF | Query objects (SQL aggregates), queued exports |
| Documents | S3 files via medialibrary, central listing, ZIP, bulk delete | Custom `Media` model (UUID), signed temp URLs |
| Finance | Income/expense transactions, categories, monthly/yearly overview in CZK | `Transaction` with CZK snapshot; listens to `PaymentApplied` |
| Invoicing | Invoice/proforma/credit note, items, numbering, issuing, PDF, e-mail, manual payment | `InvoiceStatus` enum state machine, `IssueInvoice` Action, snapshots |
| Payments | Stripe link, webhook processing, matching, duplicate/unmatched handling | `ProcessStripeEvent` job, `Payment` model |
| Settings | Supplier data, invoicing defaults, number formats | spatie/laravel-settings typed classes |
| Audit | Who changed what (Admin-only) | spatie/activitylog v5 with allowlist concern |

## Recommended Project Structure

```
app/
├── Domain/                         # business rules; MUST NOT import App\Filament or Livewire
│   ├── Shared/
│   │   ├── Money/                  # Money, Currency, MoneyMath, MoneyCast, ExchangeRate math
│   │   ├── Sequences/              # SequenceAllocator, NumberSequence model, SequenceFormatter
│   │   ├── Auth/                   # PartnerContext, PartnerScope, PartnerScoped contract, BasePolicy
│   │   ├── Models/                 # KokpitModel (HasUuids + PartnerScope + abstract constrainForPartner)
│   │   ├── Jobs/                   # KokpitJob base, FailureReporter
│   │   └── Audit/                  # LogsKokpitActivity concern (allowlist-only), custom Activity model
│   ├── Identity/                   # User, Role, Permission (UUID), PartnerAccount rules
│   ├── Clients/                    # Models, Actions, Policies, Scopes, Data, Enums, Services/AresClient
│   ├── Projects/                   # Project, ProjectBillingTerms, key rules
│   ├── Tasks/                      # Task, Todo, Comment; Actions/AllocateTaskNumber, MoveTask
│   ├── TimeTracking/               # TimeEntry; Actions/{StartTimer,StopTimer,...}; Services/Timesheet
│   ├── Rates/                      # ExchangeRate, Jobs/FetchCnbRates, Services/CnbClient, CurrencyConverter
│   ├── Reports/                    # Queries/*, Exports/*, WorkReportPdf
│   ├── Documents/                  # Media (UUID), DocumentPolicy, Actions/BuildZipDownload
│   ├── Finance/                    # Transaction, Category, Listeners/RecordIncomeOnPayment
│   ├── Invoicing/                  # Invoice, InvoiceItem, Enums/InvoiceStatus, Actions/IssueInvoice, Data/*Snapshot
│   └── Payments/                   # Payment, StripeWebhookCall, Jobs/ProcessStripeEvent, Stripe/* adapters
├── Filament/
│   ├── Admin/                      # Resources/<Module>/..., Pages (Kanban, Timesheet, System, Settings)
│   ├── Partner/                    # ALLOWLIST: Partner-only resources/pages with explicit columns
│   ├── Livewire/TimerWidget.php    # persistent timer (Admin only)
│   └── Providers/AppPanelProvider  # single panel; spa(); discoverResources for both dirs
├── Http/
│   ├── Controllers/Api/V1/         # thin adapters over Domain Actions
│   ├── Controllers/Downloads/      # invoice PDF / document streaming after policy check
│   └── Middleware/EnsureAdmin.php
└── Providers/                      # MorphMapServiceProvider, SchedulerServiceProvider
config/webhook-client.php           # stripe config entry
database/migrations/                # UUID everywhere; raw SQL for partial idx/CHECK/triggers/generated columns
lang/{cs,en}/                       # all user-facing strings (cs default; en for invoices)
routes/{web,api,webhooks}.php
tests/
├── Arch/                           # dependency rules, "every model declares Partner rule", no bare withoutGlobalScopes
├── Isolation/                      # Partner leak registry (grows each phase; Phase 14 closes gaps)
├── Concurrency/                    # real multi-process tests against PostgreSQL
└── Feature|Unit/<Module>/
scripts/                            # hygiene (step 0): sensitive-content check, hook installer
```

### Structure Rationale

- **`Domain` vs `Filament`:** Filament is replaceable UI; invariants must survive being called from API, jobs and tests. A Pest arch test (`expect('App\Domain')->not->toUse('App\Filament')`) enforces it.
- **`Filament/Partner` is an allowlist:** Partner never gets Admin resources with hidden fields. Partner-facing screens are separate classes listing exactly the columns/fields Partner may see. Admin resources return `canAccess() === false` for Partner. New Admin screens are therefore secure by default.
- **Modules mirror the brief's phase order** so each phase adds one folder and its migrations; dependencies flow one way (see Internal Boundaries).
- **Sensitive data in its own tables** (`project_billing_terms`, `time_entries`, rates, `payments`, `transactions`): Partner cannot read what a screen forgot to hide, because the whole table is deny-all for Partner.

## Architectural Patterns

### Pattern 1: Default-deny Partner isolation in three layers

**What:** (1) Database shape: sensitive data in Admin-only tables. (2) Model layer: every model extends `KokpitModel`, which registers `PartnerScope` and *forces* each concrete model to implement `constrainForPartner(Builder, User)` (abstract; there is no default, so a new model cannot compile without a decision). (3) Policy layer: `BasePolicy::before()` lets Admin through; Partner falls through to per-ability rules that re-check ownership (`$user->client_id === $model->client_id`). Filament/API/exports sit on top of those.
**When to use:** Always; this is the Security constraint from the brief.
**Trade-offs:** Scope + policy duplicate the rule (intentional; scope protects lists/search/relations/aggregates, policy protects single-record actions). Raw `DB::table()` bypasses scopes, so it is banned for domain tables outside Admin-only Reports (arch test).

```php
// app/Domain/Shared/Auth/PartnerScope.php
final class PartnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = app(PartnerContext::class)->partner();   // Auth user only if role = Partner, else null
        if ($user === null) {
            return;                                       // Admin, queue, console, seeders: unscoped
        }
        $model->constrainForPartner($builder, $user);     // abstract in KokpitModel
    }
}

// Examples of the per-model decision (each model MUST pick one)
final class Client extends KokpitModel {
    public function constrainForPartner(Builder $q, User $u): void { $q->whereKey($u->client_id); }
}
final class Project extends KokpitModel {
    public function constrainForPartner(Builder $q, User $u): void {
        $q->where('client_id', $u->client_id)->where('client_visible', true);
    }
}
final class Task extends KokpitModel {
    public function constrainForPartner(Builder $q, User $u): void {
        $q->whereIn('project_id', Project::query()->select('id'));   // reuses Project's scope (nested scope applies)
    }
}
final class TimeEntry extends KokpitModel {
    public function constrainForPartner(Builder $q, User $u): void { $this->denyPartner($q); } // whereRaw('false')
}
```

Rules that make it hold:
- `User` is the only unscoped model (authentication must resolve it); `PartnerContext` reads `Auth::user()` once and memoizes per request to avoid recursion.
- Never call bare `withoutGlobalScopes()`; only `withoutGlobalScope(SoftDeletingScope::class)`. Arch test greps for the bare form. Filament re-queries the record on every Livewire request through `getEloquentQuery()`, which observes global scopes by default; removing them removes them for the whole request, including global search (verified in Filament docs).
- Queue workers and CLI have no authenticated Partner, so scopes are inactive there by design. Therefore **jobs never use ambient auth**: any job that produces data for a Partner (e.g. invoice ZIP) receives `client_id` and applies `->where('client_id', $id)` explicitly.
- Partner has no API tokens: `/api/v1` is `auth:sanctum` + `EnsureAdmin`; token creation UI is Admin-only.

### Pattern 2: Hiding sensitive attributes everywhere (UI, search, activity log, exports, API)

| Surface | Mechanism |
|---------|-----------|
| Tables/forms | Partner sees only `Filament/Partner/*` classes with explicit columns; Admin resources deny Partner in `canAccess()` and are not registered in navigation. |
| Relation managers / `Select::relationship()` | Not relied on: the **model global scope** applies to every Eloquent query, whereas a resource's `getEloquentQuery()` does not run for relation queries. This is why the scope lives on the model, not only on the resource. |
| Global search | `globalSearchResourceOptIn()` on the panel; only Partner-safe resources set `$isGloballySearchable`; `getGloballySearchableAttributes()` is an explicit allowlist (searching a hidden column is an oracle that leaks its content). Results start from `getEloquentQuery()` so scopes apply. |
| Activity log | Activity is Admin-only (`constrainForPartner` = deny); Partner never gets a history tab. Every loggable model uses `LogsKokpitActivity` which requires an explicit `logOnly([...])` (no `logAll()`), enforced by an arch test; secrets (tokens, passwords, Stripe keys) are never in the list. v5 stores diffs in `attribute_changes`, so the allowlist matters for the diff as well. Settings changes are logged manually with redacted values (Settings are not Eloquent models). |
| Exports (CSV/XLSX/PDF/ZIP) | Admin-only except Partner invoice PDF download, which is a controller that runs the policy then streams the file via short-lived signed URL. Queued exports receive explicit IDs/filters, never ambient auth. |
| API | spatie/laravel-data response DTOs are the allowlist; spatie/query-builder `allowedFilters/allowedSorts/allowedIncludes` explicit; no Partner tokens. |
| Comments | `comments.internal = true` rows excluded in `Comment::constrainForPartner`; Partner-created comments forced `internal = false` in the Action, not in the form. |
| Notifications/e-mail | Built from Partner-safe DTOs, never from the Eloquent model with all columns. |

### Pattern 3: Gap-free number allocation with row locks

**What:** Numbers are consumed **inside the transaction that gives them meaning**. A rolled-back issue rolls back the counter increment, so no gaps. Never use PostgreSQL SEQUENCEs (they skip on rollback) and never `MAX()+1` (race).
**When to use:** Invoice/proforma/credit-note numbers (`number_sequences`), task numbers (`projects.next_task_number`).

```php
// Shared/Sequences/SequenceAllocator.php  (key e.g. "invoice:2026")
public function next(string $key): int
{
    throw_if(DB::transactionLevel() === 0, LogicException::class, 'Allocate inside the caller transaction.');
    DB::table('number_sequences')->insertOrIgnore([           // ON CONFLICT DO NOTHING
        'id' => (string) Str::uuid7(), 'key' => $key, 'next_value' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $row = DB::table('number_sequences')->where('key', $key)->lockForUpdate()->first();
    DB::table('number_sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1]);
    return $row->next_value;
}

// Tasks: single statement, row lock held until commit
$n = DB::selectOne(
    'UPDATE projects SET next_task_number = next_task_number + 1 WHERE id = ? RETURNING next_task_number - 1 AS n',
    [$projectId]
)->n;
```

Rules:
- DB backstops: `UNIQUE (project_id, number)` on tasks; `UNIQUE (number) WHERE number IS NOT NULL` on invoices (per series); `CHECK (next_value >= 1)`.
- Allocate at **issue time**, never at draft creation; drafts have no number.
- Sequence key includes the year computed in `Europe/Prague` at issue time (not UTC; an invoice issued at 00:30 on 1 January Prague time is still 31 December in UTC and would land in the wrong year's series). Format `{YYYY}{NNNN}` widens naturally beyond 9999 (`str_pad` never truncates); treat as a documented limit.
- Lock ordering is fixed (invoice row -> time entries -> sequence row) to avoid deadlocks; keep the transaction free of I/O (PDF/mail/Stripe go after commit).
- The import procedure sets `next_value` / `next_task_number` directly; allocator semantics "next value to hand out" make that trivial.
- Test with **real parallel processes against PostgreSQL** (`tests/Concurrency`, e.g. 20 workers x 10 allocations -> exactly 200 distinct consecutive numbers). SQLite or in-process tests cannot prove this (`lockForUpdate` is a no-op there).

### Pattern 4: Money value object with a single rounding point

**What:** `Money(int $minor, Currency $currency)` readonly. Currency is a backed enum of supported ISO 4217 codes with exponent (CZK 2, EUR 2, ...). No floats anywhere near money (PHPStan rule + `declare(strict_types=1)`). Add/subtract/compare require same currency. **Anything that can produce a sub-minor-unit value goes through `MoneyMath`**, the only class allowed to round (half-up, integer arithmetic).
**Where rounding happens (exactly two places):**
1. `MoneyMath::forDuration(int $seconds, Money $hourlyRate)`: `intdiv($seconds * $rate->minor + 1800, 3600)`. Called **once per invoice line** on the line's *total* seconds, not per time entry (sum of rounded != rounded sum). Guard `is_int()` after the multiply (PHP overflows to float silently).
2. `MoneyMath::convert(Money, ExchangeRate)`: CNB rate as `brick/math` `BigDecimal` (never float) with the quoted unit amount (1/100/1000); half-up to target minor units. The converted CZK amount and the rate are **stored as a snapshot**, never recomputed later.
**DB mapping:** `amount_minor bigint`, `currency char(3)`, `CHECK (currency ~ '^[A-Z]{3}$')`; a multi-column Eloquent cast (`MoneyCast`) maps pairs to `Money`. Filament display via `->money()` formatting of minor units.
**Trade-offs:** Own ~100-line VO instead of `brick/money`: the surface is tiny and nothing can round implicitly; cost is maintaining the currency enum (CNB list is the natural source). `brick/math` is still used for rates. (LOW-MEDIUM: taste decision, revisit in Phase 1 if multi-currency scope grows.)
Time entries store **no money**: amount = f(snapshotted rate, seconds) computed at billing, then frozen on invoice items.

### Pattern 5: Invoice issuing as a transactional state machine with snapshots

**What:** `InvoiceStatus` enum (`draft`, `issued`, `paid`) with an explicit `allowedTransitions()` map; overdue is *derived* (`issued` and `due_at < today`), not stored. Only Actions mutate status (`IssueInvoice`, `ApplyPayment`). Corrections after issue use credit notes, not edits.
**Issue transaction (one DB transaction, short):**
1. `Invoice::lockForUpdate()`; assert `draft`, has items, totals re-derived from items via `Money`.
2. `TimeEntry::whereIn(...)->lockForUpdate()`; assert none running, none billed already; link to items.
3. `SequenceAllocator::next()` for the series/year.
4. Write snapshots: `supplier_snapshot`, `customer_snapshot` (jsonb, `schema_version`), `exchange_rate_snapshot` (rate, unit, date, source), `language`, `vat_mode`, rate and quantity-in-seconds on each item.
5. Set `number`, `issued_at`, `due_at`, `status = issued`.
6. `afterCommit()`: dispatch `RenderInvoicePdf` -> `SendInvoiceEmail`, and `CreateStripePaymentLink`. All idempotent.
**DB enforcement (belt and braces):**
- `CHECK (status = 'draft' OR (number IS NOT NULL AND issued_at IS NOT NULL))`.
- `BEFORE UPDATE` trigger on `invoices`/`invoice_items` raising when `OLD.status <> 'draft'` unless only operational columns change (`status` draft->issued handled by its own rule, `paid_at`, `pdf_path`, `sent_at`, `stripe_*`). Same for `time_entries` where `invoice_item_id IS NOT NULL` (time/project/task/user columns frozen).
- Eloquent `updating` guard as the first, friendlier layer.
**Idempotence and concurrency:** double click or two admins -> second request blocks on the row lock, then sees `issued` and gets a typed `InvalidTransition`. Filament action also `requiresConfirmation()` and disables on submit.
**PDF from snapshots only:** the renderer reads snapshot columns, never live Client/Settings; the file is stored on S3 with a sha256; re-render is allowed and byte-equivalent in content.
**Payments:** separate `payments` rows; `paid` when applied sum >= total (computed under invoice row lock). Manual payment and Stripe both call `ApplyPayment`.

```php
public function __invoke(string $invoiceId): Invoice
{
    return DB::transaction(function () use ($invoiceId) {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
        $invoice->status->assertCanTransitionTo(InvoiceStatus::Issued);
        $entries = $this->lockAndValidateEntries($invoice);          // FOR UPDATE, not billed, not running
        $invoice->fill([
            'number'       => $this->formatter->format($invoice->series, $this->sequences->next($invoice->sequenceKey())),
            'issued_at'    => $now = CarbonImmutable::now('Europe/Prague'),
            'due_at'       => $now->addDays($this->settings->dueDays),
            'supplier_snapshot' => SupplierSnapshot::fromSettings($this->settings),
            'customer_snapshot' => CustomerSnapshot::fromClient($invoice->client),
            'exchange_rate_snapshot' => $this->rates->snapshotFor($invoice),
            'status'       => InvoiceStatus::Issued,
        ])->save();
        InvoiceIssued::dispatch($invoice->id);                        // ShouldDispatchAfterCommit
        return $invoice;
    });
}
```

### Pattern 6: Time-entry consistency in the database

```sql
CREATE TABLE time_entries (
  id               uuid PRIMARY KEY,
  user_id          uuid NOT NULL REFERENCES users(id),
  project_id       uuid NOT NULL REFERENCES projects(id),
  task_id          uuid NULL,
  description      text NULL,
  started_at       timestamptz(0) NOT NULL,          -- second precision => integer durations are exact
  ended_at         timestamptz(0) NULL,              -- NULL = running timer
  duration_seconds bigint GENERATED ALWAYS AS
                   (EXTRACT(EPOCH FROM (ended_at - started_at))::bigint) STORED,
  invoice_item_id  uuid NULL,                        -- FK added in invoicing phase; non-null = billed/locked
  created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
  CONSTRAINT time_entries_end_after_start CHECK (ended_at IS NULL OR ended_at > started_at),
  CONSTRAINT time_entries_task_in_project FOREIGN KEY (task_id, project_id)
      REFERENCES tasks (id, project_id)             -- needs UNIQUE (id, project_id) on tasks
);
CREATE UNIQUE INDEX time_entries_one_running_per_user ON time_entries (user_id) WHERE ended_at IS NULL;
CREATE INDEX time_entries_unbilled ON time_entries (project_id, started_at)
  WHERE invoice_item_id IS NULL AND ended_at IS NOT NULL;   -- feeds "unbilled time" dashboard
CREATE INDEX time_entries_user_started ON time_entries (user_id, started_at);
```

- Generated-column immutability of `timestamptz - timestamptz` should hold, but **verify in the migration test**; fallback is a plain column plus `CHECK (duration_seconds = EXTRACT(...))`. (MEDIUM)
- Laravel's builder cannot express the partial indexes; use `DB::statement` in migrations (that is fine and expected).
- `StartTimer` (one transaction): stop the current running entry at `$now`, insert the new one with the same `$now`; if two requests race, the partial unique index rejects one -> catch `UniqueConstraintViolationException`, return 409 / refresh. `StopTimer` is idempotent (no running entry -> no-op with a clear response).
- One `Clock` source (`CarbonImmutable::now()` truncated to seconds), stored UTC. Display `Europe/Prague`. Day grouping in timesheets/reports must use `AT TIME ZONE 'Europe/Prague'` (a UTC `date()` mis-buckets evening entries). Entries spanning midnight belong to the start day.
- Overlap prevention for one user (`EXCLUDE USING gist` with `btree_gist`) is **not** recommended by default: legitimate parallel work exists. Decide explicitly in Phase 6 (open question).
- Billed lock: `invoice_item_id IS NOT NULL` -> trigger rejects changes to time/project/task/user; deleting a *draft* item releases entries (`ON DELETE SET NULL`).
- Safety net for the core value: scheduled `AlertStaleTimers` (running > N hours) and an "unbilled time" digest.

### Pattern 7: Stripe webhook pipeline with DB-enforced idempotence

**Flow:** `POST /webhooks/stripe` -> `Route::webhooks()` (no CSRF/session) -> custom `StripeSignatureValidator` -> `StripeWebhookProfile` (only handled event types) -> store `StripeWebhookCall` -> dispatch `ProcessStripeEvent` -> 2xx immediately.

Why custom pieces (HIGH): the package's default validator is a plain HMAC of the body; Stripe signs `t=<ts>,v1=<hmac>` over `"{t}.{rawBody}"`, so use `Stripe\Webhook::constructEvent($request->getContent(), $header, $secret, 300)`; the tolerance must never be 0. The package's table has no external id, so extend it.

```php
// config/webhook-client.php (excerpt)
'configs' => [[
    'name' => 'stripe',
    'signing_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'signature_header_name' => 'Stripe-Signature',
    'signature_validator' => \App\Domain\Payments\Stripe\StripeSignatureValidator::class,
    'webhook_profile' => \App\Domain\Payments\Stripe\StripeWebhookProfile::class,
    'webhook_model' => \App\Domain\Payments\StripeWebhookCall::class,   // HasUuids, external_id, status
    'process_webhook_job' => \App\Domain\Payments\Jobs\ProcessStripeEvent::class,
]],
```

**Three independent DB idempotence layers** (do not rely on any single one):
1. **Ingest:** `webhook_calls.external_id` (= Stripe `evt_...`) with `UNIQUE (name, external_id)`; the profile fast-path checks existence, the unique index closes the race (catch the unique violation and answer 2xx).
2. **Job claim:** first statement of `ProcessStripeEvent::handle()` is an atomic claim `UPDATE webhook_calls SET status='processing', attempts=attempts+1 WHERE id=? AND status IN ('received','failed') RETURNING id`; 0 rows -> another run owns/finished it, return. Status ends `processed | ignored | failed` with `processed_at`.
3. **Domain:** `payments` has `UNIQUE (provider, provider_payment_id)` (Stripe PaymentIntent id, partial where not null). Stripe can also emit two distinct events for one fact, so uniqueness is on the *object*, not just the event.

**Matching:** read `invoice_id` from Checkout Session metadata (Payment Link `metadata` is auto-copied; also set `payment_intent_data.metadata`). Under invoice row lock: amount/currency must equal outstanding -> `matched` + `ApplyPayment`; invoice already paid -> `duplicate` (recorded, not applied, visible in Payments overview); no/unknown invoice or amount mismatch -> `unmatched`/`needs_review` (Admin assigns manually).
**Order independence (Stripe does not guarantee order, `created` is not an ordering key):** handlers derive state from the object, not from the previous event; for async methods handle `checkout.session.completed` (check `payment_status`) and `checkout.session.async_payment_succeeded/failed` independently; optionally re-fetch the Session via API inside the job for freshness.
**Operations:** retention (`delete_after_days`) >= 30 days (Stripe manual resend window up to 30 days via CLI); failed calls shown in Payments overview with a "Retry" (re-dispatch; claim makes it safe). Deactivate the Payment Link when the invoice is paid (queued, idempotent, Stripe idempotency key = invoice id).

### Pattern 8: Queue and job design

**Driver recommendation (decide in Phase 1): PostgreSQL `database` queue** for v1: single company, trivial volume, no extra Zerops service, and dispatch inside a transaction is atomic with the data (transactional outbox for free). Set `after_commit => true` regardless. Horizon is Redis-only, so failure visibility is built (below). Switch to Redis only if a measurable need appears.

```php
abstract class KokpitJob implements ShouldQueue
{
    use Queueable, SerializesModels;
    public int $tries = 5;
    public int $maxExceptions = 3;
    public function backoff(): array { return [15, 60, 300, 900]; }       // exponential-ish, seconds
    public function middleware(): array {
        return [(new WithoutOverlapping($this->lockKey()))->releaseAfter(30)->expireAfter(300)];
    }
    abstract protected function lockKey(): string;                         // aggregate id, e.g. "invoice:{$id}"
    public function failed(Throwable $e): void { app(FailureReporter::class)->report($this, $e); }
}
```

Rules: (a) payload = IDs, not models with state; (b) every job is idempotent by a natural key (`pdf_sha256` exists -> skip, `UNIQUE (date, currency)` upsert for rates, Stripe idempotency keys, claim-before-work for webhooks); (c) external calls use `ThrottlesExceptions`/`Http::retry` only for transient errors; permanent errors `fail()` fast; (d) side effects after commit; (e) no ambient auth (see Pattern 1).
**Failure visibility:** `FailureReporter` writes a Filament database notification to Admins + log; an Admin-only **System** page lists `failed_jobs`, failed `webhook_calls`, last scheduler heartbeat, last successful CNB date (stale > 3 days = red). `spatie/laravel-health` (queue, schedule, DB checks) is a good fit (MEDIUM; confirm AGPL compatibility, MIT expected). Scheduler: `Schedule::job(...)->onOneServer()->withoutOverlapping()`; run `schedule:work` as a Zerops worker/cron; CNB job at ~15:00 Prague with retries (rates are published ~14:30), weekend/holiday handled by "latest available <= date".
**Job inventory:** `FetchCnbRates`, `RenderInvoicePdf`, `SendInvoiceEmail`, `CreateStripePaymentLink`, `DeactivateStripePaymentLink`, `ProcessStripeEvent`, `RenderWorkReportPdf`, `BuildZipDownload`, `RunReportExport`, `AlertStaleTimers`, `AlertOverdueInvoices`/`UnbilledTimeDigest`, `model:prune` for webhook calls.

### Pattern 9: Persistent timer widget

**What:** Livewire component `TimerWidget` rendered through `FilamentView::registerRenderHook(PanelsRenderHook::TOPBAR_END, ...)` (hook names verified: `TOPBAR_START/END`, `USER_MENU_BEFORE`, `GLOBAL_SEARCH_*`, `BODY_START/END`); the callback returns nothing for Partner.
**Design:** the **server row is the only state**. The component renders `started_at` (epoch seconds) + `server_now`; Alpine ticks locally every second from `started_at` with an offset computed from `server_now` (clock-skew safe). No per-second Livewire polling. Resync on `wire:poll.60s`, on `visibilitychange`, and on a `timer-changed` Livewire event (dispatched by every Start/Stop Action, so task rows, the time table and API-driven changes converge). Stop sends the entry id; if that entry is no longer running (stopped via API in another tab) the component just refreshes. Survives SPA navigation because it recomputes from server state on mount; wrapping in `@persist` is an optimisation, not a dependency (MEDIUM: verify whether the topbar sits inside the swapped region in Filament v5). Start buttons on task rows/pages call the same `StartTimer` Action.

### Pattern 10: Kanban

Own thin Filament Page + Livewire component (about 200 LOC) rather than a plugin: upstream `mokhosh/filament-kanban` targets Filament 3; v4/v5 exist only as forks of uncertain maintenance (MEDIUM). Columns = fixed task status enum; one board query per column with limit (e.g. 50 + "load more"; `done` limited to recent N days) so the board never loads the whole table. Ordering: `spatie/eloquent-sortable` with group = `(project_id, status)`; `MoveTask(task, toStatus, afterTaskId)` runs in a transaction, re-numbers only the affected column, reuses `ChangeTaskStatus` (same Action as the edit form, so `completed_at` etc. stay consistent). Drag and drop via the Alpine sort plugin / `wire:sort` (verify which Livewire 4 provides); optimistic move with server rollback on failure. Global board = same component without the project filter (+ project/assignee filters). Admin-only; Partner cannot change status/priority (assumption kept from the brief) so Partner gets the list view, not the board.

### Pattern 11: Settings

`spatie/laravel-settings` typed classes: `CompanySettings` (supplier identity, bank, logo), `InvoicingSettings` (due days, series/number format patterns, default language, `vat_mode = non_payer`), `IntegrationSettings` (non-secret toggles only). **Secrets (Stripe keys, webhook secret, S3, mail) stay in env**, never in the DB or the activity log. Settings is an Admin-only Filament page; every save is logged manually with redaction. Invoice issuing copies settings into snapshots, so later edits never alter issued documents. Defaults in the repo are fictional placeholders (`Example Ltd`). The package's own `settings` table keeps its package key (no relations); declare this exception in the UUID checklist.

## UUID v7 Everywhere: Convention and Checklist

- Own tables: `$table->uuid('id')->primary()`, FKs `foreignUuid()->constrained()`, models use `HasUuids` (v7 by default) via `KokpitModel`. Optional on PG 18: `DEFAULT uuidv7()` so raw inserts also get ordered ids.
- `Relation::enforceMorphMap([...])` in `MorphMapServiceProvider` (short stable aliases, no class names in DB).
- Package/framework tables that need editing **before the first migrate** (publish, edit, commit):

| Table | Change |
|-------|--------|
| `model_has_roles`, `model_has_permissions`, `roles`, `permissions` | uuid model key (+ custom Role/Permission with `HasUuids`; optional `model_morph_key = model_uuid`) |
| `activity_log` | `uuid id`, `nullableUuidMorphs('subject')`, `nullableUuidMorphs('causer')` + custom `Activity` model (`HasUuids`) |
| `media` | `uuid id`, `uuidMorphs('model')` + custom `Media` model (config `media_model`) |
| `tags`, `taggables` | uuid ids, `uuidMorphs('taggable')` + custom `Tag` model |
| `personal_access_tokens` (Sanctum) | `uuidMorphs('tokenable')`, uuid id + `Sanctum::usePersonalAccessTokenModel()` (MEDIUM) |
| `webhook_calls` | `uuid id` + extra columns (see Pattern 7) + custom model |
| `notifications` (Filament DB notifications) | `uuidMorphs('notifiable')` |
| `sessions` (if database driver) | `user_id` as `uuid` instead of `foreignId` |
| `exports`/`imports`/`failed_import_rows` (if Filament export used) | `user_id` uuid |
| Left as-is (framework internals, no domain relations) | `jobs`, `failed_jobs`, `job_batches`, `cache`, `cache_locks`, `migrations`, `password_reset_tokens`, `settings` |

- A Pest arch/DB test inspects `information_schema` and fails on any non-whitelisted table whose PK or `*_id`/morph id column is not `uuid`.

## Data Flow

### Request Flow

```
[User action: Filament / API]
    -> auth + role middleware
    -> Policy (ability) ---------------------------> 403
    -> Domain Action (DB::transaction, locks)
         -> Model (PartnerScope on every read) -> PostgreSQL (FK/UNIQUE/CHECK/triggers)
         -> domain event (afterCommit) -> listeners / queued jobs
    <- DTO/Resource (allowlisted fields) <- response
```

### State Management

Server state only. Livewire components hold IDs and render from the DB; the timer and kanban never trust client state (timer ticks locally from server timestamps, kanban sends "move X after Y" and re-renders from the DB).

### Key Data Flows

1. **Time -> invoice:** TimeEntry (UTC seconds, no money) -> Admin picks unbilled entries per project -> draft Invoice items (`seconds`, snapshotted rate, one rounding per line) -> `IssueInvoice` (lock, number, snapshots) -> after commit: PDF -> e-mail -> Stripe link -> client sees invoice in Partner view.
2. **Payment -> income:** Stripe event -> signed, stored, claimed -> `Payment` matched under invoice lock -> `ApplyPayment` -> invoice `paid` -> `PaymentApplied` event -> Finance listener creates an income `Transaction` with a CZK snapshot.
3. **Exchange rates:** scheduler -> `FetchCnbRates` (upsert by `(date, currency)`) -> `CurrencyConverter` reads "latest rate on/before issue date" -> snapshot on the document.
4. **Partner read:** Partner request -> scoped query -> allowlisted Partner screen; anything not on the allowlist is invisible by table and by scope.

## Scaling Considerations

| Scale | Architecture Adjustments |
|-------|--------------------------|
| One company, 1 admin + tens of Partners | Monolith, one web process, one queue worker, DB queue, one scheduler. This is the target. |
| Hundreds of thousands of time entries | Indexes above suffice; reports use SQL aggregates and streamed exports (`lazyById`); no caching needed yet. |
| Many Partners/heavy PDF volume | Move queue to Redis, separate worker container, PDF driver as service (Gotenberg). Not planned. |

### Scaling Priorities

1. **First bottleneck:** PDF rendering CPU/memory (Chromium) inside the web/worker container; choose the driver deliberately in Phase 10/12 (DOMPDF is zero-dependency; Browsershot needs Node + Chromium on Zerops).
2. **Second bottleneck:** report queries over `time_entries` without date-bounded indexes; keep `(project_id, started_at)` and `(user_id, started_at)`.

## Anti-Patterns

### Anti-Pattern 1: UI-only hiding (`->visible()`, hidden columns)

**What people do:** Hide rates/finance in Filament with `visible(fn () => isAdmin())`.
**Why it's wrong:** Search, relation selects, exports, Livewire payloads, API and notifications still read the column.
**Do this instead:** Physical separation of sensitive tables, model-level default-deny scopes, Partner allowlist screens, plus the isolation test registry.

### Anti-Pattern 2: Bare `withoutGlobalScopes()` or `DB::table()` on domain tables

**What people do:** Remove scopes to "make a list work" or write raw aggregates.
**Why it's wrong:** Silently disables Partner isolation for the whole request (Filament re-queries records through the same builder).
**Do this instead:** Remove one named scope; arch test forbids the bare form; raw aggregates only in Admin-only Reports.

### Anti-Pattern 3: Numbers from `MAX()+1` or DB sequences; allocation at draft time

**Why it's wrong:** Races produce duplicates; sequences and early allocation produce gaps on rollback/deleted drafts.
**Do this instead:** Pattern 3.

### Anti-Pattern 4: Rounding per time entry, floats, or recomputing from live rates

**Why it's wrong:** Totals drift, invoices change after the fact.
**Do this instead:** Integer seconds, one rounding per line via `MoneyMath`, snapshot rate/exchange rate.

### Anti-Pattern 5: Rendering invoice PDFs from live Client/Settings

**Why it's wrong:** A re-render after an address change alters an issued document.
**Do this instead:** Render from snapshots only; store file hash.

### Anti-Pattern 6: Business logic in observers, Filament callbacks or Livewire methods

**Why it's wrong:** API/jobs bypass it; hidden coupling; untestable concurrency.
**Do this instead:** Single-purpose Actions; UI and API are adapters.

### Anti-Pattern 7: Processing Stripe in the controller, ordering by `created`, trusting one idempotence layer

**Do this instead:** Pattern 7: verify, store, 2xx, claim, apply under row lock, unique on the Stripe object.

### Anti-Pattern 8: Testing concurrency/constraints on SQLite or mocks

**Why it's wrong:** No partial indexes, CHECK semantics differ, `lockForUpdate` is a no-op.
**Do this instead:** PostgreSQL service container in CI from Phase 1; `RefreshDatabase` + real-process concurrency tests.

### Anti-Pattern 9: Package defaults for morph columns, `logAll()`, kanban plugin forks, per-second Livewire polling

**Do this instead:** UUID checklist, `logOnly` allowlist, own thin board, server-timestamp-driven Alpine timer.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| Stripe | Payment Links created in a queued job (idempotency key = invoice id); webhook via spatie/webhook-client; `stripe/stripe-php` for signature check | Secrets in env; raw body untouched; exclude route from CSRF/session; 3-day retry window; Stripe also lists IPs for allowlisting |
| CNB | Scheduled queued job, `daily.txt?date=` | Per-1/100/1000 units; no publication on weekends/holidays; alert on stale data |
| ARES | Synchronous HTTP call on client form (user waits), short timeout, graceful degradation | Never blocks saving; verify current ARES REST endpoint in Phase 2 |
| S3-compatible bucket | medialibrary private disk; temporary URLs after policy check | Provider decided in Phase 1 |
| SMTP / mail provider | Queued mailables per `clients.invoice_language` | Partner-safe DTOs only |
| GitHub Actions + Zerops | CI (PostgreSQL service, gitleaks, tests); deploy from release/dispatch behind `production` approval | Hygiene step 0 owns the scanning pipeline |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| Filament/API -> Domain | Call Actions, read via scoped models/DTOs | Never the reverse; arch-tested |
| Tasks -> Projects -> Clients | Direct FK/model use | One-directional |
| TimeTracking -> Invoicing | `invoice_item_id` link set only by Invoicing Actions | TimeTracking exposes `lockUnbilled()`/`markBilled()` |
| Invoicing -> Rates, Settings, Documents | Direct service calls inside the issue transaction (snapshots), file storage after commit | No I/O inside the transaction |
| Payments -> Invoicing | `ApplyPayment` Action only | Payments never edit invoice columns directly |
| Finance <- Invoicing/Payments | Domain events (`PaymentApplied`) | Invoicing does not know Finance |
| Reports -> everything | Read-only query objects, Admin-only | Only place raw aggregates allowed |

## Build Order (matches the brief's phase order)

Cross-cutting infrastructure is **front-loaded**, even if first used later, because retrofitting it is the expensive part. Each phase also adds its rows to the Partner isolation registry.

| # | Phase | Architecture deliverables | Pitfall avoided |
|---|-------|---------------------------|-----------------|
| 0 | Hygiene | `.gitignore`, sensitive-content script, hook, gitleaks, CI scan, CLAUDE.md rule | Leaking real data into a public repo |
| 1 | Foundation | Laravel 13 + Filament 5 SPA panel; PostgreSQL test DB in CI; UUID conventions + edited package migrations + morph map + schema test; `KokpitModel`/`PartnerScope`/`BasePolicy` + arch tests; Admin/Partner roles; `Money`/`MoneyMath`; `KokpitJob` base + `FailureReporter` + System page skeleton; settings; activity-log concern; queue driver decision; scheduler | UUID retrofit, fail-open isolation, SQLite-tested concurrency |
| 2 | Clients | Client, contacts, tags, Partner account link (`users.client_id`), ARES lookup, first Partner allowlist screen + isolation registry | Partner sees other clients |
| 3 | Projects | Project (key, `client_visible`), `project_billing_terms` (Admin-only table) | Rates/prices in a Partner-readable table |
| 4 | Tasks | `SequenceAllocator` + per-project counter, subtasks, todos, comments with `internal`, list view | Duplicate/gapped `KEY-N`; internal comments leaking |
| 5 | Kanban | Own board page, `MoveTask`, sortable positions | Plugin lock-in; loading whole table |
| 6 | Time | `time_entries` constraints, Start/Stop/Manual Actions, billed-lock trigger (marker column), timer widget, timesheet | Two running timers, mis-bucketed days, editable billed time |
| 7 | API | `/api/v1` (Sanctum abilities, rate limit, OpenAPI), Admin-only, Data DTOs | Partner tokens, over-exposed includes |
| 8 | Rates | CNB fetch job, backfill command, converter, failure alert | Float rates, silent stale rates |
| 9 | Reports | SQL aggregates, streamed CSV/XLSX, work-report PDF, dashboard | Raw queries leaking, N+1 on big ranges |
| 10 | Documents | medialibrary (UUID), S3 private, ZIP job, bulk delete; **PDF driver decision** | Public bucket, bigint morphs |
| 11 | Finance | Transactions/categories, CZK overview, `PaymentApplied` listener stub | Mixing currencies without snapshot |
| 12 | Invoicing | Series/sequences, `IssueInvoice`, snapshots, triggers, PDF from snapshots, e-mail, manual payment, Partner invoice view | Gaps, mutable issued invoices, live-data PDFs |
| 13 | Stripe | Payment Link job, webhook pipeline (3 idempotence layers), matching, duplicate/unmatched, Payments overview + retry | Double-applied payments, lost events |
| 14 | Partner audit | Close gaps in the isolation registry; route audit (every non-allowlisted route authenticated), enumerate Filament resources/pages/API x roles | Late discovery of leaks |

Ordering rationale: Money and scopes must exist before Projects (rates); the sequence allocator is proven on tasks (simple) before invoices (critical); Rates precede Reports/Finance/Invoicing (conversion + snapshots); Documents precede Invoicing (PDF storage); Finance precedes Invoicing so manual payment can emit income; Stripe is last because it only extends `ApplyPayment`. **Do not defer leak tests to Phase 14**: the registry starts in Phase 2; Phase 14 is an audit, not the first test.

## Open Questions / Research Flags

- Phase 1: queue driver (recommend DB), S3 provider, PHP 8.5 compatibility of every Spatie package at install time, PG version on Zerops (uuidv7 default, generated columns), `spatie/laravel-health` licence check.
- Phase 2: one Partner account per client (`users.client_id`, assumed) vs many-to-many; current ARES REST endpoint.
- Phase 6: allow overlapping entries per user? Filament v5 topbar vs `@persist`; generated-column immutability check.
- Phase 7: OpenAPI generator choice (e.g. dedoc/scramble, MIT) and Sanctum UUID token model.
- Phase 10/12: PDF driver on Zerops (DOMPDF vs Browsershot/Gotenberg), Czech font handling.
- Phase 12: Czech invoicing specifics (mandatory fields for a non-VAT payer, proforma settlement, credit-note numbering/sign convention, QR payment) need dedicated research; credit note effect on billed time entries.
- Phase 13: confirm the final event matrix (`checkout.session.completed`, `async_payment_succeeded/failed`, `expired`) against current Stripe docs.

## Sources

- Laravel 13.x and 12.x Eloquent docs, UUID section (HasUuids = UUIDv7 by default): https://laravel.com/docs/13.x/eloquent (HIGH)
- Filament v5 announcement: https://laravel-news.com/filament-5 (HIGH); Filament docs: resources overview (getEloquentQuery, global scopes, record re-query), global search, render hooks: https://filamentphp.com/docs/4.x/resources/overview, https://filamentphp.com/docs/4.x/resources/global-search, https://filamentphp.com/docs/5.x/advanced/render-hooks (HIGH)
- spatie/laravel-permission UUID guide: https://spatie.be/docs/laravel-permission/v7/advanced-usage/uuid (HIGH)
- spatie/laravel-activitylog v5 upgrade notes and migration stub: https://raw.githubusercontent.com/spatie/laravel-activitylog/main/UPGRADING.md ; https://spatie.be/docs/laravel-activitylog/v5/advanced-usage/logging-model-events (HIGH)
- spatie/laravel-webhook-client README and migration stub: https://github.com/spatie/laravel-webhook-client (HIGH)
- spatie/laravel-pdf v2 drivers: https://spatie.be/docs/laravel-pdf/v2/introduction (HIGH)
- Stripe webhooks (duplicates, ordering, retries, raw body, tolerance): https://docs.stripe.com/webhooks ; Payment Link metadata copied to Checkout Sessions: https://docs.stripe.com/api/payment-link/create (HIGH)
- CNB exchange rate fixing (publication time, daily.txt, per-unit amounts): https://www.cnb.cz/en/financial-markets/foreign-exchange-market/central-bank-exchange-rate-fixing/central-bank-exchange-rate-fixing/ (HIGH)
- Filament kanban plugin landscape (upstream F3 only, forks for v4/v5): https://filamentphp.com/plugins/mokhosh-kanban ; https://packagist.org/packages/sheavescapital/filament-kanban (MEDIUM)
- PostgreSQL patterns (partial unique index, composite FK, generated columns, SELECT FOR UPDATE, UPDATE ... RETURNING): established practice, to be re-verified by migration tests (MEDIUM-HIGH)

---
*Architecture research for: single-tenant Laravel + Filament CRM/ERP (Kokpit)*
*Researched: 2026-10-06*
