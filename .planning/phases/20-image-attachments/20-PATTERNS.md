# Phase 20: Image Attachments - Pattern Map

**Mapped:** 2026-11-27
**Files analyzed:** 28 new/modified (grouped below)
**Analogs found:** 26 / 28 (all analog paths verified git-tracked)

Paths are relative to the repository root. Line numbers refer to the files as read on the mapping date.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `database/migrations/2026_11_27_*_create_knowledge_article_images.php` (images + version_images tables, frozen trigger) | migration | CRUD | `database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php` (+ `2026_11_25_000200_create_knowledge_article_versions_table.php`) | exact |
| `app/Domain/Knowledge/Models/KnowledgeArticleImage.php`, `KnowledgeArticleVersionImage.php` | model | CRUD | `app/Domain/Knowledge/Models/KnowledgeVersionRedaction.php` | exact |
| `app/Domain/Knowledge/Images/ImagePipeline.php`, `ProcessedImage.php`, `ImageRefusal.php`, `ImageRefused.php`, `GifFrames.php`, `JpegOrientation.php` | utility | transform | `app/Domain/Knowledge/Support/MarkdownNormaliser.php` (pure string-to-string) | role-match; GD code from RESEARCH Patterns 1-4 |
| `app/Domain/Knowledge/Actions/StoreArticleImage.php` | service (Action) | file-I/O | `app/Domain/Knowledge/Actions/SaveArticle.php` (gate + transaction) + `PruneExpiredDocumentArchives` (disk use) | role-match |
| `app/Domain/Knowledge/Actions/DeleteImageForGood.php` | service (Action) | CRUD | `app/Domain/Knowledge/Actions/RedactVersion.php` | exact |
| `app/Domain/Knowledge/Actions/SaveArticle.php` (modify: bind + index) | service | CRUD | itself (`writeVersion`, `ValidationException::withMessages`) | exact |
| `app/Domain/Knowledge/Actions/RedactVersion.php` (modify: resync references) | service | CRUD | itself (lines 140-170) | exact |
| `app/Domain/Knowledge/Jobs/PruneKnowledgeImages.php` (+ `Actions/PruneKnowledgeImages` logic) | job | batch | `app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php` | exact |
| `routes/console.php` (modify: schedule) | config | batch | `routes/console.php` lines 87-100 | exact |
| `app/Http/Controllers/KnowledgeAttachmentController.php` | controller | request-response (stream) | `app/Http/Controllers/DocumentFileController.php` | exact |
| `app/Providers/Filament/AdminPanelProvider.php` (modify: route) | route/config | request-response | itself, `authenticatedRoutes` lines 91-104 | exact |
| `app/Domain/Knowledge/Support/LinkAndImagePolicy.php` (modify) | utility | transform | itself | exact |
| `app/Domain/Knowledge/Support/KnowledgeMarkdown.php` (modify: context + sanitiser) | utility | transform | itself | exact |
| `app/Domain/Knowledge/Support/ImageContext.php`, `ImageReferences.php`, `InternalImageSrcSanitizer.php`, missing-image node + renderer | utility | transform | `Support/TaskListMarkerRenderer.php`, `Support/CalloutRenderer.php` | role-match |
| `app/Domain/Knowledge/Queries/ArticleImages.php` | query | CRUD (read) | existing `app/Domain/Knowledge/Queries/*` | role-match |
| `app/Filament/Resources/KnowledgeArticleResource/Concerns/HandlesImageUploads.php` | hook/trait (Livewire) | request-response | `app/Filament/Concerns/ManagesDocuments.php` (upload trait for a Filament page/manager) | role-match |
| `app/Filament/RelationManagers/KnowledgeArticleImagesRelationManager.php` | component | CRUD | `app/Filament/RelationManagers/TaskDocumentsRelationManager.php` (+ `ManagesDocuments` trait, `ActivityHistoryRelationManager`) | exact |
| `app/Filament/Resources/KnowledgeArticleResource.php` (modify: relations, form button/notice) | component | CRUD | itself (`getRelations()` line 618) | exact |
| `resources/js/knowledge-editor.js` (modify) | component (JS) | event-driven | itself (task-list button) | exact |
| `resources/css/kokpit-prose.css` (modify) | config (CSS) | n/a | itself | exact |
| `lang/cs/knowledge.php`, `lang/cs/kokpit.php` (modify) | config | n/a | existing `knowledge.redact.*`, `'version_redacted'` | exact |
| `app/Console/.../kokpit:deploy:verify`, `composer.json`, `zerops.yml` (GD check) | config | request-response | existing `check()` list of the deploy-verify command | role-match |
| `tests/Support/ImageFixtures.php` | test util | transform | `tests/Support/*` helpers | role-match |
| Tests (Unit pipeline, strip, Feature route/save/prune/delete, Concurrency) | test | n/a | `tests/Concurrency/KnowledgeSaveConcurrencyTest.php`, `tests/Unit/Knowledge/KnowledgeMarkdownTest.php` | exact |
| Declarations: `tests/Support/PartnerMatrix.php`, `RouteRules.php`, `CanaryRegistry.php`, `ChannelMatrix.php`, `tests/Arch/ModelDeclarationTest.php`, `JobContractTest.php`, `KnowledgeSeamTest.php`, `tests/Isolation/RouteWalkTest.php` | test config | n/a | the `KnowledgeVersionRedaction` entries in the same files | exact |
| `app/Providers/AccessServiceProvider.php`, `app/Domain/Shared/Database/MorphMap.php` | config | n/a | entries for `KnowledgeVersionRedaction` | exact |

## Pattern Assignments

### Migration: images and version-images tables (migration, CRUD)

**Analog:** `database/migrations/2026_11_26_000100_add_knowledge_organisation_and_redaction.php`

**Table + named constraints through `DB::statement`** (lines 69-84):
```php
Schema::create('knowledge_version_redactions', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->uuid('version_id');
    $table->uuid('actor_id');
    $table->text('scope');
    $table->smallInteger('reason_length');
    $table->timestampTz('created_at')->useCurrent();
    $table->index('version_id', 'knowledge_version_redactions_version_id_index');
});

DB::statement('ALTER TABLE knowledge_version_redactions ADD CONSTRAINT knowledge_version_redactions_version_fk FOREIGN KEY (version_id) REFERENCES knowledge_article_versions (id) ON DELETE RESTRICT');
DB::statement("ALTER TABLE knowledge_version_redactions ADD CONSTRAINT knowledge_version_redactions_scope_check CHECK (scope IN ('lines', 'whole'))");
DB::statement('ALTER TABLE knowledge_version_redactions ADD CONSTRAINT knowledge_version_redactions_reason_length_check CHECK (reason_length BETWEEN 1 AND 500)');
```
Apply: columns and CHECKs per RESEARCH Pattern 6; write `size_bytes` check as `10 * 1024 * 1024` (sensitive-data checker). The composite FK `(version_id, article_id) REFERENCES knowledge_article_versions (id, article_id)` needs a new `UNIQUE (id, article_id)` on `knowledge_article_versions` (an `ALTER TABLE` in this migration; the versions table is frozen by trigger, a constraint addition does not touch rows).

**Trigger pattern** (lines 56-66 and the later `CREATE FUNCTION kokpit_refuse_knowledge_redaction_change()` block, line 90 onward): `plpgsql` function raising `USING ERRCODE = 'KP001'`. Copy for the immutable columns (`mime_type`, `width`, `height`, `size_bytes`, `sha256`) and non-null `article_id` of the images table; the reference table stays mutable (only `RedactVersion` and `DeleteImageForGood` delete from it). Also copy the documented `down()` guard style (refuse while rows exist) and the header comment style (decision ids, what changes, limits).

---

### `KnowledgeArticleImage.php`, `KnowledgeArticleVersionImage.php` (model, CRUD)

**Analog:** `app/Domain/Knowledge/Models/KnowledgeVersionRedaction.php`

**Whole-file pattern** (lines 1-70): `final class ... extends KokpitModel implements PartnerIsolated`, `use DeniesPartners;`, `public const UPDATED_AT = null;` (image rows have `created_at` only; `orphaned_at` is a plain cast), `protected $table`, nothing fillable (rows written with `forceFill` by Actions), `@property` docblock, `casts()` returning `'immutable_datetime'`, `BelongsTo` relations with `@return BelongsTo<X, $this>`.
```php
final class KnowledgeVersionRedaction extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;
    public const UPDATED_AT = null;
    protected $table = 'knowledge_version_redactions';
    public function version(): BelongsTo { return $this->belongsTo(KnowledgeArticleVersion::class, 'version_id'); }
    protected function casts(): array { return ['created_at' => 'immutable_datetime']; }
}
```
Add an `storagePath()` method (`knowledge/images/<uuid>.<ext>`, pure function of id + mime) in the style of `DocumentArchive::storagePath()` (referenced by `PruneExpiredDocumentArchives`). The version-images model has a composite primary key (`version_id`, `image_id`); mark `$incrementing = false` and avoid `HasUuids` for it.

Declarations required in the same PR (each lists `KnowledgeVersionRedaction` today; copy the entry and add both new models): `tests/Support/PartnerMatrix.php:522` (`adminOnlyModels()`), `tests/Support/ChannelMatrix.php:183`, `tests/Support/CanaryRegistry.php:37,167,619`, `tests/Isolation/CanaryRegistryTest.php:139`, `tests/Arch/ModelDeclarationTest.php:90,158`, `tests/Arch/KnowledgeSeamTest.php:274` (writer registry), plus `AccessServiceProvider` (`Gate::policy(..., AdminOnlyPolicy::class)`) and `MorphMap`.

---

### `Images/ImagePipeline.php` and helpers (utility, transform)

**Analog:** `app/Domain/Knowledge/Support/MarkdownNormaliser.php` (pure, injectable, unit-testable without the app: `final class`, `declare(strict_types=1)`, no facades). There is no GD code in the repository; the code to copy is RESEARCH Patterns 1-4 verbatim (header gate with `getimagesizefromstring`, magic-byte sniff, `gifFrameCount` walker, re-encode recipe). Hard rules from RESEARCH: never call `imagedestroy()` (deprecated in 8.5, becomes an exception), no `imagescale()`, wrap decode in `try { } catch (\ErrorException)`, orientation applied before encode, no `@` suppression. `ImageRefused` carries only the `ImageRefusal` enum, never content (matches the project rule of no content in exceptions, see `SaveArticle` docblock lines 62-64).

---

### `Actions/DeleteImageForGood.php` (service, CRUD)

**Analog:** `app/Domain/Knowledge/Actions/RedactVersion.php`

**Reason validation, to be extracted and shared, not copied** (lines 194-218):
```php
private function reasonLength(mixed $reason): int
{
    if (is_string($reason) && str_contains($reason, "\0")) {
        throw ValidationException::withMessages(['reason' => __('knowledge.errors.nul_character')]);
    }
    $trimmed = is_string($reason) ? trim($reason) : '';
    if ($trimmed === '') {
        throw ValidationException::withMessages(['reason' => __('knowledge.redact.errors.reason_required')]);
    }
    $length = mb_strlen($trimmed);
    if ($length > self::MAX_REASON_LENGTH) {
        throw ValidationException::withMessages(['reason' => __('knowledge.redact.errors.reason_too_long', ['max' => self::MAX_REASON_LENGTH])]);
    }
    return $length;
}
```
(Extract into a small shared helper in `Support/` or `Actions/` and call it from both Actions; images use `knowledge.images.delete.errors.*` keys.)

**Transaction + DB failure mapping** (lines 96, 143-148, 178-180): `DB::transaction(...)` wrapped in `try { } catch (PDOException $e) { throw new ArticleWriteFailed((string) $e->getCode()); }`.

**Activity row, metadata only** (lines 164-175):
```php
activity()
    ->useLog($locked->getMorphClass())
    ->performedOn($locked)
    ->causedBy($actor)
    ->event('version_redacted')
    ->withProperties([... 'reason_recorded' => true])
    ->log('version_redacted');
```
Apply: event `image_deleted`, properties `['reason_recorded' => true]` only (no file name, no reason). Add the label next to `'version_redacted' => 'Verze redigována'` in `lang/cs/kokpit.php`. Order inside the transaction: lock image row, delete reference rows, delete S3 object, delete row, activity.

---

### `SaveArticle.php` modification: bind pending images, index references (service, CRUD)

**Analog:** the file itself.

**Insertion points:** `create()` after `$this->writeVersion($article, $actor);` (line ~123) and the content-changing branch of `update()` after its `writeVersion` call; the helper itself belongs next to `writeVersion` (lines 400-410):
```php
private function writeVersion(KnowledgeArticle $article, User $author, ?int $restoredFromVersionNo = null): void
{
    (new KnowledgeArticleVersion)->forceFill([
        'article_id' => $article->getKey(),
        'version_no' => $article->version_no,
        'title' => $article->title,
        'body_md' => $article->body_md,
        'author_id' => $author->getKey(),
        'restored_from_version_no' => $restoredFromVersionNo,
    ])->save();
}
```
`writeVersion` returns void; either make it return the saved version or fetch the version id for the reference rows.

**Refusal pattern** (lines 234-242):
```php
throw ValidationException::withMessages(['folder_id' => __('knowledge.links.errors.folder_missing')]);
```
Apply: `['body' => __('knowledge.images.errors.foreign_reference')]` inside the open transaction so the rollback writes nothing. Unknown UUIDs are skipped (D-18, Pitfall 6). Update the class docblock sentence "The order is fixed and is never changed" (lines 36-37) because a bind step is appended. `inTransaction()` (lines 419-426) already maps `PDOException` to `ArticleWriteFailed`.

---

### `RedactVersion.php` modification: resync reference rows (service, CRUD)

**Analog:** the file itself. After the `KnowledgeArticleVersion::...->update(['body_md' => $new])` (line ~153) and before `set_config(... 'off')` (line 162) or just after it, inside the same `DB::transaction`, delete reference rows of `$version` whose UUID no longer occurs in `$new` (use `ImageReferences::in($new)`). Existing seam registry in `tests/Arch/KnowledgeSeamTest.php` lists reviewed writers of `body_md`; add the reference-table writers there.

---

### `Jobs/PruneKnowledgeImages.php` (job, batch)

**Analog:** `app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php` (lines 1-46)
```php
#[Idempotent(how: 'deletes the id-derived object and the row ...; a second run finds nothing to delete and a missing object is skipped')]
final class PruneExpiredDocumentArchives extends KokpitJob
{
    private const int CHUNK = 100;

    public function handle(): void
    {
        $disk = Storage::disk();
        DocumentArchive::query()
            ->where('expires_at', '<', now())
            ->chunkById(self::CHUNK, static function ($archives) use ($disk): void {
                foreach ($archives as $archive) {
                    // Object first: a record whose object could not be deleted stays and is retried by the next run.
                    $disk->delete($archive->storagePath());
                    $archive->delete();
                }
            });
    }
}
```
Imports: `App\Domain\Operations\Jobs\Idempotent`, `App\Domain\Operations\Jobs\KokpitJob`, `Illuminate\Support\Facades\Storage`. Apply: object first, then row; two passes (stamp `orphaned_at`, delete after 7 days with `lockForUpdate` recheck) per RESEARCH Pattern 10. Put the logic in an Action class so it is unit-testable. Docblock states the 7-8 day effective delay.

**Schedule** (`routes/console.php` lines 87-100):
```php
Schedule::job(new PruneExpiredDocumentArchives)
    ->daily()
    ->name('kokpit-document-archive-prune')
    ->onOneServer();
```
Add with name `kokpit-knowledge-image-prune`, plus a comment block in the same style. Append the class to `EXPECTED_APPLICATION_JOBS` in `tests/Arch/JobContractTest.php`.

---

### `KnowledgeAttachmentController.php` (controller, request-response stream)

**Analog:** `app/Http/Controllers/DocumentFileController.php`

**Imports** (lines 5-17): `Illuminate\Filesystem\FilesystemAdapter`, `Illuminate\Http\Request`, `Illuminate\Support\Facades\Gate`, `Illuminate\Support\Facades\Storage`, `Illuminate\Support\Str`, `Symfony\Component\HttpFoundation\StreamedResponse`.

**Check order and stream** (lines 37-75):
```php
public function __invoke(Request $request, PartnerContext $context, string $document): StreamedResponse
{
    $user = $request->user();
    abort_unless($user !== null && ..., 403);
    abort_unless(Str::isUuid($document), 404);
    $record = Document::query()->whereKey($document)->first();
    abort_if($record === null, 404);
    Gate::authorize('view', $record);
    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk($media->disk);
    abort_unless($disk->exists($path), 404);
    return $disk->response($path, null, [
        'Content-Type' => ..., 'Content-Length' => (string) $record->size_bytes,
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'private, no-store',
    ]);
}
```
Apply: Partner/non-Admin `abort(403)` first, `Str::isUuid` 404, load image, authorise `view` on the owning article (`withTrashed()`), pending image only for `uploaded_by`, then `If-None-Match` 304 (only after auth), then the D-12 header set (RESEARCH Pattern 9: `private, max-age=300`, ETag = sha256, CSP `default-src 'none'; sandbox`, `Content-Disposition: inline` without a file name). Differences from the analog: no `signed` middleware, no `u` query check, `Cache-Control` is not `no-store`. Keep the long class docblock explaining the fixed check order.

**Route** (`AdminPanelProvider.php` lines 91-98):
```php
->authenticatedRoutes(function (): void {
    Route::get('/files/documents/{document}', DocumentFileController::class)
        ->middleware([SetNoReferrerPolicy::class, 'signed', 'throttle:document-files'])
        ->name('files.document');
```
Add `Route::get('/knowledge/attachments/{attachment}', KnowledgeAttachmentController::class)->middleware([SetNoReferrerPolicy::class, 'throttle:knowledge-attachments'])->name('knowledge.attachment')`. Panel path is `admin`, so the URL is `/admin/knowledge/attachments/{uuid}` and the sanitiser regex must match that. Declare: a rate limiter (where `document-files` is defined, grep `RateLimiter::for('document-files'`), `tests/Support/RouteRules.php`, `tests/Support/PartnerMatrix.php`, and a branch in `tests/Isolation/RouteWalkTest.php::walkedUrl()` (lines 100-130 show the per-parameter `if ($parameter === ...)` chain; it throws on unknown parameters).

---

### `LinkAndImagePolicy.php` and `KnowledgeMarkdown.php` (utility, transform)

**Analog:** the files themselves.

**Current image handling to replace** (`LinkAndImagePolicy.php` lines 36-38):
```php
foreach ($images as $image) {
    $image->replaceWith(new Text($this->altText($image)));
}
```
New behaviour: if `$image->getUrl()` matches `kokpit-attachment:<uuid>` and the uuid is in the per-render `ImageContext`, `setUrl(<relative route>)` and `$image->data->set('attributes', [...width,height,loading,decoding])`; otherwise replace with the missing-image inline node (not `HtmlInline`, escaped by `html_input => 'escape'`) or plain alt text for external URLs. The route string is passed in, not computed in `Support/` (arch seam). Instance carries the context for one `render()` (set, convert, clear in `finally`); registered at `KnowledgeMarkdown.php` line 60 `$environment->addEventListener(DocumentParsedEvent::class, new LinkAndImagePolicy);` so keep a reference to that instance.

**Renderer signature** (`KnowledgeMarkdown.php` line 71): `render(string $markdown, ?ImageContext $images = null)`. Early returns at lines 73-81 stay.

**Sanitiser config** (lines 93-108): change `->allowMediaSchemes([])` / `->allowRelativeMedias(false)` (lines 97-98) to `->allowRelativeMedias(true)`, add `->allowElement('img', ['src','alt','width','height','loading','decoding'])` and `->withAttributeSanitizer(new InternalImageSrcSanitizer)`. Keep `->withMaxInputLength(-1)`.

**Custom node renderers to copy for the missing-image span:** `Support/TaskListMarkerRenderer.php` (37 lines, registered at `KnowledgeMarkdown.php` line 65 with `$environment->addRenderer(TaskListItemMarker::class, new TaskListMarkerRenderer($translate), 10);`) and `Support/CalloutRenderer.php`. Labels go through the injected `$translate` closure with a new `render.image.missing` key; `KnowledgeLangTest` pins render keys and must be extended.

---

### `KnowledgeArticleImagesRelationManager.php` (component, CRUD)

**Analog:** `app/Filament/RelationManagers/TaskDocumentsRelationManager.php` (lines 1-28) and `KnowledgeArticleHistoryRelationManager.php`

**Whole pattern:**
```php
#[AccessRule(Audience::AdminOnly, reason: 'The Admin manages every task file; ...')]
final class TaskDocumentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    use ManagesDocuments;

    protected static string $relationship = 'documents';
}
```
Imports: `App\Domain\Shared\Auth\AccessRule`, `App\Domain\Shared\Auth\Audience`, `App\Filament\Concerns\EnforcesRelationManagerAccessRule`, `Filament\Resources\RelationManagers\RelationManager`. The attribute must sit on the concrete class (attributes are not inherited, see `ActivityHistoryRelationManager` docblock). Table structure (read-only manager, `->headerActions([])`, `->toolbarActions([])`, `->emptyStateHeading(...)`, `->defaultSort(...)`) copy from `ActivityHistoryRelationManager::table()`; the single `recordActions` entry is `Action::make('delete')` with `requiresConfirmation()` and a Textarea reason form, modelled on the Phase 19 redaction modal (find its Filament action in the knowledge view page; reuse `knowledge.redact.*` copy structure). Register in `KnowledgeArticleResource::getRelations()` (line ~618, next to `KnowledgeArticleHistoryRelationManager::class`); add to `PartnerMatrix::panelClasses()`. ImageColumn must be fed an absolute `route()` URL, never the disk path (RESEARCH anti-patterns).

---

### `HandlesImageUploads` trait + `knowledge-editor.js` (hook / component, event-driven)

**Analog (JS):** `resources/js/knowledge-editor.js`
- Idempotent bind guard (lines 25-29): `if (window.kokpitKnowledgeEditorBound) { return } window.kokpitKnowledgeEditorBound = true`.
- Field marker selector `'.fi-fo-markdown-editor[data-kokpit-task-list]'` (line 31); label travels in a data attribute, no Czech text in JS. Add `data-kokpit-image-*` attributes the same way `KnowledgeArticleResource::form()` sets `data-kokpit-task-list` via `extraAttributes` (grep that call).
- Editor access (lines 105-109): `root._editor` of the `[x-data]` ancestor, then `editor.codemirror`.
- Button creation (lines 111-130): `createElement('button')`, `type='button'`, `tabIndex=-1`, `title` and `aria-label`, click handler with `preventDefault()`.
- CSS-mask icon via constructed stylesheet (lines 38-69), mutation observer on `document.documentElement` (header comment lines 16-22).
- Edits via `codemirror.operation(function () { ... replaceRange ... })` (lines 69-100) so one undo step.
Add `codemirror.on('paste' | 'drop', ...)`, `$wire.upload('imageUpload', file, ok, err, progress)` then `$wire.storeImageUpload()`, notifications through `new FilamentNotification().title(t).body(b).danger().send()`.

**Analog (PHP side):** `app/Filament/Concerns/ManagesDocuments.php` (a trait that owns uploads/actions for a Filament component) and the page-level error mapping that already turns `ValidationException` into `data.*` field errors (`RethrowsDomainValidation.php`, `withFormErrors` in the knowledge pages). Keep `->fileAttachments(false)` on the body field (RESEARCH Pattern 5). The trait exposes a `#[Renderless]` method that runs `StoreArticleImage` and deletes the Livewire temp file in `finally`. Livewire temp upload config: `config/livewire.php` and the README upload section (pinned by `UploadConfigTest`).

---

### Lang files (config)

**Analog:** `lang/cs/knowledge.php` keys `knowledge.redact.*`, `knowledge.links.errors.*`, `knowledge.render.*`; `lang/cs/kokpit.php` `'version_redacted' => 'Verze redigována'`. Add a `knowledge.images` array (copy table in `20-UI-SPEC.md` Copywriting Contract), `render.image.missing`, and the `image_deleted` activity label. `tests/Feature/Knowledge/KnowledgeLangTest.php` must be extended.

---

### Tests

- Concurrency: `tests/Concurrency/KnowledgeSaveConcurrencyTest.php` + `tests/Concurrency/knowledge-save-worker.php` as the pattern for `KnowledgeImageCleanupConcurrencyTest` (worker script per process).
- Renderer: extend `tests/Unit/Knowledge/KnowledgeMarkdownTest.php`.
- Schema: extend `tests/Feature/Schema/KnowledgeTablesTest.php` (insert helper at line ~113 shows the raw `DB::table(...)->insert([...])` style; triggers asserted at `KnowledgeRestoreRedactConcurrencyTest.php:108`).
- Fixtures: new `tests/Support/ImageFixtures.php`, assembled at runtime from fragments (RESEARCH Code Examples), names like `screenshot-example.png`.

## Shared Patterns

### Admin-only, Partner fail-closed
**Source:** `app/Filament/RelationManagers/KnowledgeArticleHistoryRelationManager.php` (attribute) and `app/Domain/Knowledge/Models/KnowledgeVersionRedaction.php` (`DeniesPartners` + `PartnerIsolated`).
**Apply to:** both models, the relation manager, the controller, the upload trait, every declaration file listed above.
```php
#[AccessRule(Audience::AdminOnly, reason: '...')]
final class ... {}
```

### Activity log holds metadata only
**Source:** `RedactVersion.php` lines 164-175.
**Apply to:** `DeleteImageForGood` (and nowhere else: upload and bind rows are not logged by the Actions).

### Database failure surfaces only as `ArticleWriteFailed`
**Source:** `SaveArticle::inTransaction()` lines 419-426 and `RedactVersion.php` lines 178-180.
**Apply to:** `DeleteImageForGood`, `BindArticleImages` (if a separate class), `StoreArticleImage` row insert.

### Czech text only in lang files, code and tests English
**Source:** `lang/cs/knowledge.php`; `__('knowledge....')` calls in Actions (e.g. `SaveArticle.php` line 234).
**Apply to:** all new messages; JS receives labels through data attributes or the server reply.

### Object first, then row; id-derived object path
**Source:** `PruneExpiredDocumentArchives.php` lines 34-45 (`$disk->delete($archive->storagePath())` then `$archive->delete()`), `Storage::disk()` (default private disk, no disk name hard-coded).
**Apply to:** `PruneKnowledgeImages`, `DeleteImageForGood`, `StoreArticleImage`.

### Immutability by trigger with KP001
**Source:** migration `2026_11_26_000100...` lines 56-66 (`RAISE EXCEPTION ... USING ERRCODE = 'KP001'`).
**Apply to:** images table immutable columns.

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `Images/ImagePipeline.php`, `GifFrames.php`, `JpegOrientation.php` | utility | transform | No GD or binary-format code exists in the repository; use RESEARCH Patterns 1-4 (probe-verified). Only the class shape follows `MarkdownNormaliser`. |
| Clipboard paste / drop JS and `$wire.upload` | component (JS) | event-driven | The existing script only adds a toolbar button; no upload JS exists. RESEARCH Pattern 5 plus the end-of-phase human check (no JS test tooling). |
| `InternalImageSrcSanitizer` | utility | transform | No `AttributeSanitizerInterface` implementation exists yet; code in RESEARCH Code Examples. |
| GD capability check in `kokpit:deploy:verify` | config | request-response | Find the command (grep `deploy_verify`) and append to its existing `check()` list; the GD probe itself is new. |

## Metadata

**Analog search scope:** `app/Domain/Knowledge`, `app/Domain/Documents/Jobs`, `app/Http/Controllers`, `app/Filament/RelationManagers`, `app/Filament/Concerns`, `app/Providers/Filament`, `routes/console.php`, `database/migrations`, `resources/js`, `tests/Support`, `tests/Arch`, `tests/Isolation`, `tests/Concurrency`, `tests/Unit/Knowledge`.
**Tracked-source gate:** `git ls-files` confirmed for the controller, job, `SaveArticle`, relation manager, migration, model, JS and `routes/console.php` analogs; no `.gsd` mirror paths used.
**Pattern extraction date:** 2026-11-27
