---
phase: 20-image-attachments
reviewed: 2026-11-27T00:00:00Z
depth: standard
files_reviewed: 52
files_reviewed_list:
  - app/Console/Commands/DeployVerifyCommand.php
  - app/Domain/Knowledge/Actions/BindArticleImages.php
  - app/Domain/Knowledge/Actions/DeleteImageForGood.php
  - app/Domain/Knowledge/Actions/PruneImages.php
  - app/Domain/Knowledge/Actions/ReasonText.php
  - app/Domain/Knowledge/Actions/RedactVersion.php
  - app/Domain/Knowledge/Actions/SaveArticle.php
  - app/Domain/Knowledge/Actions/StoreArticleImage.php
  - app/Domain/Knowledge/Actions/StoredImage.php
  - app/Domain/Knowledge/Images/Animation.php
  - app/Domain/Knowledge/Images/GdCapability.php
  - app/Domain/Knowledge/Images/ImageFormat.php
  - app/Domain/Knowledge/Images/ImageNames.php
  - app/Domain/Knowledge/Images/ImagePipeline.php
  - app/Domain/Knowledge/Images/ImageRefusal.php
  - app/Domain/Knowledge/Images/ImageRefused.php
  - app/Domain/Knowledge/Images/JpegOrientation.php
  - app/Domain/Knowledge/Images/ProcessedImage.php
  - app/Domain/Knowledge/Jobs/PruneKnowledgeImages.php
  - app/Domain/Knowledge/Models/KnowledgeArticle.php
  - app/Domain/Knowledge/Models/KnowledgeArticleImage.php
  - app/Domain/Knowledge/Models/KnowledgeArticleVersion.php
  - app/Domain/Knowledge/Models/KnowledgeArticleVersionImage.php
  - app/Domain/Knowledge/Queries/ArticleImages.php
  - app/Domain/Knowledge/Support/ImageContext.php
  - app/Domain/Knowledge/Support/ImageReferences.php
  - app/Domain/Knowledge/Support/InternalImageSrcSanitizer.php
  - app/Domain/Knowledge/Support/KnowledgeMarkdown.php
  - app/Domain/Knowledge/Support/LinkAndImagePolicy.php
  - app/Domain/Knowledge/Support/MissingImage.php
  - app/Domain/Knowledge/Support/MissingImageRenderer.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Filament/RelationManagers/KnowledgeArticleImagesRelationManager.php
  - app/Filament/Resources/KnowledgeArticleResource.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/HandlesImageUploads.php
  - app/Filament/Resources/KnowledgeArticleResource/Concerns/ImageUploadForm.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
  - app/Filament/Resources/KnowledgeArticleResource/Pages/ViewArticleVersion.php
  - app/Http/Controllers/KnowledgeAttachmentController.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - composer.json
  - config/kokpit.php
  - database/migrations/2026_11_27_000100_create_knowledge_article_images_tables.php
  - lang/cs/knowledge.php
  - lang/cs/kokpit.php
  - resources/css/kokpit-prose.css
  - resources/js/knowledge-editor.js
  - routes/console.php
  - zerops.yml
findings:
  critical: 0
  warning: 2
  info: 6
  total: 8
status: issues_found
---

# Phase 20: Code Review Report

**Reviewed:** 2026-11-27
**Depth:** standard
**Files Reviewed:** 52
**Status:** issues_found

## Summary

The image feature is carefully built. I found no BLOCKER (no security hole, no data-loss path that is reachable in
normal operation). The areas the brief asked about held up under tracing:

- **Image route authorisation** (`KnowledgeAttachmentController`): the Partner is refused with 403 before any lookup
  (`KokpitPolicy::before` returns null for a Partner, `AdminOnlyPolicy::viewAny` denies). The uuid shape is checked,
  the lookup is by lowercased id, a pending image is served only to its uploader, and a bound image follows the owning
  article through the policy (archived included). The 304 branch comes after authorisation. The route sits inside
  `authenticatedRoutes`, so the Filament auth and two-factor middleware run first. The stored (re-encoded) MIME type,
  `nosniff`, a sandbox CSP, `Content-Disposition: inline` without a file name and `Vary: Cookie` are all set. I checked
  Laravel's `FilesystemAdapter::response()` and it honours a caller-supplied `Content-Disposition`.
- **SVG / XSS**: only bytes GD encoded itself are stored, the format is decided by magic bytes, SVG is refused, and
  the renderer admits an `img` only for a `kokpit-attachment:<uuid>` that the per-render `ImageContext` lists. The
  sanitiser's `InternalImageSrcSanitizer` is a second, strict layer (`\D`-anchored pattern). Raw HTML stays escaped.
- **Path handling**: the object key is built from the id and the mime type only.
- **Locking**: lock order is consistent (article row, then image rows in id order, then reference rows) across
  `SaveArticle`/`BindArticleImages`, `DeleteImageForGood` and `PruneImages`. I traced the prune-versus-save races
  (pending bind, orphan stamp/clear, delete recheck) and found them safe.
- **Migration / trigger**: the composite foreign keys, the CHECKs and the jsonb-based freeze trigger are correct
  (bind NULL to an article and the orphan stamp are the only allowed updates, a bound `article_id` never changes).
  `composer.lock` was updated together with `ext-gd`.
- **JS editor script**: no unsafe sink. Server replies are used as notification text or as a CodeMirror insertion only.
- **GD pipeline**: the animation walkers, the Exif orientation parser and the orientation transforms are bounded and
  correct. A GIF whose logical screen is smaller than its image descriptor does not bypass the pixel cap in the GD
  build I tested (GD 2.3.3 rejects it).

What remains are two robustness problems in the deletion paths and six minor items.

## Warnings

### WR-01: One failing object delete aborts the whole daily cleanup (and a non-throwing disk leaks the object)

**File:** `app/Domain/Knowledge/Actions/PruneImages.php:74-78, 99-101, 141-145, 176-177`
**Issue:** `deletePending()` and `deleteOrphan()` call `Storage::disk()->delete()` and ignore its return value. The
production disk (`config/filesystems.php`, `s3`) has `'throw' => true`, so a storage failure surfaces as an exception.
That exception propagates out of the `DB::transaction` closure, out of the `chunkById` callback and out of `run()`.
Consequences:

1. One image whose object cannot be deleted (permission, a bad key, a transient storage outage that outlasts the run)
   makes the job fail every day at the same row, because `chunkById` reaches it first in id order. Passes 2 to 4 never
   run (`clearStamps`, `stampOrphans` and `deleteOrphans` follow `prunePending` in `run()`), and every other expired
   image stays on the bucket until the one stuck item is fixed. The class docblock (lines 38-39) promises the opposite:
   "an image whose object could not be deleted keeps its row and is retried by the next run".
2. On a disk configured with `throw => false` (the `local` and `public` disks), `delete()` returns `false`, the code
   carries on and `$image->delete()` removes the row. The object is then leaked with no row left to retry from.
   `DeleteImageForGood::deleteObject()` already handles both forms (exception and `false`). `PruneImages` does not.

**Fix:** Isolate each image and make a failed delete a per-item event, not a run-ending one. Run all four passes, then
report once.

```php
private function deletePending(string $id): bool
{
    return DB::transaction(function () use ($id): bool {
        $image = KnowledgeArticleImage::query()->whereKey($id)->whereNull('article_id')->lockForUpdate()->first();

        if ($image === null) {
            return false;
        }

        if (! $this->deleteObject($image->storagePath())) {
            return false; // row stays, next run retries
        }

        $image->delete();

        return true;
    });
}

private function deleteObject(string $path): bool
{
    try {
        return Storage::disk()->delete($path) !== false;
    } catch (Throwable) {
        return false;
    }
}
```

Count the failures in the result array and let `PruneKnowledgeImages::handle()` throw (or `report()`) after `run()`
returns, so the failure is visible without blocking the other passes.

### WR-02: `DeleteImageForGood` removes the object before the activity row, which breaks its own invariant

**File:** `app/Domain/Knowledge/Actions/DeleteImageForGood.php:93-105` (docblock claim at 34-38)
**Issue:** The docblock says the only residual is a failing commit. The code order is reference rows, image row,
**object**, then `activity()...->log()`. A failure of the activity insert (a database error, a property or JSON problem
in the activity layer) happens after the irreversible object delete. The surrounding transaction rolls the rows back,
but the object is already gone. The result is the state the design says must not occur: the image row and every
reference row exist, the object does not, the Obrázky thumbnail and the article image are broken, the route answers 404,
and the Admin sees "failed". A retry does complete (a missing object deletes cleanly), so it is recoverable, but the
window is wider than documented.
**Fix:** Make the object delete the last statement before commit, so that only the commit itself remains as residual.

```php
KnowledgeArticleVersionImage::query()->toBase()->where('image_id', $stored->getKey())->delete();
KnowledgeArticleImage::query()->whereKey($stored->getKey())->toBase()->delete();

activity()
    ->useLog($locked->getMorphClass())
    ->performedOn($locked)
    ->causedBy($actor)
    ->event('image_deleted')
    ->withProperties(['reason_recorded' => true])
    ->log('image_deleted');

$this->deleteObject($stored->storagePath()); // last: a failure here still rolls everything back
```

## Info

### IN-01: `DeleteImageForGood` "not found" is not handled by the table action

**File:** `app/Filament/RelationManagers/KnowledgeArticleImagesRelationManager.php:180-202`
**Issue:** `DeleteImageForGood::handle()` throws `ModelNotFoundException` (a pending image, or `firstOrFail()` inside the
transaction when the row is already gone, for example a double submit or a second tab). The action catches only
`ValidationException` and `ArticleWriteFailed`, so the Admin gets a generic 404 or error modal instead of a message.
**Fix:** Catch `ModelNotFoundException`, show the "already deleted" success or info notification, and `halt()`/refresh
the table.

### IN-02: An object with no row has no sweeper

**File:** `app/Domain/Knowledge/Actions/StoreArticleImage.php:74-85`
**Issue:** The object is written first and the row second. `removeObject()` runs only for a `PDOException`. Any other
failure of `save()` (for example the `LogicException` of `extension()`), or a process that dies between `put()` and
`save()`, leaves an object that no row names. `PruneImages` works from rows only, so nothing ever collects it. For a
feature whose threat model is a leaked secret in a screenshot, an unreferenced copy that nothing cleans up is worth
closing.
**Fix:** Either widen the catch to `Throwable` (remove the object, rethrow), or invert the order: insert the pending
row first inside a transaction, `put()` the object, and let the existing 24-hour pending sweep remove a row whose
object never arrived.

### IN-03: A crafted file name can inject a `kokpit-attachment:<uuid>` token into the alt text

**File:** `app/Domain/Knowledge/Images/ImageNames.php:35-37` with `app/Domain/Knowledge/Support/ImageReferences.php:27-40`
**Issue:** `altText()` strips `[ ] ( ) \` and control characters only. A file named like
`kokpit-attachment:<uuid-of-another-image>.png` produces the alt `kokpit-attachment:<uuid>`, which is prefilled into
the editor. `ImageReferences::in()` matches the scheme anywhere in the body, so `BindArticleImages::lockReferenced()`
then sees a second reference. If that uuid belongs to another article's image, the save is refused with
`foreign_reference` until the Admin notices and edits the alt text. If it names an image of this article, it keeps that
image referenced. This is not an access problem (the renderer still lists only the article's own images), only a
confusing refusal driven by an attacker-controlled file name.
**Fix:** In `altText()`, remove the scheme token (for example
`str_ireplace(ImageReferences::SCHEME, '', $text)`) before the length cut.

### IN-04: A reference to a foreign image inside a code block refuses the save

**File:** `app/Domain/Knowledge/Actions/BindArticleImages.php:52-72`
**Issue:** The docblocks (lines 35-36 here, and in `ImageReferences`) justify the superset parse because a false
"referenced" "only keeps an image a little longer". That reasoning holds for retention but not for the refusal in
`lockReferenced()`: a uuid inside a fenced code block or inline code that happens to name an image of another article
(for example an article that documents the reference syntax and quotes a real id) rejects the whole save with a `body`
error even though nothing would render.
**Fix:** Keep the superset for retention (the reference index) but decide the refusal on references the renderer would
show, or at least name the offending id class in the message. If the owner accepts the strict behaviour, state it in
the docblock instead of the "safe direction" argument.

### IN-05: The upload queue has no timeout; a hung request leaves the submit buttons disabled

**File:** `resources/js/knowledge-editor.js` (`uploadOne`, `enqueue`, `applyLocks`)
**Issue:** `wire.upload('imageUpload', file, stored, failed, function () {})` resolves the queue only through the
success or error callback. If neither fires (a stalled connection that never errors, or a Livewire request that never
settles), `finish()` never runs, `releaseLocks()` is never called and the Save buttons stay disabled while the Admin
holds an unsaved draft. A page reload is the only way out and it loses the draft.
**Fix:** Add a watchdog per file (for example 120 seconds) that resolves the promise as a failure, shows the notification
and lets the queue continue.

### IN-06: The memory preflight does not model what GD allocates

**File:** `app/Domain/Knowledge/Images/ImagePipeline.php:164-183, 247-281`
**Issue:** `guardPixels()` budgets the decoded source (4 bytes per pixel), the canvas and 16 MiB, measured against PHP's
`memory_limit`. Two gaps: the JPEG orientation step (`JpegOrientation::rotate()`, orientations 3 and 5 to 8) allocates a
full second image while the original is still alive in `process()`, which the budget omits; and with an external libgd
(the GD of this host, and Alpine's `php85-gd`) GD allocates outside the Zend allocator. On this host, an 8 Mpx JPEG with
orientation 6 went through `process()` with PHP's reported peak at 2 MiB, so the `memory_limit` figure bounds nothing
there. The effective protection is `MAX_PIXELS` (24 Mpx, roughly 100 to 220 MB of GD memory) and the container's memory,
and that already holds. This is not exploitable beyond that cap, but the comment and the check imply a guarantee they do
not give.
**Fix:** Add `+ $pixels * 4` to `$needed` for the rotating orientations, and say in the docblock that the preflight is
advisory with an external GD, or size `MAX_PIXELS` against the container memory instead.

---

_Reviewed: 2026-11-27_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
