# Phase 9: Documents and Finance - Research

**Researched:** 2026-10-16
**Domain:** Private-storage document module (Spatie medialibrary behind a `documents` entity, Partner-scoped visibility, ZIP in the background, app-routed signed downloads) plus a finance module (transactions, two-level categories, CZK snapshots, month/year overview) on Laravel 13 / Filament 5 / PostgreSQL 18
**Confidence:** HIGH for the stack and the in-repo integration points (all read from source this session); MEDIUM for deployment behaviour (Zerops multi-container, browser reach of the bucket); the open decisions are listed in the Assumptions Log and Open Questions.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): Partner never sees time, rates, prices or finance, and isolation is enforced by Policies and global scopes with fail-closed `#[AccessRule]` / `#[NotPartnerScoped]` / `#[DeniesPartners]` (Phase 2); `App\Domain\Shared\Models\Media` (UUID v7, `DeniesPartners`, "later phases open it deliberately with a client-bound constraint and explicit policy grants") is registered in `config/media-library.php`, with `MEDIA_DISK=s3` on the private bucket (Phases 2-3); money is bigint minor units plus ISO 4217 currency; time is stored UTC and shown in `Europe/Prague`; CNB rate of the transaction day with the previous valid rate for weekends and holidays, and the conversion helper `ExchangeRateConverter` (Phase 8 D-01, D-02, EX-03); report exports live 7 days on private storage, are downloaded through the app with a permission check and delivered by bell notification (Phase 8 D-09); `KokpitJob` base for queued jobs (Phase 3); Partner comments are never internal and Partner never sees internal comments or their attachments (Phase 5, TA-04); Admin notifications for Partner activity are bell only, no e-mail (Phase 5 D-15).

**Files: data model and attachment (DO-01)**
- **D-01:** Every file is a row in a `documents` table with exactly one Spatie medialibrary media (the existing UUID `Media` model, private disk). The row holds name, optional description, the Partner-visibility flag, an optional owner morph (client, project, task, comment, transaction; invoice is added in Phase 10), a denormalized `client_id` (resolved from the owner and kept consistent) and the uploader. A file without owner is "unattached". The central listing, filters, Partner scope and storage totals are queries over `documents`. — **Reversibility:** costly — the central listing, Partner scope, ZIP job, totals and the Phase 10 invoice PDF all depend on this table shape.
- **D-02:** Admin manages files in a shared "Soubory" relation manager (one implementation reused) on client, project, task and transaction detail: upload, rename, visibility toggle, download, delete. Attachments of comments are uploaded directly in the comment form. The inert "attachments later" placeholders and `fileAttachments(false)` markers left by Phase 5 (A10, D-11) are replaced.

**Files: Partner visibility (DO-01)**
- **D-03:** The visibility flag `visible_to_client` defaults to **true** for files uploaded on a project or task (owner decision; Admin can hide a file at upload or later). Files owned by a client, a transaction or nobody are never visible to Partner whatever the flag says; invoice PDFs reach Partner only through the Phase 10 invoice view. The flag only has effect through the Partner scope; the DB-level rule is the scope plus policy, not the UI.
- **D-04:** A Partner sees and downloads a file only when all of these hold: it belongs to a project the Partner can access (project `client_visibility` and own client) or a task of such a project; the flag is true; and it is not an attachment of an internal comment. Never another client's files. Search, selects, counts, notifications and the activity log must not leak file names or existence either (canary harness extended).
- **D-05 (owner decision, widens Phase 5 A10):** Partner can **upload** files, but only on tasks and non-internal comments they authored, in visible projects of their own client (when creating a task and in their own comments). Partner cannot attach files to someone else's task or comment, to a project or client, or to a comment marked internal (Partner comments are never internal). Partner-uploaded files are visible to the Partner at once (flag true, Partner cannot change it). Partner may delete only their own uploaded files while the task is open; Admin may delete anything. Admin gets a bell notification on every Partner upload (no e-mail). — **Reversibility:** costly — reopens a closed Phase 5 decision; Partner upload forms, policy, notification and isolation tests all depend on it.
- **D-06:** Upload limits are the same for Admin and Partner (owner decision): the medialibrary default maximum of 10 MB per file (value in `config/kokpit.php`) and the package's disallowed-extension list, no separate whitelist. Because a Partner can upload, the planner must treat uploaded content as untrusted: sanitized stored names, no inline rendering except images and PDF (see D-10), `X-Content-Type-Options: nosniff`, and no public URL.

**Central listing, ZIP, deletion, totals (DO-02, DO-03)**
- **D-07:** The Admin-only central documents listing shows name, owner type with a link to the owner, client, size, MIME type, uploader, date and the visibility flag; all columns sortable. Filters: owner type, client, project, date range, file-type group and visibility; search in the name. Unattached files show with owner "unattached".
- **D-08:** ZIP download is a bulk action over the selected rows (including a filter result), built by a queued `KokpitJob` (idempotent), kept 7 days on private storage like report exports (value in `config/kokpit.php`), then deleted by the scheduled prune; Admin gets a bell notification with a link; download goes through the app with a permission check. Maximum file count and total size per ZIP are configured in `config/kokpit.php`. A "download everything of a client or project" button is not part of this phase.
- **D-09:** Deleting a file, single or bulk, is permanent after a confirmation dialog and removes the stored S3 object together with the row (DO-03); each deletion is written to the activity log (name, size, owner type). No trash. Deleting an owning record also removes its files and objects. Confirmation for issued invoice PDFs is built in Phase 10. — **Reversibility:** one-way — a deleted S3 object cannot be recovered by the app.
- **D-10:** Every download and preview goes through an app route that checks the policy and then hands out a short-lived signed URL (or streams); no public bucket URL exists. The TTL is short (seconds to minutes, value in `config/kokpit.php`). Images and PDF can be previewed inline in the browser; every other type downloads as an attachment.
- **D-11:** Storage totals are stat tiles above the listing (total file count and size) and a by-client table (client, file count, size) with an "Unattached" row; a file counts for the client in its denormalized `client_id` (project, task and comment files roll up to the project's client). Sizes are the sum of original files only (no conversions).

**Transactions and CZK amount (FI-01)**
- **D-12:** A transaction has type (income/expense), date (Europe/Prague calendar date), category, currency, amount in minor units, amount in CZK, description, free-text counterparty, optional document number, optional client and optional project (the project must belong to the chosen client). The invoice link is added in Phase 10. Receipts are file attachments through the shared Soubory relation manager (D-02). Admin-only; Partner has no access.
- **D-13:** Any currency stored in the CNB rates can be used. The CZK amount is computed from the CNB rate of the transaction date (previous valid rate for weekends and holidays, `ExchangeRateConverter`) and stored together with the rate as a snapshot, so later rate changes never alter it. Admin may overwrite the CZK amount with the real figure from the bank; a manually set amount is flagged as manual and kept. When no rate exists for the currency and date, the form asks for the CZK amount manually and does not block saving. CZK transactions need no rate.
- **D-14:** Transactions are freely editable and deletable by Admin (changes recorded in the activity log with an explicit attribute allowlist; deleting a transaction deletes its receipt files with confirmation per D-09). The list has a receipt-count column and a "without receipt" filter. There is no period closing. Transactions created from invoice payments in Phase 10 (FI-03) will be restricted to editing date and category; that restriction is built there.

**Categories and overview (FI-01, FI-02)**
- **D-15:** Categories are two levels (parent and child). Each category has a type (income or expense); a child inherits its parent's type; a transaction takes only a category of the same type. Categories can be archived but not deleted once used (DB restrict FK); unused categories can be deleted. Order is manual (eloquent-sortable).
- **D-16:** The app seeds a small generic set of Czech-named categories with fictional-safe generic content (for example invoicing, software, fees, tax payments) and one system income category for invoice payments, used by Phase 10 (FI-03), which can be renamed but not deleted or archived. No real business data.
- **D-17:** The finance overview is one Admin page with a Month/Year switch: tiles for income, expense and balance in CZK; a category table that rolls child sums into the parent; a by-client table with a "No client" row; and a bar chart of months in the selected year (Filament chart widget). Periods use the transaction date in Europe/Prague; all sums are in CZK from the stored CZK amounts.

### Claude's Discretion
Class, file, table and index names; the exact shape of the owner morph and how `client_id` is kept consistent (DB trigger, model hook or action); whether comment file uploads reuse the relation-manager component; signed-URL mechanism details (redirect to S3 temporary URL vs stream); the file-type group definitions for the filter; ZIP library and entry naming; category seed names (Czech, generic); exact CHECK constraints (type match, project-belongs-to-client); Czech wording; chart styling; how a Partner upload form is added to the create-task and own-comment forms; test structure.

### Deferred Ideas (OUT OF SCOPE)
- "Download everything of a client or project" one-click ZIP — not in this phase.
- Period closing for finance — not in this phase.
- Stricter file-type whitelist or malware scanning for uploads — not chosen; revisit if Partner uploads become a concern.
- (Reviewed todo, not folded) "Redesign unreadable time report layout" — a Phase 8 gap already covered by UI-SPEC Amendment A-1.

Out of this phase by the boundary text: the invoice reference on transactions, income from invoice payments (FI-03) and confirmation before deleting an issued invoice PDF (DO-03 part) belong to Phase 10.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DO-01 | Files uploaded to clients, projects, tasks, comments, invoices (Phase 10), transactions or unattached on S3; Partner sees only files on accessible projects/tasks, excluding internal-comment attachments, and only those flagged visible | `documents` table + HasMedia, owner morph, `Document::constrainForPartner` delegating to the scoped Project / Task / TaskComment queries, policy defence in depth, Filament `FileUpload` with `storeFiles(false)` feeding one `AttachDocuments` Action, Livewire temp-upload disk fix, canary fixtures |
| DO-02 | Admin central listing with filters, sorting, single and ZIP download (queued), bulk delete, storage totals overall and per client | `DocumentResource` (Admin-only) with filters and `CzechCollation` sorting, header stats widget, by-client table, `BuildDocumentArchive` job on ZipStream (already installed), `document_archives` record + prune + bell |
| DO-03 | Downloads always go through the app with permission check and short-lived signed URL; deleting a record removes the S3 object; issued invoice PDFs need explicit confirmation to delete (Phase 10 part) | Panel `authenticatedRoutes` signed route that streams (the DDEV bucket is not browser-reachable, Livewire downloads base64-buffer the whole file), `DeleteDocument` Action running as system inside a DB transaction, owner-deletion hook |
| FI-01 | Income and expense transactions with hierarchical categories, currency, CZK amount, optional client/project/invoice (invoice in Phase 10), receipts as attachments | `transaction_categories` and `transactions` tables with composite FKs (type match, project-belongs-to-client, two levels), CZK snapshot via `ExchangeRateConverter::rateFor` + `Money::convert`, Soubory relation manager on transactions |
| FI-02 | Overview per month/year, per category and per client in CZK | `FinanceOverviewPage` with `HasFiltersForm`, Stats and Chart widgets via `InteractsWithPageFilters`, rollup query on stored CZK minor units |
</phase_requirements>

## Summary

Phase 9 is mostly integration work on top of packages that are already installed and conventions that are already enforced by tests: Spatie medialibrary 11.23.9 (`Media` is already a UUID model, `DeniesPartners`, `MEDIA_DISK=s3`), Filament 5.10.2, Livewire 4.4.7, `maennchen/zipstream-php` 3.2.2 (a transitive dependency of medialibrary, so no new package), `spatie/eloquent-sortable`, the Phase 8 `ExchangeRateConverter` and the `KokpitJob` / report-export pipeline. No new dependency is needed. The hard part is not the libraries but five integration traps that the code base already hints at and that I confirmed in vendor source: (1) with `FILESYSTEM_DISK=s3` and no `config/livewire.php`, Livewire sends browser uploads straight to S3 with a presigned URL, and the DDEV bucket (`rustfs:9000`, only `expose`d, not published) is unreachable from the browser; (2) the same reason makes a redirect to an S3 presigned download URL unusable in development, and a Livewire action download buffers and base64-encodes the whole file into the JSON response, so neither is suitable for a ZIP; (3) `Media` is `DeniesPartners`, so any code path that runs as a Partner and touches `$document->media` (including the package's own delete-media-on-delete hook) silently finds nothing and leaves S3 objects behind; (4) a Partner-readable `documents` table must satisfy the pinned-column, canary and registry tests; (5) a `FileUpload` string state is client-controlled, so only `TemporaryUploadedFile` instances may reach the Action.

The recommended shape is a thin `App\Domain\Documents` module (`Document` model with `InteractsWithMedia`, Actions `AttachDocuments`, `RenameDocument`, `SetDocumentVisibility`, `DeleteDocument`, `RequestDocumentArchive`, a `DocumentUrl` signer, a `DocumentFileController`, the `BuildDocumentArchive` and `PruneExpiredDocumentArchives` jobs) and an `App\Domain\Finance` module (`Transaction`, `TransactionCategory`, Actions `CreateTransaction`, `UpdateTransaction`, `DeleteTransaction`, `ConvertToCzk`, a `FinanceOverview` reader). The database carries the integrity: composite foreign keys give "child category has parent's type", "two levels only", "transaction category has the transaction's type" and "project belongs to client"; CHECKs pin the owner pair, the flag-versus-owner rule and the path shape. Files are streamed through one signed, authenticated panel route; the ZIP is built by a queued job with ZipStream into a temp file and stored under a CHECK-pinned path.

**Primary recommendation:** Build `documents` as a Partner-readable, scope-protected table (constrain through the already-scoped Project, Task and TaskComment queries, not through copied rules), keep `Media` closed to Partners and load it only as system after the policy passed, stream every download through one signed panel route, publish a `config/livewire.php` that moves Livewire temp uploads to the local private disk, and put all integrity rules of the finance module into composite foreign keys and CHECK constraints.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Upload (Admin and Partner) | Frontend Server (Livewire/Filament form) | API / Backend (domain Action) | Livewire receives the temp file; one Action validates, authorizes, stores through medialibrary |
| File bytes at rest | Database / Storage (private S3 bucket) | — | Never publicly addressable (D-10); object key is server-generated |
| Download and inline preview | API / Backend (panel route + controller) | Database / Storage | Policy check, then streamed through the app with security headers; no bucket URL reaches the browser |
| Partner visibility rule | Database / Storage (global scope on `documents`) | API / Backend (policy) | The scope is the rule, the policy is defence in depth (D-03) |
| ZIP build | API / Backend (queued `KokpitJob`) | Database / Storage (archive object, 7 days) | Long-running, streams objects from S3 into one archive |
| Storage totals, listing filters | Database / Storage (queries over `documents`) | Frontend Server (Filament table, stats) | Denormalized `client_id`, `size_bytes`, `mime_type` make totals plain aggregates |
| CZK conversion snapshot | API / Backend (Action using `ExchangeRateConverter`) | Database / Storage (stored rate columns) | Done once at write time, stored, never recomputed |
| Category and transaction integrity | Database / Storage (composite FK, CHECK) | API / Backend (Actions) | "Database is the guarantee" convention of this repo |
| Finance overview | API / Backend (read model over stored CZK) | Frontend Server (Filament page, widgets) | Pure aggregation, no Partner exposure |
| Browser-side concerns | Browser / Client | — | Only the upload widget and chart rendering; nothing authoritative |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| spatie/laravel-medialibrary | 11.23.9 | Stores each file as a `Media` row on the private disk and deletes the object with the row [VERIFIED: composer.lock, `composer why`] | Owner decision (CONTEXT specifics); already installed and registered with the UUID `Media` model |
| filament/filament | v5.10.2 | Resources, relation managers, `FileUpload`, `StatsOverviewWidget`, `ChartWidget`, `HasFiltersForm` [VERIFIED: composer.lock] | Panel framework of the project |
| livewire/livewire | v4.4.7 | Temporary uploads, `SupportFileDownloads` [VERIFIED: composer.lock] | Under Filament |
| maennchen/zipstream-php | 3.2.2 (MIT) | Streams objects into a ZIP file without loading them [VERIFIED: composer.lock:4479, `composer why` says "spatie/laravel-medialibrary 11.23.9 requires maennchen/zipstream-php (^3.1)"] | Already installed; promote to a direct `require` so the dependency is explicit |
| spatie/eloquent-sortable | ^5.0 | Manual category order [VERIFIED: composer.json] | Decided in D-15; already used by tasks |
| spatie/laravel-activitylog | ^5.1 | Allowlisted activity rows through `LogsAllowlistedActivity` [VERIFIED: composer.json] | Existing convention (FND-08) |
| brick/money via `App\Domain\Shared\Money\Money` | ~0.15.2 | Money value object, single rounding point [VERIFIED: composer.json] | FND-04 |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `App\Domain\ExchangeRates\ExchangeRateConverter` | in repo | `rateFor()` returns rate, unit amount and rate day; `toCzk()` converts [VERIFIED: app/Domain/ExchangeRates/ExchangeRateConverter.php] | Transaction CZK amount |
| `App\Domain\Operations\Jobs\KokpitJob` + `#[Idempotent]` | in repo | Job base with tries 3, backoff 10/60/300, timeout 60 and the system-context middleware [VERIFIED: app/Domain/Operations/Jobs/KokpitJob.php:49-51] | ZIP build, archive prune |
| `App\Domain\Shared\Database\CzechCollation` | in repo | Czech-ordered text sort | Name and category sorting |
| `App\Domain\TimeTracking\Support\PragueDay` | in repo | Prague day to UTC instants | Date-range filter of the listing |
| `Immutability` guard helpers | in repo | `kokpit_guard_frozen_row()` trigger (KP001) | Optional DB guard of the system category |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| ZipStream streaming | PHP `ZipArchive` (ext-zip present) | `ZipArchive::addFile` needs every source on local disk until `close()`, so a 200 MB archive needs 200 MB of temp files at once; ZipStream streams each S3 object |
| Streaming download through the app | Redirect to an S3 presigned URL | Presigned URLs use the container endpoint `rustfs:9000` in DDEV, which the browser cannot resolve (CONTRIBUTING.md:79 says so); response headers like `nosniff` cannot be set on an S3 response either |
| Livewire row-action download (Phase 8 pattern) | Real GET route | Fine for small CSV files; unusable for a ZIP or an inline preview (see Pitfall 2) |
| Spatie `SpatieMediaLibraryFileUpload` Filament plugin | Core `FileUpload` + own Action | The plugin writes media straight onto a model; D-01 puts the `documents` entity and the policy between the form and the media, so the form must not store |

**Installation:**
```bash
# No new package. Make the transitive dependency explicit:
ddev exec composer require maennchen/zipstream-php:^3.2
```

**Version verification:** `composer.lock` lists `maennchen/zipstream-php` 3.2.2 (MIT); Packagist reports 3.2.2 released 2026-04-11, about 13.5M downloads a month, repository `github.com/maennchen/ZipStream-PHP` [VERIFIED: packagist.org/packages/maennchen/zipstream-php.json and repo.packagist.org/p2, fetched this session].

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| maennchen/zipstream-php | Packagist | since 2013-11 | about 13.5M a month, 354M total | github.com/maennchen/ZipStream-PHP | OK (manual check) | Approved; already in `composer.lock` as a requirement of spatie/laravel-medialibrary |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none
**Seam note:** `gsd-tools package-legitimacy check` accepts only `npm`, `pypi` and `crates` (its usage error says so), so the verdict above is a manual Packagist check, not a seam run. No other package is recommended, so no `checkpoint:human-verify` install task is needed.

## Architecture Patterns

### System Architecture Diagram

```
UPLOAD
 Admin modal / relation manager / comment form  ----\
 Partner create-task form / own-comment form   -----+--> Livewire temp upload (local private disk, NOT s3)
                                                         |
                                                         v  state = TemporaryUploadedFile (strings refused)
                                              AttachDocuments Action (DB transaction)
                                                |  1 authorize (DocumentPolicy::create on owner)
                                                |  2 resolve client_id (+project_id) from owner, validate size/ext
                                                |  3 insert documents row (uuid v7)
                                                |  4 addMedia()->usingFileName(<uuid>.<ext>)->toMediaCollection  --> private S3 bucket
                                                |  5 copy size/mime onto row; activity log; Partner upload -> Admin bell
                                                v
                                       documents (Partner-readable, PartnerScope)  <--- media (DeniesPartners)

DOWNLOAD / PREVIEW
 Filament row action -> policy check -> URL::temporarySignedRoute(user id bound, ttl seconds)
        -> browser GET /admin/documents/{document}/file?mode=inline|download
        -> panel auth + MFA middleware -> signed -> DocumentFileController
        -> Document via scoped query (Partner: 404) -> policy again -> Media as system
        -> Storage readStream -> StreamedResponse (nosniff, attachment unless raster image/PDF, no-store)

ZIP
 Listing bulk action (selection or filter result) -> RequestDocumentArchive (count + size limits, ids validated)
        -> document_archives row (pending) -> BuildDocumentArchive (KokpitJob, system)
        -> ZipStream -> temp file -> private disk document-archives/<uuid>.zip -> row ready + bell
        -> archive list row action -> same signed route family (streamed) ; daily prune deletes object then row (7 days)

DELETE
 Row / bulk action + confirmation -> DeleteDocument (runs as system AFTER the policy check, in a DB transaction)
        -> Document::delete -> package deleting-hook deletes Media -> MediaObserver removes S3 objects -> activity row (name, size, owner type)
 Owner hard delete (transaction) -> DeleteTransaction deletes each Document first (archive of client/project/task is NOT a delete)

FINANCE
 Transaction form (type, date, category, currency, amount, optional client/project) -> CreateTransaction
        -> ExchangeRateConverter::rateFor(currency, date) --found--> Money::convert -> czk_minor + rate snapshot (source = cnb)
                                                         --ExchangeRateMissing--> form asks for CZK, source = manual
        -> transactions (composite FKs enforce type match and project-belongs-to-client)
 FinanceOverviewPage (HasFiltersForm: month|year) -> Stats widget + Chart widget (pageFilters, revalidated) + category/client tables
        -> SUM(czk_minor) over date range, child sums rolled into parent via COALESCE(parent_id, id)
```

### Recommended Project Structure
```
app/Domain/Documents/
├── Models/            Document.php, DocumentArchive.php
├── Enums/             DocumentOwnerType (aliases), DocumentTypeGroup, DocumentArchiveStatus
├── Actions/           AttachDocuments, RenameDocument, SetDocumentVisibility, DeleteDocument,
│                      RequestDocumentArchive, ResolveDocumentOwner
├── Policies/          DocumentPolicy.php
├── Http/              DocumentFileController.php   (or app/Http/Controllers)
├── Support/           DocumentUrl.php (signer), DocumentDisposition.php (inline allowlist, safe names)
├── Jobs/              BuildDocumentArchive.php, PruneExpiredDocumentArchives.php
├── Notifications/     DocumentArchiveReadyNotification, PartnerUploadedFilesNotification
└── Queries/           StorageTotals.php
app/Domain/Finance/
├── Models/            Transaction.php, TransactionCategory.php
├── Enums/             TransactionType.php, CzkSource.php
├── Actions/           CreateTransaction, UpdateTransaction, DeleteTransaction, ConvertToCzk, ArchiveCategory
└── Queries/           FinanceOverview.php
app/Filament/Resources/DocumentResource(.php) DocumentArchiveResource TransactionResource TransactionCategoryResource
app/Filament/RelationManagers/ClientDocuments..., ProjectDocuments..., TaskDocuments..., TransactionDocuments...
        (one trait DocumentsRelationManagerBehaviour, four final classes: the #[AccessRule] attribute is not inherited)
app/Filament/Partner/Resources/*/RelationManagers/ PartnerProjectDocuments..., PartnerTaskDocuments...
app/Filament/Pages/FinanceOverviewPage.php   app/Filament/Widgets|Pages widgets for stats/chart
config/livewire.php (new)   config/kokpit.php (documents + finance keys)
```

### Pattern 1: `documents` table (denormalized, constraint-heavy)
**What:** One row per file; the Partner reads this table, so its column list is pinned by a test and holds nothing Admin-only.
**When to use:** all file storage in Phases 9-10.
**Example:**
```php
// Source: conventions of database/migrations/2026_10_10_000100_create_tasks_table.php and 2026_10_15_000200_create_report_exports_table.php
Schema::create('documents', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->string('name', 255);                       // display name, renameable
    $table->text('description')->nullable();           // Partner-visible when the file is shared (decide wording, see A1)
    $table->boolean('visible_to_client')->default(false);
    $table->string('owner_type', 32)->nullable();      // morph alias: client|project|task|task_comment|transaction (invoice in Phase 10)
    $table->uuid('owner_id')->nullable();
    $table->foreignUuid('client_id')->nullable()->constrained('clients')->restrictOnDelete();
    $table->uuid('project_id')->nullable();            // recommended denormalization, composite FK below
    $table->foreignUuid('uploaded_by_id')->constrained('users')->restrictOnDelete();
    $table->string('extension', 16)->nullable();       // server-derived, [a-z0-9]{1,10}
    $table->string('mime_type', 127);                  // detected from content by the package (finfo), copied once
    $table->unsignedBigInteger('size_bytes');          // copy of media.size, files are never replaced
    $table->timestampsTz();
    $table->index(['owner_type', 'owner_id'], 'documents_owner_index');
    $table->index(['client_id', 'created_at'], 'documents_client_created_index');
});
DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_owner_pair_check CHECK ((owner_type IS NULL) = (owner_id IS NULL))');
// Never shared unless it hangs on a project, a task or a comment (D-03). Phase 10's invoice owner needs no change.
DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_visible_owner_check CHECK (NOT visible_to_client OR owner_type IN ('project', 'task', 'task_comment'))");
DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_project_needs_client_check CHECK (project_id IS NULL OR client_id IS NOT NULL)');
DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_id_client_unique UNIQUE (id, client_id)');
DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_project_client_fk FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id) ON DELETE RESTRICT');
DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_name_check CHECK (length(btrim(name)) > 0)");
// Exactly one media row per document: the package row points back through (model_type, model_id).
DB::statement("CREATE UNIQUE INDEX media_document_unique ON media (model_id) WHERE model_type = 'document'");
```
Do not put `owner_type` values into a CHECK list: Phase 10 adds `invoice`, and the existing rule R9 already verifies every stored morph type against the morph map. Add `'document' => Document::class` and `'document_archive' => DocumentArchive::class` (and the finance aliases) to `MorphMap::MAP` [VERIFIED: app/Domain/Shared/Database/MorphMap.php:47, one alias per model; `'task_comment'` already exists at line 65].

### Pattern 2: Partner scope that delegates to already-scoped queries
**What:** `Document::constrainForPartner` reuses the Project, Task and TaskComment scopes instead of copying their rules (the exact pattern of `TaskComment::constrainForPartner`, which delegates to `Task::query()`).
**Example:**
```php
// Source: pattern of app/Domain/Tasks/Models/TaskComment.php (constrainForPartner)
public function constrainForPartner(Builder $query, string $clientId): void
{
    $query->where($this->qualifyColumn('visible_to_client'), true)
        ->where($this->qualifyColumn('client_id'), $clientId)            // belt and braces, never the only rule
        ->where(function (Builder $q): void {
            $q->where(fn (Builder $p) => $p->where('owner_type', 'project')
                  ->whereIn('owner_id', Project::query()->select('projects.id')))
              ->orWhere(fn (Builder $t) => $t->where('owner_type', 'task')
                  ->whereIn('owner_id', Task::query()->select('tasks.id')))
              // TaskComment's scope already drops is_internal rows and invisible tasks.
              ->orWhere(fn (Builder $c) => $c->where('owner_type', 'task_comment')
                  ->whereIn('owner_id', TaskComment::query()->select('task_comments.id')));
        });
}
```
Archived projects/tasks drop out through the soft-delete scope inside those subqueries (Task uses `SoftDeletes`), which matches "a Partner can access". `Document` uses `IsolatesPartners` (not `DeniesPartners`) and `implements PartnerIsolated`; `ModelDeclarationTest`'s pinned model list, `CanaryRegistry::fixtures()` and the Partner-safe column list (`tests/Isolation/PartnerSafeColumnsTest.php`) each need a line.

### Pattern 3: Upload through one Action; the form never stores
**What:** `FileUpload::make('files')->multiple()->storeFiles(false)->maxSize(<KB>)` keeps the state as `TemporaryUploadedFile`; the Action refuses everything that is not one.
**Example:**
```php
// Source: vendor/filament/forms/src/Components/BaseFileUpload.php:474 (storeFiles), :1002 (no store when false), :1006-1013 (string state is a path and client-controlled)
FileUpload::make('files')
    ->label(__('kokpit.documents.fields.files'))
    ->multiple()->storeFiles(false)
    ->maxFiles((int) config('kokpit.documents.max_files_per_upload'))
    ->maxSize((int) config('kokpit.documents.max_size_kb'))   // Filament and Livewire count KILOBYTES, medialibrary counts BYTES
    ->required();

// In the Action: abort the whole upload on anything else.
foreach ($files as $file) {
    if (! $file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
        throw ValidationException::withMessages(['files' => __('kokpit.documents.errors.invalid_upload')]);
    }
}
$media = $document->addMedia($file)
    ->usingName($safeName)
    ->usingFileName($document->getKey().($ext ? ".{$ext}" : ''))   // server-generated object name, no user text
    ->toMediaCollection('file');                                   // disk = config('media-library.disk_name')
```

### Pattern 4: One signed, authenticated streaming route
**What:** Register the route with `Panel::authenticatedRoutes()` so Filament's `Authenticate` (which calls `canAccessPanel`, hence the Partner lockout and archived-client rules) and the MFA middleware run. Sign it with the requesting user bound in, and stream.
**Example:**
```php
// Source: vendor/filament/filament/src/Panel/Concerns/HasRoutes.php:83 (authenticatedRoutes); app/Providers/Filament/AdminPanelProvider.php:67 comment names routes "filament.admin.*"
->authenticatedRoutes(fn () => Route::get('/documents/{document}/file', DocumentFileController::class)
    ->middleware(['signed', 'throttle:documents'])->name('documents.file'))

// DocumentUrl: user id inside the signature, TTL from config/kokpit.php
URL::temporarySignedRoute('filament.admin.documents.file', now()->addSeconds($ttl), [
    'document' => $document->getKey(), 'mode' => $inline ? 'inline' : 'download', 'u' => $user->getKey(),
]);

// Controller (after: signed ok, u === auth id, Document resolved through the scoped query -> 404 for a hidden file, policy 'view')
$media = app(PartnerContext::class)->runAsSystem(fn () => Media::query()
    ->where('model_type', 'document')->where('model_id', $document->getKey())->firstOrFail());
return Storage::disk($media->disk)->response($media->getPathRelativeToRoot(), $downloadName, [
    'Content-Type' => $inline ? $document->mime_type : 'application/octet-stream',
    'Content-Length' => (string) $document->size_bytes,
    'X-Content-Type-Options' => 'nosniff',
    'Cache-Control' => 'private, no-store',
], $inline ? 'inline' : 'attachment');   // FilesystemAdapter::response, vendor/laravel/framework/.../FilesystemAdapter.php:346-372
```
Inline is allowed only for a fixed allowlist (png, jpeg, gif, webp, pdf); SVG and HTML are never inline (an SVG is script-capable and would run on the app origin). Passing an explicit `Content-Type` avoids the extra S3 HEAD that `response()` otherwise does (`Content-Type ??= $this->mimeType($path)`).

### Pattern 5: ZIP build with ZipStream into a temp file
```php
// Source: vendor/maennchen/zipstream-php/src/ZipStream.php:209-226 (constructor), :387 (addFileFromStream), :705 (finish)
$tmp = tempnam(sys_get_temp_dir(), 'kokpit-zip-');
$out = fopen($tmp, 'wb');
$zip = new ZipStream(outputStream: $out, sendHttpHeaders: false);
foreach ($documents as $doc) {                                   // chunked by id, as system
    $stream = $disk->readStream($media->getPathRelativeToRoot());
    $zip->addFileFromStream(fileName: $uniqueSafeEntryName, stream: $stream);   // dedupe "name (2).pdf", strip separators and control chars
    fclose($stream);
}
$zip->finish(); fclose($out);
$disk->writeStream($archive->storagePath(), fopen($tmp, 'rb'));   // path pinned by CHECK: document-archives/<uuid>.zip
```
Mark the archive ready and notify in one transaction after the object is stored (copy `BuildReportExport::handle`: lock row, `Pending` check, `markReady`, `notifyNow`). Use `#[Timeout]` below 300 s (Horizon supervisor) and size the configured ZIP limits so the build fits; `KokpitJob`'s default is 60 s (KokpitJob.php:51).

### Pattern 6: Category and transaction integrity in the database
```php
// Source: the composite-FK technique of tasks_parent_fk (database/migrations/2026_10_10_000100_create_tasks_table.php:90)
// transaction_categories: id, parent_id, type, depth (0|1), parent_depth stored 'CASE WHEN parent_id IS NULL THEN NULL ELSE 0::smallint END',
//   name, is_system, system_key (unique, nullable), archived_at, position, timestamps
// CHECK depth = CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END ; CHECK type IN ('income','expense')
// UNIQUE (id, type, depth)
// FOREIGN KEY (parent_id, type, parent_depth) REFERENCES transaction_categories (id, type, depth) ON DELETE RESTRICT
//     -> a child has its parent's type, the parent is a root, no third level, whatever code writes the row
// transactions: ... category_id, type, client_id, project_id ...
// FOREIGN KEY (category_id, type) REFERENCES transaction_categories (id, type)        -> category type must match
// FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id)             -> needs projects UNIQUE (id, client_id)
// CHECK (project_id IS NULL OR client_id IS NOT NULL)   -- MATCH SIMPLE skips a composite FK when any column is NULL
```
A system category can reuse the Phase 2 guard: `Immutability::guardTriggerSql('transaction_categories', 'is_system', 'false', ['name', 'position', 'updated_at'])` freezes every other column and refuses delete (KP001) for `is_system = true` rows [VERIFIED by reading kokpit_guard_frozen_row(), `to_jsonb(OLD) ->> 'is_system'` yields 'true'/'false'; the helper signature is app/Domain/Shared/Database/Immutability.php:40, plan-time check needed].

### Pattern 7: CZK snapshot
```php
// Source: app/Domain/ExchangeRates/ExchangeRateConverter.php (rateFor returns rate, unit_amount, rate_date; throws ExchangeRateMissing)
if ($amount->currency === 'CZK') { $czk = $amount; $source = CzkSource::Identity; }
else try {
    $r = $converter->rateFor($amount->currency, $date);            // one lookup, the very rate used
    $czk = $amount->convert($r['rate'], 'CZK', $r['unit_amount']); // single rounding point
    $snapshot = [$r['rate'], $r['unit_amount'], $r['rate_date']]; $source = CzkSource::Cnb;
} catch (ExchangeRateMissing) { /* form requires czk_minor; source = Manual; no block on save */ }
```
Columns: `czk_minor` bigint, `czk_source` varchar CHECK in (`identity`, `cnb`, `manual`), `exchange_rate numeric(20,10)`, `exchange_unit_amount int`, `exchange_rate_date date`, with CHECKs: `cnb` requires all three snapshot columns, the others require them null, `identity` requires `amount_currency = 'CZK'` and `czk_minor = amount_minor`. Use the repo's money pair convention `amount_minor` plus `amount_currency` char(3) with `^[A-Z]{3}$` and `MoneyCast` [VERIFIED: app/Domain/Shared/Money/MoneyCast.php; 02 decision "Money column convention"].

### Pattern 8: Finance overview as a page with filters and widgets
`FinanceOverviewPage` uses `Filament\Pages\Dashboard\Concerns\HasFiltersForm` (the page then owns a `$filters` property and `Page::getWidgetData()` passes `pageFilters` to header widgets, vendor/filament/filament/src/Pages/Page.php:599). Widgets use `InteractsWithPageFilters`, whose `$pageFilters` is a `#[Reactive]` public property, hence client-controlled: revalidate period type, year and month in one `FinanceFilters::fromInput()` that falls back to the current Prague month (same approach as `ReportFilters`). Every widget and the page carry `#[AccessRule(Audience::AdminOnly, ...)]` and the matching `Enforces*` trait. Sums come from `Transaction::query()->selectRaw(...)->groupBy(...)` (Eloquent; `DB::table(` is banned outside an empty allowlist, tests/Arch/QueryEscapeHatchTest.php), the period is a half-open `date` range, and the chart is the one place where minor units become a number for Chart.js (round to whole CZK or two decimals; label it as display only).

### Anti-Patterns to Avoid
- **Opening `Media` to Partners "because documents are visible":** keep `Media` closed and load it as system after the policy (Pitfall 3). The document row carries everything a Partner screen needs.
- **Letting `FileUpload` store files** (`storeFiles(true)`, the default): the file would land on a Filament-chosen path, outside `documents`, and a tampered string state would be accepted as an existing path.
- **Redirecting the browser to `Storage::temporaryUrl()`** in this codebase: unreachable endpoint in DDEV, no way to add `nosniff`.
- **Switching `RichEditor::fileAttachments(true)` on** to deliver comment attachments: inline editor attachments bypass `documents`, the policy and the sanitiser (`RichText` strips them). Keep `fileAttachments(false)` and add a separate `FileUpload` field.
- **Deleting an owner row with a raw query or by DB cascade:** the S3 object survives (no model event). Only Eloquent `delete()` on the `Document` removes the media.
- **Hard-coding the owner type list in a CHECK:** Phase 10 adds `invoice`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Object naming, S3 write and delete | Own path logic | `addMedia()->usingFileName()->toMediaCollection()`; `Document` delete removes media | The package observer removes the objects; `InteractsWithMedia::bootInteractsWithMedia` deletes all media on `deleting` (InteractsWithMedia.php:51-65) |
| ZIP writing | `ZipArchive` with temp copies, or manual streams | ZipStream (installed) | Streams each object, zip64 and zero-header handling built in |
| Partner row filter | Re-implementing project/task/internal rules | Delegate to the scoped `Project`, `Task`, `TaskComment` queries | One rule per concept; a later change of project visibility cannot leave the documents scope behind |
| Type-match and parent rules of categories | Application validation only | Composite FKs (Pattern 6) | Any writer is covered; same technique as the task parent key |
| CZK conversion and rounding | `round($amount * $rate)` | `ExchangeRateConverter::rateFor` + `Money::convert` | One rounding point, per-unit rates (100/1000) handled |
| Number-to-text of money | `number_format` | `Money::toMajor()` / `Money::format('cs')` | Matches every other screen and export |
| Czech ordering | `orderBy('name')` | `CzechCollation::orderBy()` | Documented convention (CONTRIBUTING:73) |
| Bell notification markup | New escaping scheme | Copy `ReportExportReadyNotification` (scalars in, `e()` once, database channel) | Escaping and Partner-safety already reviewed |
| Queue job contract | Plain job | `KokpitJob` + `#[Idempotent(how: ...)]` and register in the job-contract list | `JobContractTest` fails otherwise |

**Key insight:** every piece of this phase has an existing in-repo twin (report export for the ZIP and its prune, `TaskComment` for the scoped delegation, tasks for composite FKs, Phase 8 for rates). Copying the twin keeps the arch and isolation tests green; inventing a new shape means rewriting those tests.

## Runtime State Inventory

Not a rename, refactor or migration phase. Omitted. (One note: Phase 10 will add the `invoice` owner and an `invoice_id` on transactions; nothing in this phase needs data migration. The existing media table has no rows today [ASSUMED: no code path stored media before this phase; grep found no `addMedia` use in `app/`].)

## Common Pitfalls

### Pitfall 1: Livewire uploads go browser-to-S3 and fail in DDEV (and need CORS in production)
**What goes wrong:** The first `FileUpload` ever added in this repo (none exist: grep of `app/`, `config/`, `resources/` for `FileUpload` and `livewire-tmp` returned nothing) tries a presigned PUT to `rustfs:9000`. The browser cannot resolve it; uploads hang or fail.
**Why it happens:** There is no `config/livewire.php`; Livewire then uses `config('filesystems.default')`, which is `s3`, and treats the disk as S3: `FileUploadConfiguration::disk()` returns `config('livewire.temporary_file_upload.disk') ?: config('filesystems.default')` and `isUsingS3()` is true for an `s3` driver [VERIFIED: vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadConfiguration.php:30-49]. CONTRIBUTING.md:79 already warns "On `s3` the browser uploads directly through a presigned URL, so the reachable-endpoint note for downloads applies to uploads as well" [VERIFIED: CONTRIBUTING.md:79]. `.ddev/docker-compose.rustfs.yaml` only `expose`s ports 9000/9001, it does not publish them [VERIFIED].
**How to avoid:** Publish `config/livewire.php` with `temporary_file_upload.disk => 'local'` (private, `storage/app/private`), `rules => ['required', 'file', 'max:<KB from kokpit config>']`. The Action then moves the file to S3 server-side through medialibrary. Keep tests green: under tests Livewire already swaps to `tmp-for-tests`.
**Warning signs:** Upload progress stuck at 0 in DDEV; a 403/CORS error from the bucket host in production.
**Caveat:** local temp files are per container. If production scales the web container beyond one, the second Livewire request (form submit) may land on a container that does not hold the temp file [ASSUMED, see A4 and Open Question 1].

### Pitfall 2: Livewire downloads buffer and base64 the entire file
**What goes wrong:** Returning a `StreamedResponse` from a Filament/Livewire action (the Phase 8 export pattern) works for a small CSV, but a 200 MB ZIP is read into memory and base64-encoded into the JSON response. Inline preview is impossible because it always becomes a download.
**Why it happens:** `SupportFileDownloads::call()` runs `$response->sendContent()` into an output buffer and stores `base64_encode($binary)` as the `download` effect [VERIFIED: vendor/livewire/livewire/src/Features/SupportFileDownloads/SupportFileDownloads.php, `captureOutput` and `base64_encode`].
**How to avoid:** Row actions only authorize and redirect (or `$livewire->js(window.open(...))` for inline) to the signed GET route of Pattern 4. Keep the Phase 8 `ReportExportResource` download as it is (not in scope).

### Pitfall 3: `Media` is closed to Partners, so a Partner-run delete leaves objects behind
**What goes wrong:** A Partner deletes their own file: `Document::delete()` fires the package hook `deleteAllMedia()`, which reads `$this->media()->cursor()` through the Partner-scoped `Media` model, finds zero rows, deletes nothing, and the S3 object and the media row remain while the document row is gone.
**Why it happens:** `Media` uses `DeniesPartners` (app/Domain/Shared/Models/Media.php:21-23); `InteractsWithMedia::deleteAllMedia()` is `$this->media()->cursor()->each(fn ($media) => $media->delete())` [VERIFIED: vendor/spatie/laravel-medialibrary/src/InteractsWithMedia.php:688-693].
**How to avoid:** `DeleteDocument` checks the policy as the actor, then runs the deletion inside `PartnerContext::runAsSystem()` and `DB::transaction()`; assert in a test that the object is gone from the fake disk after a Partner delete. The same applies to `AttachDocuments` (the media write for a Partner upload runs as system after the policy check) and to reads of the media row in the download controller.

### Pitfall 4: The S3 object and the database cannot commit together
**What goes wrong:** An exception after `toMediaCollection()` rolls the transaction back but the object stays; or a commit fails after the object is deleted.
**How to avoid:** In `AttachDocuments`, catch `Throwable` around the transaction and best-effort delete the object written in this call before rethrowing (the media key is known). For delete, run `Document::delete()` inside the transaction so a thrown S3 error (the disk has `'throw' => true`, config/filesystems.php:66) rolls the row back instead of leaving an orphan row; accept the rare commit-failure orphan and consider a later reconcile command (Open Question 4). The upload of N files in one submit is N documents; decide whether one failure rolls back all (recommended: yes, one transaction per submit).

### Pitfall 5: `client_id` / `project_id` drift
**What goes wrong:** The denormalized columns disagree with the owner after the owner changes. The owners that can change are few: a project's client is immutable (UpdateProject refuses a different `client_id`, app/Domain/Projects/Actions/UpdateProject.php:70-73), a task's project and a comment's task are fixed, so only a **transaction's client** can change.
**How to avoid:** `UpdateTransaction` updates `documents.client_id` and `project_id` of its files in the same transaction (a model-event bulk update is fine here because the documents are logged only for name/visibility). Add a Feature test with a raw SQL "no document disagrees with its resolved owner" query, run after create/update flows. Keep the owner immutable after upload (A11); a wrong owner means delete and re-upload.
**Warning signs:** Storage totals per client not equal to the sum of the client's rows; Partner scope still passes (it also checks the owner), but Admin filters differ from the owner link.

### Pitfall 6: Archive is not delete
**What goes wrong:** "Deleting an owning record also removes its files" is implemented as a cascade on `Client`/`Project`/`Task` archive, destroying files of a reversible action.
**Why it happens:** Clients, projects and tasks are only soft-deleted (archived); comments are append-only and never deleted; users are deactivated. Only a **transaction** can really be deleted in this phase.
**How to avoid:** Hook deletion into `DeleteTransaction` only (each document through `DeleteDocument`, one confirmation per D-14). Add an arch/feature test that fails if a model with documents gains a hard-delete path without a documents cleanup (list the owner models). Archived owners keep their files and keep them downloadable by Admin.

### Pitfall 7: Polymorphic owner rendering
**What goes wrong:** The central listing shows "—" for the owner of archived projects/tasks/clients, or runs one query per row.
**How to avoid:** Eager load `owner` with `MorphTo::morphWith([...])` including soft-deleted rows (`withTrashed`) and only the label columns; resolve a comment's label through its task. Order text columns with `CzechCollation`.

### Pitfall 8: Unit confusion in size limits
**What goes wrong:** Filament `maxSize` and Livewire `max:` are kilobytes, medialibrary `max_file_size` is bytes (`1024 * 1024 * 10`, config/media-library.php:56), PHP and nginx have their own limits.
**How to avoid:** One source in `config/kokpit.php` (`documents.max_size_kb => 10240`); the Action validates against it before `addMedia`, the form and the Livewire rule derive from it; leave the medialibrary value as the hard 10 MB ceiling and pin both in a test. Zerops defaults to 1024 MB for `upload_max_filesize`/`post_max_size` and states the balancer limits, so 10 MB passes [CITED: docs.zerops.io/guides/php-tuning]; DDEV PHP allows 100M and nginx `client_max_body_size 0` [VERIFIED: `ddev exec php -i`, /etc/nginx/nginx.conf:74]. The repository's `site.conf.tmpl` sets no `client_max_body_size`; the Zerops page attributes that limit to the L7 balancer, so no change is expected, but confirm in the deploy rehearsal (A7).

### Pitfall 9: Filter state and notifications leaking names
**What goes wrong:** A Partner learns that a hidden file exists through a count, a select option, an activity row or a notification text.
**How to avoid:** No global search on documents (`globalSearchResourceOptIn` is already on; do not opt in). `Activity` is `DeniesPartners`. The Admin bell for a Partner upload carries the uploader, task reference and file count, built from scalars; Partner never receives a document notification. Extend `NotificationLeakTest` and the canary registry (below). The Partner create-task form and own-comment form show only the user's own just-uploaded files.

### Pitfall 10: Seeded categories and the test database
**What goes wrong:** Seeding categories in a migration puts rows into every test database; tests that count categories fail. Seeding only in `DatabaseSeeder` (which production never runs: roles are created by `kokpit:install`) leaves production without the system category that Phase 10 needs.
**How to avoid:** Seed through an idempotent data migration keyed by `system_key` for the system category and a fixed starter set, and write tests relative to the baseline; or create the system category lazily by `system_key` in an Action and seed only the starter set in the install command. Recommended: data migration (A13). Seed content is generic Czech names; no real business data (hygiene rule).

### Pitfall 11: Activity log and deletion
**What goes wrong:** The deletion row is missing or leaks content.
**How to avoid:** `LogsAllowlistedActivity` logs `old` attributes on the `deleted` event [VERIFIED: vendor/spatie/laravel-activitylog/src/Models/Concerns/LogsActivity.php:233-262, `isDeletedEvent` moves attributes to `old`]. Allowlist `name`, `visible_to_client`, `owner_type`, `size_bytes` (not `description`). Add `Document::class` and `Transaction::class` to the pinned list in `tests/Arch/ActivityAllowlistTest.php` ("lists the application models that log activity explicitly"), add `kokpit.activity.subjects.document` and `...transaction` labels (`ActivityPresenter::subjectLabel` falls back to the raw alias otherwise).

### Pitfall 12: ZIP entry names and sizes
**What goes wrong:** Duplicate names overwrite each other in the archive; names with `/`, `..`, control characters or a leading dot create odd paths; a selection of "all filtered rows" is huge.
**How to avoid:** Build entry names from the document name plus extension, strip separators and control characters, dedupe with `name (2).ext`, and cap the length. Enforce `max_files` and `max_bytes` twice (at request time on the selection, and again in the job from the database, because a queue payload skips the request). Use `chunkSelectedRecords()` or pass ids only (Filament bulk actions have `chunkSelectedRecords`, vendor/filament/actions/src/Concerns/CanFetchSelectedRecords.php:25, and `Table::maxSelectableRecords`, tables/src/Table/Concerns/HasBulkActions.php:186).

### Pitfall 13: Transaction edits with a manual CZK amount
**What goes wrong:** The Admin changes currency or date of a transaction whose CZK amount is manual; recomputing silently overwrites the bank figure, or keeping it silently disagrees with the new inputs.
**How to avoid:** `czk_source = manual` is sticky until the Admin explicitly switches back to "computed" (a toggle in the form); while manual, a currency/amount/date change keeps `czk_minor` and shows a hint. When computed, any change of date, currency or amount recomputes and refreshes the snapshot. Test all four combinations.

## Code Examples

### Livewire temp-upload configuration
```php
// config/livewire.php (new). Source: vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadConfiguration.php:30-49,112-121
return [
    'temporary_file_upload' => [
        'disk' => 'local',                       // private, never the s3 default (Pitfall 1)
        'rules' => ['required', 'file', 'max:10240'],   // keep equal to kokpit.documents.max_size_kb, pinned by a test
        'middleware' => 'throttle:60,1',
        'cleanup' => true,
    ],
];
```
Check at plan time that publishing the file does not drop other Livewire defaults the app relies on (publish with `php artisan livewire:publish --config` and edit only this key).

### Policy skeleton (Partner grants are explicit, everything else denied)
```php
// Source: pattern of app/Domain/Tasks/Policies/TaskCommentPolicy.php (view through scoped queries)
final class DocumentPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool { return true; }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof Document && $record->visible_to_client
            && $this->ownerIsVisibleToPartner($record);      // Project/Task/TaskComment::query()->whereKey($owner_id)->exists() (scoped)
    }

    public function delete(User $user, Model $record): bool
    {
        // own upload (uploaded_by_id === $user->id) while the owning task is open (A2); Admin passes through before()
    }
}
```
Register with `Gate::policy(Document::class, DocumentPolicy::class)` in `AccessServiceProvider` (the models there are registered by hand, app/Providers/AccessServiceProvider.php:62).

### Owner-aware resolver (client and project of a file)
```php
// client -> client id; project -> its client and itself; task -> project's client and project;
// task_comment -> the task's project; transaction -> the transaction's client and project (may be null);
// unattached -> null. Locks nothing beyond a FOR SHARE read of the owner, like CreateTask reads its project.
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Filament `FileUpload` stores to a disk and a path column | `storeFiles(false)` keeps a `TemporaryUploadedFile` for the domain to handle (`storeFile()` is deprecated in favour of `storeFiles()`, BaseFileUpload.php:489) | Filament 4/5 | Use `storeFiles(false)` |
| ZipStream 2.x `ZipStream\Option\Archive` | ZipStream 3 named constructor arguments (`outputStream:`, `sendHttpHeaders:`) | 3.0 | Use the 3.x API shown above |
| Livewire 3 `WithFileUploads` per component | Livewire 4 with Filament schema components; same `TemporaryUploadedFile` | Livewire 4 | Imports under `Livewire\Features\SupportFileUploads` |

**Deprecated/outdated:** `BaseFileUpload::storeFile()` (use `storeFiles()`); inline RichEditor file attachments for this project (kept off by decision).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The default of `visible_to_client` for a file on a **non-internal comment** uploaded by the Admin is true (D-03 names only project and task files); on an internal comment it is forced false; `description` is shown to a Partner when the file is shared | Pattern 1, Pitfall 9 | Admin-only notes leak to Partner, or comment files are unexpectedly hidden |
| A2 | "While the task is open" for Partner deletion means: task not archived and `status` is not `done` | DocumentPolicy | Partner can delete too late or too early; owner should confirm the wording |
| A3 | Partner upload surfaces are exactly two: create-task form and own comment form (D-05 reading "when creating a task and in their own comments"); no later upload onto an existing task | Policy, UI | If the owner wants uploads on an existing own task, an extra Partner "add file" action and policy branch are needed |
| A4 | Production runs one web container (or sticky routing) so the Livewire local temp disk works; zerops.yml shows a single `kokpit` setup with `allContainers` crontab but no `minContainers` | Pitfall 1 | Intermittent "upload not found" on submit when scaled; fallback is S3 temp upload with bucket CORS and a public endpoint |
| A5 | Inline allowlist is png, jpeg, gif, webp and pdf; SVG, HTML and everything else download as attachment with `nosniff` and `no-store`; CSP header optional | Pattern 4 | A too wide list allows script execution from uploaded content on the app origin |
| A6 | ZIP defaults of 200 files and 200 MB fit a build under the 240 s `#[Timeout]`; values live in `config/kokpit.php` | Pattern 5 | Timeouts on slow S3; lower the limits or raise the timeout (supervisor max is 300 s) |
| A7 | The nginx template needs no `client_max_body_size`; the Zerops page attributes the limit to the balancer | Pitfall 8 | 413 on uploads over 1 MB if the in-container nginx default applies |
| A8 | ASVS control numbering used below follows ASVS 4.0.3 (V12 files) | Security Domain | Cosmetic |
| A9 | The panel route name is `filament.admin.documents.file` (prefix inferred from the invitation route comment) | Pattern 4 | URL generation fails; verify with `php artisan route:list` at plan time |
| A10 | The client "Soubory" tab lists every file of the client by `client_id` (with an owner column), while project, task and transaction tabs list their own owner's files | RelationManagers | Client page may be expected to show only client-owned files |
| A11 | The owner of a document is immutable after upload | Pitfall 5 | An "assign unattached file to X" feature would need re-resolution and a guard |
| A12 | A denormalized `project_id` (with composite FK to `(id, client_id)`) is added next to `client_id`; D-01 names only `client_id` | Pattern 1 | Fallback: filter by project through owner subqueries (slower, more code) |
| A13 | Categories and the system category are seeded by an idempotent data migration keyed by `system_key` | Pitfall 10 | Tests need baseline-aware counts; alternative is install-command seeding plus lazy system category |
| A14 | Transaction amounts are stored positive (`amount_minor > 0`) and the type gives the direction; Phase 10 credit notes will use the opposite type, not negative amounts | Pattern 7 | A zero or negative amount might be needed for corrections |
| A15 | A transaction in the future uses the newest known CNB list (the converter's "not after the day" rule) and is shown as computed | Pattern 7 | Admin might prefer to block or flag future dates |
| A16 | The finance bar chart may convert minor units to a display float for Chart.js; no other place uses a float | Pattern 8 | A purist rule against floats would force string-based chart data (Chart.js needs numbers) |
| A17 | The label of the by-client row for `client_id IS NULL` is "Bez klienta"; D-11 calls it "Unattached" although transaction files without a client also land there | Pitfall 5 | Mislabel; owner may want two rows (unattached vs no client) |
| A18 | One Admin bell per Partner upload **submission** (grouped file count), not per file, and no preference switch | Notifications | D-05 says "every upload"; per-file notices would flood |
| A19 | `kokpit_guard_frozen_row` can freeze the system category rows with `is_system` as the state column | Pattern 6 | Falls back to a dedicated trigger or Action-only guard |

## Open Questions (RESOLVED)

1. **Production container count and Livewire temp storage**
   - **RESOLVED (owner, D-18):** multiple web containers; use the S3 temporary-upload variant with bucket CORS, not the local temp disk.
   - What we know: `zerops.yml` has one setup, `crontab.allContainers: true`; Horizon runs on each container; nothing pins a container count.
   - What's unclear: whether the web tier is ever scaled out.
   - Recommendation: ship the local temp disk (works in DDEV and one container) and add the S3-temp-upload variant (CORS, public endpoint) to the deploy rehearsal checklist; ask the owner to confirm single-container intent.

2. **Visibility of comment files and of `description` for Partners (A1)**
   - **RESOLVED (owner, D-20):** visible by default on non-internal comments, hidden on internal ones.
   - Recommendation: comment files by the Admin default true on non-internal comments, forced false on internal ones; `description` visible when shared, with helper text in the form saying so.

3. **"Task is open" wording (A2)**
   - **RESOLVED (owner, D-19):** not archived and status not `done`.
   - Recommendation: not archived and not `done`; confirm with the owner in plan check.

4. **Orphan reconciliation**
   - **RESOLVED (planner, as recommended):** no reconcile command; verification test only.
   - What we know: S3 and PostgreSQL cannot commit together; the S3 disk throws on failure.
   - Recommendation: no reconcile command in this phase; document the one-liner SQL "documents without media and media of document without a documents row" as a verification test, and revisit if the health page should show it.

5. **Client tab content and the "Unattached" row label (A10, A17)**
   - **RESOLVED (planner, as recommended).**
   - Recommendation: as in the log; both are cheap to flip.

6. **Do archived (soft-deleted) transactions exist?**
   - **RESOLVED (D-14, D-21):** transactions are hard-deleted with their files; clients, projects and tasks are archived, which hides their files without deleting objects. CONTEXT says "freely editable and deletable"; this research assumes hard delete (files removed). If the owner wanted soft delete, the file-removal rule (Pitfall 6) changes.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV | All tests and commands (MEMORY: use `ddev exec`) | yes | DDEV v1.25.4 | — |
| PHP | Everything | yes | 8.5.8 (in DDEV) | — |
| PHP `zip`, `fileinfo` | Archive tests, mime detection | yes | loaded in DDEV (`php -m`) | — |
| PostgreSQL 18 | Composite FKs, partial index, generated columns | yes (project requirement FND-20) | — | — |
| RustFS (S3-compatible) | Real S3 tests (`s3` Pest group), delete-object proof | yes in DDEV, reachable only inside the DDEV network | rustfs 1.0.1 | Tests default to `local` disk; S3 group for the delete-object proof |
| Browser reach of the bucket | Presigned URLs | **no** (ports only `expose`d) | — | Stream through the app (recommended), Livewire temp disk local |
| Redis / Horizon | Queue for ZIP | yes (project requirement) | — | `sync` in tests |
| `gsd-tools package-legitimacy` for composer | Legitimacy gate | no (npm, pypi, crates only) | — | Manual Packagist check done |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** browser-reachable bucket (use app streaming).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3 on PHPUnit 13.3 (composer.json), `pestphp/pest-plugin-laravel` 5 |
| Config file | `phpunit.xml` (pins `FILESYSTEM_DISK` and `MEDIA_DISK` to `local`, lines 42 and 46); `tests/Pest.php` (Feature and Isolation use `RefreshDatabase`) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Documents tests/Feature/Finance` |
| Full suite command | `ddev exec vendor/bin/pest` (config `workflow.test_command`); static analysis `ddev exec composer stan`, style `ddev exec composer lint`, licences `ddev exec composer check-licenses` |
| S3-specific | `ddev exec vendor/bin/pest --group=s3` (RustFS; `pest()->group('s3')` as in tests/Feature/Operations/StorageCheckTest.php:16) |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DO-01 | Admin attaches a file to client/project/task/comment/transaction/none; row, media, size, mime, client_id/project_id correct | feature | `pest tests/Feature/Documents/AttachDocumentsTest.php` | Wave 0 |
| DO-01 | Partner sees only visible files on own visible projects/tasks, never internal-comment, never other client, never client/transaction/unattached files | isolation | `pest tests/Isolation/DocumentVisibilityTest.php` | Wave 0 |
| DO-01 | Partner upload only on own task/non-internal comment; cannot change flag; deletes only own while task open; Admin bell | feature | `pest tests/Feature/Documents/PartnerUploadTest.php` | Wave 0 |
| DO-01 | Canary: both clients' strings never reach the other Partner on any surface (fixtures for Document incl. hidden twin, internal-comment twin, client-owned, unattached) | isolation | `pest tests/Isolation/CanaryRegistryTest.php tests/Isolation/RouteWalkTest.php` | exists, extend |
| DO-01 | `documents` Partner-safe column list pinned; ModelDeclaration and ActivityAllowlist lists extended | arch | `pest tests/Isolation/PartnerSafeColumnsTest.php tests/Arch` | exists, extend |
| DO-02 | Listing filters (owner type, client, project, date range, type group, visibility), sorting, search | feature (Livewire) | `pest tests/Feature/Documents/DocumentResourceTest.php` | Wave 0 |
| DO-02 | Storage totals overall and per client, "no client" row, sum of originals | feature | `pest tests/Feature/Documents/StorageTotalsTest.php` | Wave 0 |
| DO-02 | ZIP request limits, job builds archive, dedupes names, notifies, idempotent re-run, prune deletes object then row | feature | `pest tests/Feature/Documents/DocumentArchiveTest.php` | Wave 0 |
| DO-02 | Bulk delete removes rows and objects, one failure does not hide the rest | feature | `pest tests/Feature/Documents/DeleteDocumentTest.php` | Wave 0 |
| DO-03 | Route: unsigned 403, expired 403, other user's signature 403, Partner of another client 404, correct headers (nosniff, attachment vs inline, no-store), no bucket URL anywhere | feature | `pest tests/Feature/Documents/DocumentFileRouteTest.php` | Wave 0 |
| DO-03 | Object removed from the disk on delete (also when the actor is a Partner) and when a transaction is deleted | feature + s3 group | `pest tests/Feature/Documents/DeleteDocumentTest.php --group=s3` | Wave 0 |
| DO-03 | Livewire temp upload disk is local; Livewire rule equals kokpit limit; medialibrary ceiling equals 10 MB | feature | `pest tests/Feature/Documents/UploadConfigTest.php` | Wave 0 |
| FI-01 | Transaction CRUD, composite FKs (type mismatch, third category level, project of other client) refused by the database | schema (raw SQL helper) | `pest tests/Feature/Schema/TransactionTablesTest.php` | Wave 0 |
| FI-01 | CZK: identity, CNB computed with snapshot, missing rate asks for manual, manual sticky, recompute rules | feature | `pest tests/Feature/Finance/TransactionCzkTest.php` | Wave 0 |
| FI-01 | Category rules: archive/unarchive, delete only when unused, system category not deletable/archivable but renameable | feature | `pest tests/Feature/Finance/CategoryRulesTest.php` | Wave 0 |
| FI-02 | Overview totals per month/year, child rollup into parent, by client with "no client", chart data, forged `pageFilters` fall back | feature (Livewire) | `pest tests/Feature/Finance/FinanceOverviewTest.php` | Wave 0 |

### Sampling Rate
- **Per task commit:** the focused test file(s) of that task, `ddev exec vendor/bin/pest <path>` (under 30 s each).
- **Per wave merge:** `ddev exec vendor/bin/pest tests/Feature/Documents tests/Feature/Finance tests/Isolation tests/Arch tests/Feature/Schema` plus `ddev exec composer stan`.
- **Phase gate:** full suite, `composer lint`, `composer stan`, `composer check-licenses`, `scripts/check-sensitive.sh`, and the `s3` group green before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Feature/Documents/*` and `tests/Feature/Finance/*` directories with the files above
- [ ] `tests/Feature/Schema/DocumentsTableTest.php`, `TransactionTablesTest.php` (use the raw-SQL helper `tests/Support/RawSql.php`)
- [ ] `Document` (and later `DocumentArchive`, `Transaction`, `TransactionCategory`) lines in `tests/Support/CanaryRegistry.php::fixtures()` with a real stored file on the fake disk; Partner-visible canary plus hidden, internal-comment, client-owned and unattached twins; the exact-one-row pattern of the `TaskComment` fixture
- [ ] Update pinned lists: `tests/Arch/ModelDeclarationTest.php` (app models), `tests/Arch/ActivityAllowlistTest.php` (logging models), `tests/Isolation/PartnerSafeColumnsTest.php` (documents columns), job contract list (`tests/Support/JobDeclaration.php`), schedule one-server test, `PanelRegistryTest` expectations, `RouteWalkTest` and `DeniedModelsTest` (Media remains denied)
- [ ] Test helper for a fictional uploaded file (`UploadedFile::fake()->create(...)` with names assembled from fragments, per hygiene rule) and a helper to switch the media disk to a faked `local` disk (as `CanaryRegistry::prepare()` does)
- [ ] A mutation self-check, in the repo style, that the Partner scope test fails when the `visible_to_client` or internal-comment condition is removed
- [ ] Framework install: none

## Security Domain

`security_enforcement` is enabled (config.json, `security_asvs_level: 1`, `security_block_on: high`).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (downloads) | Panel `Authenticate` + `canAccessPanel` + `EnsureAdminHasTwoFactor` through `authenticatedRoutes`; signed URL bound to the user id |
| V3 Session Management | inherited | Existing panel session middleware; downloads add nothing |
| V4 Access Control | yes (core) | `PartnerScope` on `documents`, `DocumentPolicy`, `#[AccessRule]` on every Resource/Page/Widget/relation manager, route-level policy re-check, `Media` kept `DeniesPartners` |
| V5 Input Validation | yes | Filament `maxSize`/`acceptedFileTypes` hints only; the Action validates size, extension against the package disallow list, name charset, ids as UUIDs; `FileUpload` string state refused; request ids re-validated (`pageFilters`, bulk selections) |
| V6 Cryptography | no new | No hand-rolled crypto; Laravel URL signing |
| V8 Data Protection | yes | `Cache-Control: private, no-store` on file responses; no public URL; signed URLs never logged or put in errors (CONTRIBUTING:79 rule) |
| V12 Files and Resources | yes (core) | Server-generated object names, content-detected MIME, inline allowlist, attachment + nosniff for the rest, ZIP entry sanitising, private bucket, size limit |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Stored XSS via an uploaded HTML or SVG opened inline | Tampering / Elevation | Never inline anything but raster images and PDF; `X-Content-Type-Options: nosniff`; `Content-Type: application/octet-stream` for the rest; objects served from the app route only |
| IDOR on file ids / another client's file | Information disclosure | Route resolves the document through the scoped query (404), policy on top, signature bound to the user |
| Forged `FileUpload` state pointing to an existing path | Tampering | `storeFiles(false)` and accept only `TemporaryUploadedFile` instances (Filament's own note at BaseFileUpload.php:1006-1013) |
| Path traversal / odd names in the bucket or ZIP | Tampering | Object name is `<uuid>.<validated ext>`; ZIP entry names sanitised and deduped |
| Partner learns a hidden file exists (count, select, notification, activity) | Information disclosure | Scope on every query, no global search, `Activity` Admin-only, Admin-only notifications, canary tests |
| Orphaned S3 objects after a Partner delete | Repudiation / integrity | Delete runs as system after the policy; test with the fake disk and the S3 group |
| Replay of a long-lived signed URL | Spoofing | TTL in seconds (config), user id bound, auth still required, `throttle` on the route |
| ZIP DoS (huge selection) | Denial of service | `max_files` and `max_bytes` enforced at request and in the job, timeout below the supervisor limit |
| Uploaded content is malware | Tampering | Out of scope by decision (deferred idea: no scanning); the Admin downloads it as an attachment only |

## Sources

### Primary (HIGH confidence, read this session)
- `.planning/phases/09-documents-and-finance/09-CONTEXT.md`, `.planning/REQUIREMENTS.md`, `.planning/STATE.md`, `CONTRIBUTING.md` (lines 70-113)
- In-repo code: `app/Domain/Shared/Models/Media.php`, `config/media-library.php`, `config/filesystems.php`, `config/kokpit.php`, `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php`, `app/Providers/Filament/AdminPanelProvider.php`, `app/Domain/Tasks/Models/TaskComment.php`, `app/Domain/Tasks/Policies/*`, `app/Domain/Projects/*`, `app/Domain/Reports/Jobs/*`, `app/Domain/Reports/Models/ReportExport.php`, `app/Filament/Resources/ReportExportResource.php`, `app/Domain/ExchangeRates/*`, `app/Domain/Operations/Jobs/KokpitJob.php`, `database/migrations/2026_10_10_000100_create_tasks_table.php`, `2026_10_15_000200_create_report_exports_table.php`, `tests/Isolation/PartnerSafeColumnsTest.php`, `tests/Support/CanaryRegistry.php`, `tests/Arch/*`, `.ddev/docker-compose.rustfs.yaml`, `zerops.yml`, `site.conf.tmpl`, `phpunit.xml`
- Vendor source: `vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadConfiguration.php`, `.../SupportFileDownloads/SupportFileDownloads.php`, `vendor/spatie/laravel-medialibrary/src/InteractsWithMedia.php` and `MediaCollections/FileAdder.php`, `vendor/spatie/laravel-activitylog/src/Models/Concerns/LogsActivity.php`, `vendor/filament/forms/src/Components/BaseFileUpload.php`, `vendor/filament/filament/src/Panel/Concerns/HasRoutes.php`, `.../Pages/Page.php`, `.../Widgets/Concerns/InteractsWithPageFilters.php`, `vendor/filament/actions` and `tables` bulk-action concerns, `vendor/maennchen/zipstream-php/src/ZipStream.php`, `vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemAdapter.php:346-372`
- Runtime probes in DDEV: `php -m` (zip, fileinfo), `php -i` upload limits, `composer why maennchen/zipstream-php`
- Packagist API for `maennchen/zipstream-php` (version, licence, downloads)

### Secondary (MEDIUM confidence)
- docs.zerops.io/guides/php-tuning.md: PHP defaults of 1024M upload and post size, `PHP_INI_*` env variables, limits attributed to the L7 balancer

### Tertiary (LOW confidence)
- ASVS numbering and the CSP/inline-type recommendations from training knowledge (A5, A8)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - every library already installed and read; no new package
- Architecture: HIGH for the in-repo patterns (twins exist), MEDIUM for the signed-route naming (A9) and Livewire local temp storage under scaling (A4)
- Pitfalls: HIGH for 1, 2, 3, 5, 6, 8 (verified in source); MEDIUM for 4, 9, 10, 13 (design judgement)

**Research date:** 2026-10-16
**Valid until:** 2026-11-15 (stable stack; re-check Filament, Livewire and medialibrary minor releases before plan execution)
