---
phase: 09-documents-and-finance
reviewed: 2026-10-16T12:00:00Z
depth: standard
files_reviewed: 96
files_reviewed_list:
  - app/Domain/Clients/Models/Client.php
  - app/Domain/Documents/Actions/AttachDocuments.php
  - app/Domain/Documents/Actions/DeleteDocument.php
  - app/Domain/Documents/Actions/RenameDocument.php
  - app/Domain/Documents/Actions/RequestDocumentArchive.php
  - app/Domain/Documents/Actions/SetDocumentVisibility.php
  - app/Domain/Documents/Enums/DocumentArchiveStatus.php
  - app/Domain/Documents/Enums/DocumentOwnerType.php
  - app/Domain/Documents/Enums/DocumentTypeGroup.php
  - app/Domain/Documents/Jobs/BuildDocumentArchive.php
  - app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php
  - app/Domain/Documents/Models/Document.php
  - app/Domain/Documents/Models/DocumentArchive.php
  - app/Domain/Documents/Notifications/DocumentArchiveFailedNotification.php
  - app/Domain/Documents/Notifications/DocumentArchiveReadyNotification.php
  - app/Domain/Documents/Notifications/DocumentNotifier.php
  - app/Domain/Documents/Notifications/PartnerUploadedFilesNotification.php
  - app/Domain/Documents/Policies/DocumentPolicy.php
  - app/Domain/Documents/Queries/StorageTotals.php
  - app/Domain/Documents/Support/ArchiveEntryNames.php
  - app/Domain/Documents/Support/DocumentDisposition.php
  - app/Domain/Documents/Support/DocumentFileName.php
  - app/Domain/Documents/Support/DocumentOwnerResolver.php
  - app/Domain/Documents/Support/DocumentUrl.php
  - app/Domain/Finance/Actions/ArchiveTransactionCategory.php
  - app/Domain/Finance/Actions/DeleteTransaction.php
  - app/Domain/Finance/Actions/DeleteTransactionCategory.php
  - app/Domain/Finance/Actions/MoveTransactionCategory.php
  - app/Domain/Finance/Actions/RecordTransaction.php
  - app/Domain/Finance/Actions/SaveTransactionCategory.php
  - app/Domain/Finance/Actions/UpdateTransaction.php
  - app/Domain/Finance/Enums/CzkSource.php
  - app/Domain/Finance/Enums/TransactionType.php
  - app/Domain/Finance/FinanceFilters.php
  - app/Domain/Finance/Models/Transaction.php
  - app/Domain/Finance/Models/TransactionCategory.php
  - app/Domain/Finance/Queries/FinanceOverview.php
  - app/Domain/Finance/Support/SqlState.php
  - app/Domain/Finance/TransactionCzk.php
  - app/Domain/Finance/TransactionInput.php
  - app/Domain/Projects/Models/Project.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Money/Money.php
  - app/Domain/Tasks/Models/Task.php
  - app/Domain/Tasks/Models/TaskComment.php
  - app/Filament/Concerns/ManagesDocuments.php
  - app/Filament/Concerns/RendersTaskCommentThread.php
  - app/Filament/Concerns/ShowsPartnerDocuments.php
  - app/Filament/Finance/Widgets/FinanceCategoryTable.php
  - app/Filament/Finance/Widgets/FinanceClientTable.php
  - app/Filament/Finance/Widgets/FinanceMonthsChart.php
  - app/Filament/Finance/Widgets/FinanceStats.php
  - app/Filament/Pages/FinanceOverviewPage.php
  - app/Filament/Partner/Resources/PartnerProjectResource.php
  - app/Filament/Partner/Resources/PartnerProjectResource/RelationManagers/PartnerProjectDocumentsRelationManager.php
  - app/Filament/Partner/Resources/PartnerTaskResource.php
  - app/Filament/Partner/Resources/PartnerTaskResource/Pages/CreatePartnerTask.php
  - app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php
  - app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php
  - app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskDocumentsRelationManager.php
  - app/Filament/RelationManagers/ClientDocumentsRelationManager.php
  - app/Filament/RelationManagers/ProjectDocumentsRelationManager.php
  - app/Filament/RelationManagers/TaskDocumentsRelationManager.php
  - app/Filament/RelationManagers/TransactionDocumentsRelationManager.php
  - app/Filament/RelationManagers/TransactionHistoryRelationManager.php
  - app/Filament/Resources/ClientResource.php
  - app/Filament/Resources/DocumentResource.php
  - app/Filament/Resources/DocumentResource/Pages/ListDocuments.php
  - app/Filament/Resources/DocumentResource/Widgets/DocumentStorageByClient.php
  - app/Filament/Resources/DocumentResource/Widgets/DocumentStorageStats.php
  - app/Filament/Resources/ProjectResource.php
  - app/Filament/Resources/TaskResource.php
  - app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php
  - app/Filament/Resources/TransactionCategoryResource.php
  - app/Filament/Resources/TransactionCategoryResource/Pages/ListTransactionCategories.php
  - app/Filament/Resources/TransactionResource.php
  - app/Filament/Resources/TransactionResource/Pages/CreateTransaction.php
  - app/Filament/Resources/TransactionResource/Pages/EditTransaction.php
  - app/Filament/Resources/TransactionResource/Pages/ListTransactions.php
  - app/Filament/Support/DocumentActions.php
  - app/Filament/Support/DocumentColumns.php
  - app/Http/Controllers/DocumentFileController.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Support/ProductionConfigGuard.php
  - config/kokpit.php
  - config/livewire.php
  - database/migrations/2026_10_16_000100_create_documents_table.php
  - database/migrations/2026_10_16_000200_create_transaction_categories_table.php
  - database/migrations/2026_10_16_000300_seed_transaction_categories.php
  - database/migrations/2026_10_16_000400_create_transactions_table.php
  - database/migrations/2026_10_16_000500_create_document_archives_table.php
  - resources/views/filament/pages/finance-overview.blade.php
  - resources/views/filament/tasks/comment-thread.blade.php
  - routes/console.php
findings:
  critical: 1
  warning: 2
  info: 9
  total: 12
status: issues_found
---

# Phase 9: Code Review Report

**Reviewed:** 2026-10-16
**Depth:** standard
**Files Reviewed:** 96 (plus, as supplementary context outside the list, `app/Http/Controllers/DocumentArchiveFileController.php` and `app/Filament/Resources/DocumentArchiveResource.php`, which belong to the same plans and the same download surface but are missing from the scope list)
**Status:** issues_found

## Summary

The phase is carefully built. The security-critical surfaces held up under adversarial reading:

- **Signed download routes.** The user id travels under the signature and is compared to the session user; the document id is UUID-checked; the row is read through the Partner-scoped query before the policy; the media row is read as system by a fixed `model_type`/`model_id` pair, so no request value reaches a storage path. Only five content-detected types render inline; everything else is `application/octet-stream` with `nosniff`. I probed the `Content-Disposition` builder with Unicode slash lookalikes (U+2044, U+2215, U+FF0F, U+29F8) and percent signs. Symfony's `HeaderUtils` never throws and the output is well formed.
- **Partner isolation.** `Document::constrainForPartner()` ties visibility, client and owner to the already-scoped Project, Task and TaskComment queries. The policy repeats the rule, every Livewire entry point (`downloadCommentDocument`, `previewCommentDocument`, delete) re-reads the id through a scoped query, finance models and widgets are `AdminOnly`, and the reader classes refuse a non-Admin on their own. I found no Partner leak.
- **Upload handling.** Only uploaded-file objects are accepted, the copy is bounded at one byte past the limit, the MIME type is detected from the bytes, the stored object name is `<uuid>.<[a-z0-9]{1,10}>`, and display names are cleaned of control, bidi and separator characters.
- **ZIP building.** Entry names cannot leave the extraction folder (separators replaced, `..` runs collapsed, leading dots trimmed), but one reachable name pattern crashes the builder (CR-01).
- **Data integrity.** The migrations pin the owner pair, the visibility rule, the composite project/client keys, the path shape of archives, and the CNB snapshot consistency in the database.
- **Repository hygiene.** A scan of the reviewed files found no real data, hostnames, addresses or secrets.

One bug breaks a feature on realistic input (CR-01). Two defects concern irreversible or lingering storage side effects around transactions (WR-01, WR-02). The rest is robustness and hardening.

## Critical Issues

### CR-01: Duplicate-name numbering in `ArchiveEntryNames::unique()` throws on a legitimate file set, so the ZIP build fails

**File:** `app/Domain/Documents/Support/ArchiveEntryNames.php:72-101` (the failing read is line 90)
**Issue:** `$counts` is written only when a name is handed out unchanged (line 78). A numbered candidate (`a (2).pdf`, produced at line 94) is recorded in `$taken` (line 98) but never in `$counts`. If a later document in the same archive is genuinely named like that candidate, `unique()` takes the "already taken" branch for that key, and `$this->counts[$key]` on line 90 is an undefined array key.

The queue worker turns the resulting PHP warning into an `ErrorException`, so `BuildDocumentArchive` fails on every attempt. After three tries the Admin gets only the generic "archive failed" bell.

Reproduced against the real class with a warnings-to-exceptions handler (the same effect as Laravel's `HandleExceptions`), for the selection order `a.pdf`, `a.pdf`, `a (2).pdf`:

```
a.pdf
a (2).pdf
ErrorException: Undefined array key "a (2).pdf"
```

This is realistic. A user uploads `plan.pdf` twice and also a browser-numbered copy `plan (2).pdf`. Order matters (the reverse order works), which makes it intermittent and hard to diagnose. There is no workaround short of renaming or deselecting files.

**Fix:** Treat a missing counter as 1, and register the counter for every name handed out so the numbering stays consistent:

```php
public function unique(string $name): string
{
    $key = mb_strtolower($name);

    if (! isset($this->taken[$key])) {
        $this->taken[$key] = true;
        $this->counts[$key] = 1;

        return $name;
    }

    $stem = $name;
    $suffix = '';

    if (preg_match('/^(.+)(\.[A-Za-z0-9]{1,10})$/u', $name, $matches) === 1) {
        [, $stem, $suffix] = $matches;
    }

    $number = $this->counts[$key] ?? 1;

    do {
        $number++;
        $candidate = $stem.' ('.$number.')'.$suffix;
        $candidateKey = mb_strtolower($candidate);
    } while (isset($this->taken[$candidateKey]));

    $this->counts[$key] = $number;
    $this->taken[$candidateKey] = true;
    $this->counts[$candidateKey] = 1;

    return $candidate;
}
```

Add a regression test for the order `['a.pdf', 'a.pdf', 'a (2).pdf']`.

## Warnings

### WR-01: Stored objects are destroyed inside the database transaction, so a later failure rolls back the rows but not the files (receipts included)

**File:** `app/Domain/Finance/Actions/DeleteTransaction.php:38-52`, `app/Domain/Documents/Actions/DeleteDocument.php:43-53` and `:77-92`
**Issue:** `DeleteDocument` removes the S3 object during the transaction (the media observer fires on `$locked->delete()`, and `assertObjectsRemoved()` even retries a strict delete). `DeleteTransaction` loops over all receipts inside one outer transaction and then deletes the transaction row.

If receipt N fails (object cannot be removed), or `$locked->delete()` fails, or the commit fails, the database rolls back every earlier receipt row. Their objects are already gone for good. Those receipts then show in the list and 404 on download. The docblock calls this an "accepted rare case", but receipts are finance records ("a receipt is a finance record that must stay findable", `Document` docblock), and the loss is silent and irreversible. `DeleteDocument` has the same shape for a failed commit. The same ordering also lets a bulk delete of 100 files lose objects behind rows that survive.

**Fix:** Make the database the source of truth and move the irreversible side effect after the commit. Delete the rows (and media rows) in the transaction, collect `[disk, path]` pairs, and remove the objects in `DB::afterCommit()`. A failed object delete is then reported and left for the consistency check of plan 09-20 as an orphan object. An orphan object is recoverable and invisible, unlike a row without its object. Sketch:

```php
$paths = [];
DB::transaction(function () use (&$paths, $transaction, $actor): void {
    $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
    foreach ($locked->documents()->orderBy('id')->get() as $receipt) {
        $paths = [...$paths, ...$this->deleteDocument->deleteRowsOnly($actor, $receipt)];
    }
    $locked->delete();
});
$this->deleteDocument->removeObjects($paths); // best effort, reported on failure
```

(This needs the media package's file remover to be switched off for the delete, for example `->deletePreservingMedia()` semantics or by deleting the media row through a query.)

### WR-02: The ZIP of a failed or raced archive stays on the bucket forever, because the prune deletes only `path`

**File:** `app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php:35-43`, `app/Domain/Documents/Jobs/BuildDocumentArchive.php:89-96`, `:120-125`, `:130-143`
**Issue:** `handle()` uploads the finished ZIP with `store()` (line 91) before the status flips to ready. If the final transaction then fails (for example the notification insert throws) on every attempt, or the record was pruned or changed in the meantime, `failed()` runs `giveUp()`. That marks the record failed with `path = NULL` and leaves the uploaded object. The prune then skips the delete (`if ($archive->path !== null)`), deletes the row, and the object, which contains client files, is orphaned permanently. This contradicts the stated promise that no archive of client files outlives the retention (D-08, `routes/console.php` comment). The same applies to the "not pending any more" early return after `store()` (lines 101-103).

**Fix:** The object path is a pure function of the id, so the prune can always delete it, whatever the status. Also delete it in `giveUp()`:

```php
// PruneExpiredDocumentArchives
foreach ($archives as $archive) {
    $disk->delete($archive->storagePath()); // idempotent; a missing object is not an error
    $archive->delete();
}

// BuildDocumentArchive::giveUp(), after markFailed()
Storage::disk()->delete($locked->storagePath());
```

## Info

### IN-01: A zero-byte upload is refused with the misleading "unreadable" message

**File:** `app/Domain/Documents/Actions/AttachDocuments.php:234-238`
**Issue:** `stream_copy_to_stream()` returns `0` for an empty but perfectly readable file, and `$copied === 0` is grouped with the read failure. The user sees `upload_unreadable` for a valid empty file such as a placeholder `.txt`.
**Fix:** Either allow empty files (`$copied === false` only) or give a dedicated `documents.errors.empty_file` message.

### IN-02: Served user content carries no Content-Security-Policy

**File:** `app/Http/Controllers/DocumentFileController.php:68-74` (and the twin in `DocumentArchiveFileController.php`)
**Issue:** The response hardening (content-detected inline allowlist, `nosniff`, `no-store`) is sound. There is no defence in depth if the allowlist grows later, and `grep` finds no CSP or `X-Frame-Options` anywhere in `app/`, `bootstrap/` or `config/`. A PDF or image opened in the app origin should be sandboxed.
**Fix:** Add `'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:"` and `'X-Frame-Options' => 'DENY'` to both file responses, or in the shared middleware.

### IN-03: ZIP entry names keep characters that Windows cannot extract

**File:** `app/Domain/Documents/Support/ArchiveEntryNames.php:107-122`
**Issue:** `clean()` handles separators, `..`, control and bidi characters, but not `: * ? " < > |` or reserved device names. A document called `a:b*c?.pdf` or `CON` goes into the ZIP unchanged (verified against the real class). Windows Explorer and common unzip tools then fail, prompt, or rename. This is not a traversal risk, but the stated purpose is a safe archive for clients who typically use Windows.
**Fix:** Replace `[<>:"|?*]` with `-` in `clean()`, and prefix reserved basenames (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`) with `_`.

### IN-04: Making a file visible silently exposes a previously written description

**File:** `app/Filament/Support/DocumentActions.php:158-178` (and the rename modal, `:120-152`)
**Issue:** The description column is part of the pinned Partner columns. The upload form warns that the client sees the description (`documents.hints.visible_to_client`), but the one-click "Show to client" action and the rename modal carry no such warning. An Admin who stored an internal note on a hidden file and later shares it publishes the note.
**Fix:** Add `->requiresConfirmation()` with a description that names the exposure to `toggleVisibility()` when `! $record->visible_to_client`, and a helper text on the rename modal's description field.

### IN-05: The transaction delete action catches only `RuntimeException`

**File:** `app/Filament/Resources/TransactionResource/Pages/EditTransaction.php:72-79`
**Issue:** `DeleteTransaction` can also raise `QueryException` (deadlock, lock timeout) or a storage exception that is not a `RuntimeException`. Those surface as a 500 instead of the prepared failure notification.
**Fix:** Catch `Throwable`, rethrow `AuthorizationException`, and `report()` the rest, as `DocumentActions::deleteOne()` already does.

### IN-06: Storage side effects of `AttachDocuments` are not compensated when an outer transaction fails after it returns

**File:** `app/Domain/Documents/Actions/AttachDocuments.php:87-117` and `:370-383`; callers `app/Filament/Concerns/RendersTaskCommentThread.php`, `app/Filament/Partner/Resources/PartnerTaskResource/Pages/CreatePartnerTask.php`
**Issue:** The "all or none" cleanup covers failures inside the action. Both callers wrap the action in their own `DB::transaction()`. When the outer commit fails after `handle()` returned, the written objects stay and the Livewire temporary uploads are already deleted (`deleteTemporaryUploads()` runs before the outer commit). The user cannot resubmit the same selection, and orphan objects remain. The window is small.
**Fix:** Run `deleteTemporaryUploads()` through `DB::afterCommit()`, and let the orphan check of plan 09-20 cover the objects, or give the action a way to defer both effects to the outermost commit.

### IN-07: The bucket accepts direct browser uploads without a byte cap

**File:** `config/livewire.php:133-152`
**Issue:** On the S3 path the Livewire `rules` (`max:10240`) are not applied to the presigned PUT. An authenticated Partner can put a very large object under `livewire-tmp/`. `AttachDocuments` bounds what it copies, so correctness holds, but the object stays until the bucket lifecycle rule (plan 09-24, operator notes) removes it.
**Fix:** Keep the lifecycle rule mandatory in the operator checklist and, if the provider allows it, set a bucket policy or a `content-length-range` condition on the presigned post. Nothing further is needed in code.

### IN-08: Duplicated constants and mixed clock sources

**File:** `app/Domain/Documents/Actions/AttachDocuments.php:58`, `RenameDocument.php:28`, `RequestDocumentArchive.php:33-35`, `BuildDocumentArchive.php:57-62`, `DocumentArchive.php:51`, `DocumentColumns.php:39`; clock use in `DocumentArchiveFileController.php:53`, `DocumentArchiveResource.php`, `PruneExpiredDocumentArchives.php:34` versus `BuildDocumentArchive.php:105`
**Issue:** The description limit `2000` appears in two actions and two Filament forms, the archive defaults `200` and `209715200` are repeated in two classes even though `config/kokpit.php` already defines them, and `'Europe/Prague'` is a private constant in at least four classes. The archive lifecycle mixes `TimerClock::now()` (build job, model) with `now()` and `CarbonImmutable::now()` (controller, resource, prune). The two agree in production but diverge under a faked clock.
**Fix:** One `DocumentLimits` value holder (or config keys) for the description limit and archive defaults, reuse the display time zone constant that already exists, and use `TimerClock::now()` for every archive-expiry decision.

### IN-09: Stale comment and a sort-position race

**File:** `app/Providers/AccessServiceProvider.php:101-103`; `app/Domain/Finance/Actions/SaveTransactionCategory.php:98-105` with `database/migrations/2026_10_16_000200_create_transaction_categories_table.php`
**Issue:** The `AccessServiceProvider` comment says a Partner "is granted nothing yet" and that the grants are "added in plans 09-08 and 09-10". `DocumentPolicy` now carries them. Separately, a new category takes `max(position)+1` through the sortable trait without a lock, and `position` has no unique constraint, so two concurrent creates in one sibling group get the same position and `moveOrderUp/Down` then cannot separate them. This is low risk for one Admin.
**Fix:** Update the comment. If concurrency matters, take a `lockForUpdate()` on the parent (or the sibling group) in `create()`, or add a deferrable unique index on `(type, parent_id, position)` and renumber on conflict.

---

_Reviewed: 2026-10-16_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
