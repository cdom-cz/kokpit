# Phase 9: Documents and Finance - Context

**Gathered:** 2026-10-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Files live on the private S3-compatible bucket under Admin control, with correct Partner visibility, a central Admin listing (filters, single and ZIP download, bulk delete, storage totals), and Admin records income and expense transactions with hierarchical categories and sees the money picture in CZK. Delivers DO-01, DO-02, DO-03, FI-01, FI-02. The file attachments on projects, tasks and comments that Phases 4-5 deferred ("files" part of PR-01, TA-01, TA-04) are delivered here, once, with download route and Partner visibility rules. The invoice reference on transactions (FI-01), income from invoice payments (FI-03) and confirmation before deleting an issued invoice PDF (DO-03) belong to Phase 10.

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): Partner never sees time, rates, prices or finance, and isolation is enforced by Policies and global scopes with fail-closed `#[AccessRule]` / `#[NotPartnerScoped]` / `#[DeniesPartners]` (Phase 2); `App\Domain\Shared\Models\Media` (UUID v7, `DeniesPartners`, "later phases open it deliberately with a client-bound constraint and explicit policy grants") is registered in `config/media-library.php`, with `MEDIA_DISK=s3` on the private bucket (Phases 2-3); money is bigint minor units plus ISO 4217 currency; time is stored UTC and shown in `Europe/Prague`; CNB rate of the transaction day with the previous valid rate for weekends and holidays, and the conversion helper `ExchangeRateConverter` (Phase 8 D-01, D-02, EX-03); report exports live 7 days on private storage, are downloaded through the app with a permission check and delivered by bell notification (Phase 8 D-09); `KokpitJob` base for queued jobs (Phase 3); Partner comments are never internal and Partner never sees internal comments or their attachments (Phase 5, TA-04); Admin notifications for Partner activity are bell only, no e-mail (Phase 5 D-15).

### Files: data model and attachment (DO-01)
- **D-01:** Every file is a row in a `documents` table with exactly one Spatie medialibrary media (the existing UUID `Media` model, private disk). The row holds name, optional description, the Partner-visibility flag, an optional owner morph (client, project, task, comment, transaction; invoice is added in Phase 10), a denormalized `client_id` (resolved from the owner and kept consistent) and the uploader. A file without owner is "unattached". The central listing, filters, Partner scope and storage totals are queries over `documents`. — **Reversibility:** costly — the central listing, Partner scope, ZIP job, totals and the Phase 10 invoice PDF all depend on this table shape.
- **D-02:** Admin manages files in a shared "Soubory" relation manager (one implementation reused) on client, project, task and transaction detail: upload, rename, visibility toggle, download, delete. Attachments of comments are uploaded directly in the comment form. The inert "attachments later" placeholders and `fileAttachments(false)` markers left by Phase 5 (A10, D-11) are replaced.

### Files: Partner visibility (DO-01)
- **D-03:** The visibility flag `visible_to_client` defaults to **true** for files uploaded on a project or task (owner decision; Admin can hide a file at upload or later). Files owned by a client, a transaction or nobody are never visible to Partner whatever the flag says; invoice PDFs reach Partner only through the Phase 10 invoice view. The flag only has effect through the Partner scope; the DB-level rule is the scope plus policy, not the UI.
- **D-04:** A Partner sees and downloads a file only when all of these hold: it belongs to a project the Partner can access (project `client_visibility` and own client) or a task of such a project; the flag is true; and it is not an attachment of an internal comment. Never another client's files. Search, selects, counts, notifications and the activity log must not leak file names or existence either (canary harness extended).
- **D-05 (owner decision, widens Phase 5 A10):** Partner can **upload** files, but only on tasks and non-internal comments they authored, in visible projects of their own client (when creating a task and in their own comments). Partner cannot attach files to someone else's task or comment, to a project or client, or to a comment marked internal (Partner comments are never internal). Partner-uploaded files are visible to the Partner at once (flag true, Partner cannot change it). Partner may delete only their own uploaded files while the task is open; Admin may delete anything. Admin gets a bell notification on every Partner upload (no e-mail). — **Reversibility:** costly — reopens a closed Phase 5 decision; Partner upload forms, policy, notification and isolation tests all depend on it.
- **D-06:** Upload limits are the same for Admin and Partner (owner decision): the medialibrary default maximum of 10 MB per file (value in `config/kokpit.php`) and the package's disallowed-extension list, no separate whitelist. Because a Partner can upload, the planner must treat uploaded content as untrusted: sanitized stored names, no inline rendering except images and PDF (see D-10), `X-Content-Type-Options: nosniff`, and no public URL.

### Central listing, ZIP, deletion, totals (DO-02, DO-03)
- **D-07:** The Admin-only central documents listing shows name, owner type with a link to the owner, client, size, MIME type, uploader, date and the visibility flag; all columns sortable. Filters: owner type, client, project, date range, file-type group and visibility; search in the name. Unattached files show with owner "unattached".
- **D-08:** ZIP download is a bulk action over the selected rows (including a filter result), built by a queued `KokpitJob` (idempotent), kept 7 days on private storage like report exports (value in `config/kokpit.php`), then deleted by the scheduled prune; Admin gets a bell notification with a link; download goes through the app with a permission check. Maximum file count and total size per ZIP are configured in `config/kokpit.php`. A "download everything of a client or project" button is not part of this phase.
- **D-09:** Deleting a file, single or bulk, is permanent after a confirmation dialog and removes the stored S3 object together with the row (DO-03); each deletion is written to the activity log (name, size, owner type). No trash. Deleting an owning record also removes its files and objects. Confirmation for issued invoice PDFs is built in Phase 10. — **Reversibility:** one-way — a deleted S3 object cannot be recovered by the app.
- **D-10:** Every download and preview goes through an app route that checks the policy and then hands out a short-lived signed URL (or streams); no public bucket URL exists. The TTL is short (seconds to minutes, value in `config/kokpit.php`). Images and PDF can be previewed inline in the browser; every other type downloads as an attachment.
- **D-11:** Storage totals are stat tiles above the listing (total file count and size) and a by-client table (client, file count, size) with an "Unattached" row; a file counts for the client in its denormalized `client_id` (project, task and comment files roll up to the project's client). Sizes are the sum of original files only (no conversions).

### Transactions and CZK amount (FI-01)
- **D-12:** A transaction has type (income/expense), date (Europe/Prague calendar date), category, currency, amount in minor units, amount in CZK, description, free-text counterparty, optional document number, optional client and optional project (the project must belong to the chosen client). The invoice link is added in Phase 10. Receipts are file attachments through the shared Soubory relation manager (D-02). Admin-only; Partner has no access.
- **D-13:** Any currency stored in the CNB rates can be used. The CZK amount is computed from the CNB rate of the transaction date (previous valid rate for weekends and holidays, `ExchangeRateConverter`) and stored together with the rate as a snapshot, so later rate changes never alter it. Admin may overwrite the CZK amount with the real figure from the bank; a manually set amount is flagged as manual and kept. When no rate exists for the currency and date, the form asks for the CZK amount manually and does not block saving. CZK transactions need no rate.
- **D-14:** Transactions are freely editable and deletable by Admin (changes recorded in the activity log with an explicit attribute allowlist; deleting a transaction deletes its receipt files with confirmation per D-09). The list has a receipt-count column and a "without receipt" filter. There is no period closing. Transactions created from invoice payments in Phase 10 (FI-03) will be restricted to editing date and category; that restriction is built there.

### Categories and overview (FI-01, FI-02)
- **D-15:** Categories are two levels (parent and child). Each category has a type (income or expense); a child inherits its parent's type; a transaction takes only a category of the same type. Categories can be archived but not deleted once used (DB restrict FK); unused categories can be deleted. Order is manual (eloquent-sortable).
- **D-16:** The app seeds a small generic set of Czech-named categories with fictional-safe generic content (for example invoicing, software, fees, tax payments) and one system income category for invoice payments, used by Phase 10 (FI-03), which can be renamed but not deleted or archived. No real business data.
- **D-17:** The finance overview is one Admin page with a Month/Year switch: tiles for income, expense and balance in CZK; a category table that rolls child sums into the parent; a by-client table with a "No client" row; and a bar chart of months in the selected year (Filament chart widget). Periods use the transaction date in Europe/Prague; all sums are in CZK from the stored CZK amounts.

### Claude's Discretion
Class, file, table and index names; the exact shape of the owner morph and how `client_id` is kept consistent (DB trigger, model hook or action); whether comment file uploads reuse the relation-manager component; signed-URL mechanism details (redirect to S3 temporary URL vs stream); the file-type group definitions for the filter; ZIP library and entry naming; category seed names (Czech, generic); exact CHECK constraints (type match, project-belongs-to-client); Czech wording; chart styling; how a Partner upload form is added to the create-task and own-comment forms; test structure.

### Owner decisions recorded at plan check (2026-10-16)

- **D-18:** Production runs more than one web container, so Livewire temporary uploads must NOT use the local disk of a single container: temporary uploads go through the S3-compatible private bucket (Livewire S3 temporary upload variant, with bucket CORS documented and a deploy-rehearsal check). This supersedes research assumption A4 (local temp disk); the DDEV bucket must be reachable from the browser or the test/dev path documented in CONTRIBUTING.
- **D-19:** "Task open" (Partner may delete own files) means the task is not archived and its status is not `done` (research A2).
- **D-20:** Files on non-internal comments default to visible to the Partner; files on internal comments are always hidden; the Admin can toggle visibility (research A1).
- **D-21:** Archiving a client, project or task hides its files too: files of archived owners disappear from the Partner view and from the default Admin listing (shown under an "archived owners" filter), and reappear on restore; archiving never deletes stored objects. Real deletion of files happens only by explicit file delete (D-09) and by deleting a transaction. This amends D-09's "deleting an owning record also removes its files" to mean real deletion of a transaction.
- **D-22:** The rich editors for task descriptions and comments keep inline attachments off (`fileAttachments(false)`) by design; files go only through the documents flow, so uploads cannot bypass the policy and sanitiser. This amends D-02 and was accepted by the owner.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and requirements
- `.planning/ROADMAP.md` §Phase 9 — goal and 5 success criteria; cross-phase note on file attachments (PR-01, TA-01, TA-04) and on Phase 10 items
- `.planning/REQUIREMENTS.md` — DO-01, DO-02, DO-03, FI-01, FI-02 (FI-03 and the invoice reference are Phase 10), FND-02, FND-16, FND-18
- `.planning/PROJECT.md` — Constraints (Partner isolation, UUID v7 incl. package morph columns, repository hygiene, immutability)

### Prior decisions
- `.planning/phases/05-tasks-and-kanban/05-CONTEXT.md` — D-11 (file attachments deferred to Phase 9), comments and Partner rules, D-15 notification channels
- `.planning/phases/08-exchange-rates-and-reports/08-CONTEXT.md` — D-01, D-02 (CNB rate of the day, conversion helper), D-09 (private export storage, 7-day retention, download through the app)
- `.planning/phases/03-operations-foundation/03-CONTEXT.md` — job base and alerts, S3 smoke test and private storage setup
- `.planning/phases/02-platform-foundation/` — `#[AccessRule]` / `#[DeniesPartners]` rules and the Partner isolation canary harness (FND-18)

### Code touchpoints
- `app/Domain/Shared/Models/Media.php` — UUID medialibrary model, currently `DeniesPartners`
- `config/media-library.php` — disk, 10 MB limit, disallowed extensions, custom model
- `app/Providers/AccessServiceProvider.php` — where `Media` is registered as Admin-only
- `app/Domain/Shared/Database/MorphMap.php` — morph map that must include new owner types
- `app/Filament/Resources/TaskResource.php` (attachment placeholder), `.../TaskCommentsRelationManager.php`, `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php` — places that currently disable attachments (A10)
- `app/Domain/ExchangeRates/ExchangeRateConverter.php` — CNB conversion helper
- `app/Domain/Reports/Exports/`, `app/Domain/Reports/Jobs/`, `app/Filament/Resources/ReportExportResource.php` — existing queued export, private storage, retention and download pattern to reuse for ZIP

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `Media` model (UUID v7, `PartnerIsolated`): the file storage layer, to be wrapped by `documents` rows.
- Report export pipeline (queued job, private storage, 7-day prune, bell notification, app download route): template for the ZIP download.
- `ExchangeRateConverter` and `RateBook`: CZK conversion for transactions.
- `KokpitJob` base, activity log allowlist pattern, Partner canary harness, registry test that fails on Resources/Pages/Widgets without access rules.

### Established Patterns
- Fail-closed access attributes on every Resource, Page, Widget and relation manager; Partner resources live under `app/Filament/Partner/`.
- Domain code in `app/Domain/<Area>/` with Actions, Models, Policies; Filament only adapts.
- Money as `Money` value object with one rounding point; DB CHECK and FK constraints with a raw-SQL test helper.
- Czech user-facing text in `lang/cs`.

### Integration Points
- Task, project, client and transaction detail pages (new "Soubory" relation manager).
- Task comment forms (Admin and Partner) and the Partner create-task form (uploads).
- Admin navigation: documents listing, transactions, categories, finance overview.
- Phase 10 will attach invoice PDFs to `documents` and add invoice reference and income-on-payment to transactions.

</code_context>

<specifics>
## Specific Ideas

- The owner explicitly wants Spatie medialibrary used for storage while keeping a `documents` record as the entity.
- The owner chose visibility on by default for project and task files and Partner upload on own tasks and comments, both more permissive than the minimal reading of DO-01; the isolation tests must therefore be proportionally stricter.

</specifics>

<deferred>
## Deferred Ideas

- "Download everything of a client or project" one-click ZIP — not in this phase.
- Period closing for finance — not in this phase.
- Stricter file-type whitelist or malware scanning for uploads — not chosen; revisit if Partner uploads become a concern.

### Reviewed Todos (not folded)
- "Redesign unreadable time report layout" — matched on keywords only; it is a Phase 8 gap already covered by UI-SPEC Amendment A-1 (plans 08-26, 08-27).

</deferred>

---

*Phase: 9-Documents and Finance*
*Context gathered: 2026-10-16*
