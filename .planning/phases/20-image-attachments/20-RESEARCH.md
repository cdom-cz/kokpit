# Phase 20: Image Attachments - Research

**Researched:** 2026-11-26
**Domain:** private image upload, re-encode (EXIF strip), authorised streaming, reference index and orphan cleanup inside the existing Laravel 13 / Filament 5 / Livewire 4 knowledge module
**Confidence:** HIGH for the codebase seams and GD behaviour (read and probed this session); MEDIUM for the deploy target (Zerops GD/WebP not provable from here)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Three ways to add an image: a toolbar button (file picker), paste from the clipboard (screenshots) and drag and drop onto the editor. The image is inserted at the cursor as `![alt](kokpit-attachment:<uuid>)`. This lifts the "no image button" limit of Phase 18 D-07 for this one button only; the built-in EasyMDE preview stays off.
- **D-02:** An image is uploaded immediately and stored as *pending* (no article binding yet). `SaveArticle` binds the pending images whose UUIDs appear in the body to the article inside its save transaction. This works for a new, not yet saved article. Pending images that never get bound are removed by the cleanup job after 24 hours.
- **D-03:** The reference is `kokpit-attachment:<uuid>`. Alt text is optional: it is prefilled with the file name and the Admin can change or clear it. No alt-text requirement.
- **D-04:** An image belongs to exactly one article (`article_id`). A reference to an image of another article, or to an unknown UUID, is not rendered as an image (alt text only) and `SaveArticle` refuses a body that references a foreign image. No cross-article reuse.
- **D-05:** At upload the UI shows a one-line notice that screenshots may contain secrets (they cannot be scanned), next to the existing sensitive flag.
- **D-06:** Accepted types are PNG, JPEG, WebP and static GIF. Maximum upload size 10 MB. The longer side is scaled down to at most 2400 px during re-encode. A pixel-count limit is checked before decoding to defuse decompression bombs.
- **D-07:** The type is decided from the file content (magic bytes), not from the extension or the client MIME type, and the file must decode successfully. SVG, a non-image renamed to an image extension, and everything else is refused with a Czech message.
- **D-08:** Every accepted image is re-encoded and only the re-encoded output is stored; the original is never kept. The format is preserved (PNG stays PNG, JPEG stays JPEG, WebP stays WebP, GIF stays GIF). Re-encoding drops EXIF and all other metadata, proven with a fictional test photo carrying a GPS position.
- **D-09:** An animated GIF (more than one frame) is refused with a message. A single-frame GIF is accepted.
- **D-10:** Images are streamed through the application: an authorised route (`/knowledge/attachments/{uuid}`) behind the panel authentication and the policy of the owning article loads the object from the private S3 disk and returns it. No bucket URL, no temporary URL and no redirect to the bucket ever appears in a page or response. Guest and a Partner without access are refused (fail closed, declared in the Partner matrix and route rules in the same pull request).
- **D-11:** No signed URL is used. The stale-link criterion is proven as: a copied or stored URL requested without a valid session, or after logout, is refused, and no bucket URL occurs anywhere in the HTML.
- **D-12:** Response headers: `Cache-Control: private` with a short max-age (5 minutes) and an ETag for revalidation, `X-Content-Type-Options: nosniff`, `Content-Type` from the stored type, and `Content-Security-Policy: default-src 'none'; sandbox` on the image response only.
- **D-13:** The renderer resolves `kokpit-attachment:<uuid>` to an `<img>` that points at the authorised route, only for images that belong to the article being rendered. External image URLs stay plain alt text. The sanitiser allows `img` only with the internal route as `src`. Preview, view screen, version view, diff and restore all use the same renderer. **Amendment (UI phase, 2026-11-26):** the preview of an unsaved article may also show pending images uploaded by the current Admin that are not yet bound to any article, so a new article shows its images before the first save. Every other surface keeps the strict rule.
- **D-14:** Authorisation follows the article, not the version: an image is served as long as it exists and the viewer may open its article. A stored version only references the UUID, so viewing or restoring an earlier version shows its images as long as they still exist.
- **D-15:** No page-wide Content-Security-Policy is introduced in this phase (the application sends none today and a policy for Filament and Livewire is separate, risky work).
- **D-16:** `SaveArticle` records in the same transaction which images the saved body and the new version reference, in a reference table keyed by version and attachment (index of references written at save time). Cleanup works from SQL over that table and never parses Markdown.
- **D-17:** An image is an orphan when neither the current body nor any stored version references it. A scheduled job deletes an orphan 7 days after it became one (the S3 object and the row); an image still referenced by any stored version always survives. Pending images are deleted after 24 hours.
- **D-18:** Restoring a version that references an image that has been deleted renders that reference as alt text and the restore still succeeds; the restore flow shows no error for it.
- **D-19:** The leaked-secret case: a manual Admin action deletes an image for good, immediately, with a required reason. It removes the S3 object and the row, so all references in the body and in every version render as alt text. The activity log records who, which article and that a reason exists, never the reason text. This is the image counterpart of the Phase 19 version redaction.

### Claude's Discretion
- Image library choice (GD is present with WebP; Imagick is present in DDEV but not guaranteed on the deploy target), the exact table and column names, S3 key layout, the JavaScript for the toolbar button, paste and drop, the Czech wording, the job schedule time, and how the pixel-count limit is expressed.

### Deferred Ideas (OUT OF SCOPE)
- Page-wide Content-Security-Policy for Filament and Livewire (separate hardening work).
- Animated GIF support (needs a re-encode path that is safe on the deploy target).
- Sharing images with clients (Phase 23).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| IMG-01 | Only images (PNG, JPEG, WebP, GIF) can be attached; SVG is rejected | Upload pipeline (magic-byte sniff, `getimagesizefromstring` gate, animated checks, GD decode as the "must decode" proof), refusal enum mapped 1:1 to the UI-SPEC copy table, editor JS (button, paste, drop) |
| IMG-02 | Images are stored privately, re-encoded without EXIF and delivered only through an authorised route | GD re-encode proven to drop EXIF (probe), orientation applied before the strip, `Storage::disk()` private s3 disk, streaming controller inside `authenticatedRoutes`, header set of D-12, sanitiser `img[src]` allow-rule, Partner/route-walk declarations |
| IMG-03 | Orphaned images are cleaned up, respecting references from stored versions | Image table + version-reference table with composite FKs, `SaveArticle` bind step under row locks, `RedactVersion` reference resync, daily `KokpitJob`, delete-for-good action, concurrency backstop |
</phase_requirements>

## Summary

No new Composer package is needed. PHP GD (with WebP, GIF, PNG, JPEG) is present on the host, in DDEV and in CI (`extensions: intl, bcmath, pdo_pgsql, gd, exif, ...` in `.github/workflows/hygiene.yml`), so the whole pipeline is built directly on GD functions. Imagick is not used (DDEV only). The pipeline is a pure `string -> string` function: sniff magic bytes, read dimensions with `getimagesizefromstring` (header only, so a 30000x30000 PNG header is rejected without decoding), detect animation by container structure (GD silently reads only the first frame of GIF and APNG, which I proved), decode with GD, apply the JPEG EXIF orientation (GD ignores it, so it has to be applied before the strip), downscale with `imagecopyresampled`, encode in the same format. Every probe in this document ran this session.

The most important finding for planning: **Filament's own MarkdownEditor attachment flow cannot be used** even with hooks. The bundled EasyMDE inserts `![](url)` only when the URL ends in an image extension; otherwise it inserts `[name](url)` (a link). `kokpit-attachment:<uuid>` has no extension, so the result would be a link, not an image. The component's file attachments are also always `public` and its exposed server method would store publicly. Therefore keep `->fileAttachments(false)` (fail closed) and follow the pattern already used for the task-list button: the script `resources/js/knowledge-editor.js` adds its own toolbar button, a CodeMirror `paste` and `drop` handler, uploads through a Livewire temporary upload (the existing private-bucket path with its CORS and 1-day expiry rules) into a dedicated page property, calls one `#[Renderless]` page method that runs the pipeline, deletes the temporary object, and inserts the reference through the CodeMirror API.

The second finding that shapes the data model: versions are immutable and never deleted, and a saved image is bound in the same transaction that writes a version referencing it. So under D-16/D-17 an image becomes an orphan in practice in only two ways: it stayed *pending* (never bound), or **every** version that referenced it was **redacted** (Phase 19). That means `RedactVersion` must resync the reference rows of the version it rewrites, otherwise the index lies and the success-criterion-3 deletion path can never trigger for bound images.

**Primary recommendation:** Build `Domain/Knowledge/Images/*` (pure GD pipeline + Actions), two Admin-only tables (`knowledge_article_images`, `knowledge_article_version_images` with composite FKs that make "same article" a database fact), extend `SaveArticle`/`RedactVersion` inside their transactions, extend `KnowledgeMarkdown` with an image context (still the only renderer), serve through one controller in the panel's `authenticatedRoutes`, and clean up with one daily `KokpitJob`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| File picker, paste, drop, progress, alt selection, secret notice | Browser / Client (`knowledge-editor.js`) | Frontend Server (Livewire page) | Only the browser sees the clipboard/drop; the page method is the single server entry |
| Validation, sniffing, decode, EXIF strip, resize, re-encode | API / Backend (Domain Action) | — | Must never trust the client; pure PHP/GD, no UI dependency |
| Object storage | Database / Storage (private `s3` disk via `Storage::disk()`) | — | Private bucket, no URLs ever leave the app |
| Authorised delivery | API / Backend (panel route + controller + policy) | Browser (plain `<img>`) | Streams bytes; the policy follows the owning article (D-14) |
| Markdown reference to `<img>` | API / Backend (`KnowledgeMarkdown`, the one renderer) | — | Arch seam: only `Support/` may render or sanitise knowledge text |
| Binding pending images, reference index | Database / Storage (transaction in `SaveArticle`) | — | Same transaction as the version row; DB FKs are the backstop |
| Orphan cleanup, pending expiry | Scheduler + queue (`KokpitJob`) | Database | Existing prune-job pattern; SQL over the reference table, no Markdown parsing |
| Delete for good | Frontend Server (Filament relation manager action) | API / Backend (Action) | Admin UI, the Action owns the transaction and the activity row |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP GD (ext-gd) | GD 2.3.3, PHP 8.5.13 in DDEV; WebP, GIF, PNG, JPEG all `=> 1` [VERIFIED: `ddev exec php -r gd_info()` this session] | decode, resize, re-encode | Already on host, DDEV and CI; no new dependency; no ImageMagick policy/CVE surface |
| `league/commonmark` | ^2.10 in `composer.json` | Markdown to HTML, image node hook | Already the renderer; the `Image` node accepts `data->set('attributes', ...)` [VERIFIED: probe this session] |
| `symfony/html-sanitizer` | v8.1.11 | second layer, `img[src]` allow-rule | Already the sanitiser; `AttributeSanitizerInterface` chain verified [VERIFIED: vendor read + probe] |
| Livewire temporary upload (s3) | Livewire 4.4.9, Filament 5.12.0 | browser to private bucket, no PHP body limits | Already configured (`config/livewire.php`), CORS and 1-day expiry already documented in `README.md` |
| `laravel/horizon` + `Schedule::job` | ^5.50 | cleanup job | Existing pattern (`PruneExpiredDocumentArchives`) |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `ext-exif` | present in CI and DDEV | not needed | Do **not** depend on it: write a 40-line JPEG APP1 orientation reader (smaller attack surface than `exif_read_data` on hostile input, identical behaviour on every environment). Only the *tests* may use `exif_read_data` to prove the strip |
| `finfo` (ext-fileinfo) | present (required by Laravel) | SVG detection for the message only | Never to accept a file: acceptance is by magic bytes + decode |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| GD directly | `spatie/image` 3.9.10 (already installed transitively by medialibrary) or `intervention/image` | Adds an abstraction that hides exactly the three things this phase must control (orientation, frame count, alpha); GD calls are few. Not recommended |
| GD | Imagick | Best colour/ICC handling, but only guaranteed in DDEV; deploy target unknown, ImageMagick policy files and delegates add attack surface. Revisit only if the deploy rehearsal shows GD lacks WebP |
| Livewire temp upload + page method | Own `POST` endpoint with `fetch` | Original never touches the bucket, simpler HTTP test; but needs `client_max_body_size` (the repo's `site.conf.tmpl` sets none, nginx default is 1 MB) and PHP `upload_max_filesize`/`post_max_size` on Zerops (unknown). Rejected: the repo already solved large uploads and CORS for the Livewire path |
| Filament `attachFiles` + hooks | — | Rejected, see Summary (inserts a link; public by default; exposed method) |

**Installation:** none for Composer. Add the platform requirement and the runtime package:
```bash
composer require ext-gd:*            # platform requirement only; Zerops build runs with --ignore-platform-reqs
# zerops.yml run.prepareCommands: add php85-gd to the existing `sudo apk add --no-cache supervisor php85-pcntl php85-posix icu-data-full` line [ASSUMED package name]
```
**Version verification:** no external package is added, so there is no registry version to verify. `gd_info()` of the DDEV web container is the verified capability proof; the deploy target is not verified (see Open Questions 1).

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| none | — | — | — | — | — | No external package is installed by this phase |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none
`spatie/image` 3.9.10 and `intervention/image` were considered and **not** chosen; the former is only a transitive dependency of `spatie/laravel-medialibrary` 11.23.14 [VERIFIED: `composer.lock:7294`, `composer.lock:7588`].

## Architecture Patterns

### System Architecture Diagram

```
 EDITOR (browser)                                     SERVER
 ─────────────────                                    ──────
 toolbar button / paste / drop
        │  File[]  (sequential queue)
        ▼
 knowledge-editor.js ── $wire.upload('imageUpload', file) ──► private S3 bucket  (livewire-tmp/, 1-day expiry rule)
        │                                                          │
        │  on finish: $wire.storeImageUpload()                     │ read bytes (<= 10 MB)
        ▼                                                          ▼
 Livewire page (Create/Edit) ── trait HandlesImageUploads ──► StoreArticleImage (Action)
        │                                                          │ gate: create image (Admin only)
        │                                                          ▼
        │                                              ImagePipeline::process(bytes)
        │                                              1 size cap ─► 2 magic bytes ─► 3 getimagesizefromstring (pixel cap + memory preflight)
        │                                              4 animated? (GIF walker / APNG acTL / WebP VP8X)
        │                                              5 GD decode ─► 6 JPEG orientation ─► 7 downscale 2400 ─► 8 encode same format
        │                                                          │ ProcessedImage(bytes, mime, w, h, sha256)
        │                                                          ▼
        │                                              Storage::disk()->put('knowledge/images/<uuid>.<ext>')  +  row (article_id NULL = pending)
        │                                              delete livewire-tmp object (finally)
        ◄──── {id, name, width, height} or {title, body} (Czech) ──┘
 CodeMirror insert  ![name](kokpit-attachment:<uuid>)   + secret notice unhidden

 SAVE            SaveArticle.create/update (one transaction, existing order kept)
                 … writeVersion ─► BindImages: lock image rows FOR SHARE/UPDATE ─► foreign? refuse (ValidationException body)
                                              pending of actor? set article_id ─► insert knowledge_article_version_images rows
 RENDER          KnowledgeMarkdown::render($md, ImageContext) ─► LinkAndImagePolicy:
                   internal ref resolves in context  ─► <img src="/admin/knowledge/attachments/<uuid>" width height loading decoding>
                   anything else                    ─► alt text (span.kokpit-image-missing) / external URL ─► plain alt text
                 sanitiser: img[src] kept only if it matches the internal route (custom AttributeSanitizer)
 SERVE           GET /admin/knowledge/attachments/{attachment}  (panel authenticatedRoutes: session + 2FA + Authenticate)
                   Partner ─► 403 ─► uuid shape ─► image row ─► policy(view article | pending: uploader) ─► If-None-Match 304 (after auth)
                   ─► stream from disk with D-12 headers   (guest ─► login redirect, never bytes)
 CLEANUP (daily KokpitJob)  pending > 24 h ─► delete object+row
                            unreferenced (no version row) ─► stamp orphaned_at; > 7 d and still unreferenced ─► lock, recheck, delete object+row
 DELETE FOR GOOD (relation manager action)  lock ─► delete reference rows ─► delete object ─► delete row ─► activity row (reason_recorded only)
```

### Recommended Project Structure
```
app/Domain/Knowledge/
├── Images/
│   ├── ImagePipeline.php          # pure string -> ProcessedImage, throws ImageRefused
│   ├── ProcessedImage.php         # readonly: bytes, mime, extension, width, height, sha256
│   ├── ImageRefusal.php           # enum -> lang keys (NotAnImage, Svg, TooLarge, TooManyPixels, Animated, Failed)
│   ├── ImageRefused.php           # exception carrying the enum, no content
│   ├── GifFrames.php              # block walker (proven below)
│   └── JpegOrientation.php        # APP1/TIFF orientation reader
├── Actions/
│   ├── StoreArticleImage.php      # gate, pipeline, put object, insert pending row
│   ├── BindArticleImages.php      # called by SaveArticle inside its transaction (or a private method there)
│   ├── DeleteImageForGood.php     # D-19
│   └── PruneKnowledgeImages.php   # logic behind the job (so it is unit-testable)
├── Jobs/PruneKnowledgeImages.php  # KokpitJob, #[Idempotent]
├── Models/KnowledgeArticleImage.php, KnowledgeArticleVersionImage.php
├── Queries/ArticleImages.php      # builds the ImageContext for a body (one query)
└── Support/ImageContext.php, ImageReferences.php, InternalImageSrcSanitizer.php, MissingImage*.php
app/Http/Controllers/KnowledgeAttachmentController.php
app/Filament/Resources/KnowledgeArticleResource/Concerns/HandlesImageUploads.php   # used by Create + Edit pages
app/Filament/RelationManagers/KnowledgeArticleImagesRelationManager.php
resources/js/knowledge-editor.js   # extended (existing file, existing asset registration)
```

### Pattern 1: Decode only after the header gate (decompression bomb)
**What:** `getimagesizefromstring` reads dimensions without decoding. Probe: a 33-byte PNG header claiming 30000 x 30000 returned `[30000,30000]` immediately [VERIFIED: probe]. Refuse on `width * height > MAX_PIXELS` **before** any `imagecreatefrom*`.
**Pixel limit expression (discretion):** `MAX_PIXELS = 24_000_000` plus a memory preflight, because GD holds 4 bytes per pixel for a truecolor image (the host `memory_limit` is `128M`, DDEV has `-1`): refuse when `width*height*4 + 16 MiB > memory_limit - memory_get_usage()` (skip when the limit is `-1`). 24 MP admits a 6K screenshot (about 20 MP) and every phone photo that fits in 10 MB. Both refusals use the "Too many pixels" copy. [ASSUMED: the 24 MP number is a recommendation, not a measured optimum]
**Example (verified pieces):**
```php
// Sources: probes in this session (gd.php, gif.php, webp.php); php.net GD manual for the functions.
$info = @getimagesizefromstring($bytes);               // false for SVG and for non-images
if ($info === false || $info[0] < 1 || $info[1] < 1) { throw new ImageRefused(self::classify($bytes)); }
if ($info[0] * $info[1] > self::MAX_PIXELS) { throw new ImageRefused(ImageRefusal::TooManyPixels); }
```

### Pattern 2: Magic bytes decide, the decoder confirms (D-07)
Sniff the first bytes only: PNG `\x89PNG\r\n\x1a\n`, JPEG `\xFF\xD8\xFF`, GIF `GIF87a|GIF89a`, WebP `RIFF....WEBP`. The type from `getimagesizefromstring` must agree with the sniffed type; then the GD decoder for **that** type is called (use `imagecreatefromstring` only after the sniff, so BMP/AVIF/XPM/TGA that GD also decodes are never reachable). An SVG is recognised for the message only (after optional BOM and whitespace: `<?xml`, `<svg`, `<!DOCTYPE svg`, or `finfo` returns `image/svg+xml`, which I confirmed it does for a bare `<svg .../>`). Everything else is "not an image". File name and client MIME are never read for the decision.

### Pattern 3: Animation is a structural check, because GD hides it
GD reads **only the first frame** of an animated GIF and decodes an APNG as a still. Both proven: a hand-built two-frame GIF and a PNG with an `acTL` chunk before `IDAT` both decoded without error [VERIFIED: probe `gif.php`]. So an animated file would silently become a still unless detected first.
```php
// GIF block walker, counts image descriptors, bounded by the 10 MB input. Verified: single=1, two-frame=2, truncated=null.
function gifFrameCount(string $d, int $limit = 2): ?int
{
    $n = strlen($d);
    if ($n < 13 || ! in_array(substr($d, 0, 6), ['GIF87a', 'GIF89a'], true)) { return null; }
    $flags = ord($d[10]); $p = 13;
    if ($flags & 0x80) { $p += 3 * (1 << (($flags & 7) + 1)); }      // global colour table
    $frames = 0;
    $skip = function () use ($d, $n, &$p): bool {                    // data sub-blocks
        while (true) { if ($p >= $n) { return false; } $len = ord($d[$p++]); if ($len === 0) { return true; } $p += $len; }
    };
    while ($p < $n) {
        $b = ord($d[$p++]);
        if ($b === 0x3B) { return $frames; }                          // trailer
        if ($b === 0x21) { $p++; if (! $skip()) { return null; } continue; }   // extension
        if ($b === 0x2C) {                                            // image descriptor
            if ($p + 9 > $n) { return null; }
            $lf = ord($d[$p + 8]); $p += 9;
            if ($lf & 0x80) { $p += 3 * (1 << (($lf & 7) + 1)); }    // local colour table
            $p++;                                                     // LZW minimum code size
            if (! $skip()) { return null; }
            if (++$frames >= $limit) { return $frames; }
            continue;
        }
        return null;                                                  // malformed
    }
    return null;
}
```
`null` (malformed) maps to "not a supported image"; `>= 2` maps to the Animated copy. **APNG** (`acTL` chunk before the first `IDAT`) and **animated WebP** (VP8X chunk, flag byte at file offset 20, bit `0x02`; probe confirms the bit position and that GD returns `false` on a header-only animated container) are not covered by D-09, which names GIF only. Recommendation: refuse both with the same Animated message, otherwise the stored file silently differs from the upload. This is a scope extension of D-09, listed in the Assumptions Log (A5) for owner confirmation.

### Pattern 4: Re-encode recipe (alpha, palette, orientation, resize)
Facts proven by probe: GD re-encode drops EXIF (an injected APP1 with Orientation 6 and a GPS IFD was readable by `exif_read_data` before; after re-encode there was no `Exif\0\0` marker and no GPS section), **does not** apply the Orientation (so a phone photo would end up rotated wrongly once the tag is gone), keeps PNG alpha only with `imagealphablending(false)` + `imagesavealpha(true)`, decodes palette PNGs as palette (needs `imagepalettetotruecolor` before resize), and `imagescale(..., IMG_BICUBIC)` returned `false` on an alpha WebP, while `imagecopyresampled` onto a fresh truecolor canvas preserved alpha (value 64 round-tripped).
```php
$src = imagecreatefromstring($bytes);                  // after the sniff, inside an ErrorException-tolerant try
if ($src === false) { throw new ImageRefused(ImageRefusal::NotAnImage); }
if (! imageistruecolor($src)) { imagepalettetotruecolor($src); }
$src = self::applyOrientation($src, $orientation);     // JPEG only; imagerotate with a transparent fill, or imageflip
[$w, $h] = [imagesx($src), imagesy($src)];
$scale = min(1.0, 2400 / max($w, $h));                  // downscale only, never upscale
$tw = max(1, (int) round($w * $scale)); $th = max(1, (int) round($h * $scale));
$dst = imagecreatetruecolor($tw, $th);
imagealphablending($dst, false); imagesavealpha($dst, true);
imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
ob_start();
match ($type) {
    'png'  => imagepng($dst, null, 9),
    'jpeg' => imagejpeg($dst, null, 85),                // JPEG has no alpha: flatten onto white first
    'webp' => imagewebp($dst, null, 85),
    'gif'  => imagegif($dst),
};
$out = (string) ob_get_clean();
```
**Do not call `imagedestroy()`:** it is deprecated since PHP 8.5 and emits `Deprecated: Function imagedestroy() is deprecated since 8.5, as it has no effect since PHP 8.0` [VERIFIED: probe in DDEV PHP 8.5.13]; with Laravel's error handler that becomes an exception. GD objects are freed by the garbage collector.
**Known, accepted tradeoffs (document them):** re-encoding JPEG is lossy; ICC profiles are dropped, so wide-gamut (Display P3) screenshots shift slightly toward sRGB values [ASSUMED]; GIF output is re-quantised when it had to be resized; 16-bit PNG becomes 8-bit. Re-read the output with `getimagesizefromstring` to store the *final* width and height, size and `sha256`.

### Pattern 5: Editor upload without Filament's attachment flow
Keep `->fileAttachments(false)` on the body field. Verified in `HasFileAttachments`: `saveUploadedFileAttachment()` starts with `if (! $this->hasFileAttachments()) { return null; }` and `saveUploadedFileAttachmentAndGetUrl()` then returns `null`, so the exposed method stores nothing. Verified in the minified `markdown-editor.js`: EasyMDE's `afterImageUploaded` inserts an image only when the URL's last extension is one of `["png","jpg","jpeg","gif","svg","apng","avif","webp"]`, otherwise `h[0]="["+T; ... p.insertTexts.link`, i.e. `[name](url)`. Hence an own flow.
- `knowledge-editor.js` already finds the EasyMDE instance through `root._editor` and adds the task-list button with `createElement`, a CSS-mask icon and a mutation observer; reuse that structure for the image button, label from a `data-kokpit-image-*` attribute set by `extraAttributes` (the same way `data-kokpit-task-list` is set in `KnowledgeArticleResource::form()`).
- Paste and drop: `editor.codemirror.on('paste', fn)` and `on('drop', fn)` (CodeMirror 5, which EasyMDE embeds; Filament's own handlers use exactly these events for `uploadImage`). Handle only when the event carries image files, `preventDefault()` them, and never swallow a plain text paste. [ASSUMED: CodeMirror event argument shape; confirm in the browser during the human-verify step]
- Upload: `$wire.upload('imageUpload', file, finish, error, progress)` (signature confirmed in the Livewire docs: success callback gets the temporary file name, progress is `event.detail.progress`, **no promise**, so call the server method from the success callback), then `$wire.storeImageUpload()` which returns a plain array through the Livewire call. One property, strictly serial queue (the README states Livewire refuses a list on one property on S3), which also satisfies the UI-SPEC "several files dropped at once upload one after the other".
- Errors: the server returns the Czech `title` and `body` of the refusal; the script shows it with `new FilamentNotification().title(t).body(b).danger().send()` [CITED: filamentphp.com/docs/5.x/notifications/overview; `window.FilamentNotification` exists in `vendor/filament/notifications/dist/index.js`]. No Czech text is hard-coded in JS; labels travel in data attributes.
- Default alt text is the file name without extension. **Sanitise it in the server reply** (strip `[ ] ( ) \`, control characters, newlines, bidi overrides, cap about 120 characters): a file name such as `a](x)` would otherwise break the Markdown the script inserts.
- Disable the submit button during an upload and re-assert it after every Livewire morph (a server re-render resets the `disabled` attribute). The secret notice must live in a `wire:ignore` wrapper that the script unhides, because a server-rendered `hidden` would come back on the next morph. [ASSUMED: Livewire morph behaviour for `hidden`/`disabled`; verify in the browser]
- There is no JS test tooling in the repository (no `package.json`), so this part is covered by PHP-side tests of everything it calls and by the end-of-phase human verification (`human_verify_mode: end-of-phase`).

### Pattern 6: Reference table with composite foreign keys (D-04 as a database fact)
```sql
-- knowledge_article_images
id uuid PRIMARY KEY DEFAULT uuidv7(),
article_id uuid NULL REFERENCES knowledge_articles(id) ON DELETE RESTRICT,   -- NULL = pending
uploaded_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
file_name text NOT NULL CHECK (char_length(file_name) BETWEEN 1 AND 255),    -- shown in the relation manager, never logged
mime_type text NOT NULL CHECK (mime_type IN ('image/png','image/jpeg','image/webp','image/gif')),
width int NOT NULL CHECK (width BETWEEN 1 AND 2400), height int NOT NULL CHECK (height BETWEEN 1 AND 2400),
size_bytes int NOT NULL CHECK (size_bytes BETWEEN 1 AND 10 * 1024 * 1024),   -- evaluates to 10 MiB, the D-06 cap (written as an expression for the sensitive-data checker)
sha256 char(64) NOT NULL,                                                    -- strong ETag
orphaned_at timestamptz NULL,
created_at timestamptz NOT NULL DEFAULT now(),
UNIQUE (id, article_id)                                                      -- target of the composite FK
-- object key is a pure function of id and mime_type (knowledge/images/<uuid>.<ext>), like DocumentArchive::storagePath()

-- knowledge_article_version_images
version_id uuid NOT NULL, image_id uuid NOT NULL, article_id uuid NOT NULL,
PRIMARY KEY (version_id, image_id),
FOREIGN KEY (version_id, article_id) REFERENCES knowledge_article_versions (id, article_id) ON DELETE RESTRICT,   -- needs UNIQUE (id, article_id) on versions
FOREIGN KEY (image_id, article_id)   REFERENCES knowledge_article_images   (id, article_id) ON DELETE RESTRICT
```
`article_id` on the reference row is therefore forced equal on both sides: a version of article A can never index an image of article B, whatever code writes it. Also add a trigger that refuses changing a non-null `article_id` and the immutable columns (`mime_type`, `width`, `height`, `size_bytes`, `sha256`), in the style of the existing `knowledge_article_versions_frozen` trigger. Follow the migration style of `2026_11_25_000200_create_knowledge_article_versions_table.php` (`$table->uuid('id')->primary()->default(DB::raw('uuidv7()'))`, named constraints through `DB::statement`). The reference rows stay mutable (only `RedactVersion` deletes from them), so they get no frozen trigger.

### Pattern 7: Bind and index inside `SaveArticle` (D-02, D-04, D-16)
After `writeVersion()` in both `create()` and the content-changing branch of `update()` (the no-op, classification-only and sensitivity-only branches write no version and need nothing):
1. `ImageReferences::in($body)`: a regex `~kokpit-attachment:([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})~`, lowercased, unique. Deliberately a **superset** of what the renderer displays (it also matches inside code blocks): a false "referenced" only keeps an image longer, a false "unreferenced" would delete a visible image. Say so in the class docblock.
2. Load those image rows with `lockForUpdate()` ordered by id (consistent lock order). Unknown UUIDs are skipped silently (D-18: a restored version may name a deleted image).
3. For each found row: `article_id === $article->id` is fine; `article_id === null` and `uploaded_by === $actor->id` is bound with an `UPDATE ... WHERE article_id IS NULL`; anything else (another article, or another user's pending image) throws `ValidationException::withMessages(['body' => __('knowledge.images.errors.foreign_reference')])`. The pages already map field errors under `data.*` through `withFormErrors`, and the transaction rolls everything back, so a refused save writes nothing. Place the check after the secret guard decision and before the writes so the fixed order of the existing steps is kept and only appended to; update the class docblock that says the order is never changed.
4. Insert one reference row per image for the new version.
The row locks serialise two saves that try to bind the same pending image to two articles (the second sees it bound elsewhere and is refused) and serialise the cleanup job's delete against a save.
**`RedactVersion` must resync:** after it rewrites a version's `body_md`, delete the reference rows of that version whose UUID no longer appears in the new body, inside its existing transaction, and (when the last reference of an image disappears) leave `orphaned_at` to the job. `KnowledgeSeamTest` lists reviewed writers of `body_md`, `version_no`, `redaction_no`; add the new reference-table writers there so the seam stays closed.

### Pattern 8: One renderer, per-call image context (D-13)
`KnowledgeMarkdown` is a singleton built once, and the arch seam allows only `app/Domain/Knowledge/Support/` to render or sanitise. Extend it: `render(string $markdown, ?ImageContext $images = null)`. `ImageContext` is a plain value object `uuid => {width, height}` of the images that may be shown, built **outside** the renderer by `Queries\ArticleImages::forBody(string $body, ?string $articleId, ?string $pendingForUserId)` with one `whereIn` query, so the renderer stays free of the application (its existing unit-test contract). Rules: view screen, version view, diff-adjacent views pass `articleId` only; the editor preview passes `articleId` (null for a new article) **and** the actor id so the D-13 amendment (own pending images) holds in the preview only.
- `LinkAndImagePolicy` keeps its job (external and unknown images become alt text) and gains the internal case: for a `kokpit-attachment:<uuid>` URL present in the context, `setUrl('/admin/knowledge/attachments/<uuid>')` (relative, built with `route(..., absolute: false)` and passed in, not computed in `Support/`) and `$image->data->set('attributes', ['width' => .., 'height' => .., 'loading' => 'lazy', 'decoding' => 'async'])`. Verified output: `<img width="10" height="5" loading="lazy" decoding="async" src="/admin/knowledge/attachments/<uuid>" alt="file name" />`. Make the policy instance carry the context for the duration of one `render()` (set, convert, clear in `finally`); the container is single-threaded per request.
- A missing image becomes `<span class="kokpit-image-missing">alt</span>`. Do **not** emit it as `HtmlInline`: with `html_input => 'escape'` that node is escaped. Use a small custom inline node plus renderer (the module already does this for `TaskListItemMarker`, callouts, code), and add the fallback text as a `render.image.missing` key (the provider maps keys to `knowledge.render.*`; `KnowledgeLangTest` pins the render keys, extend it).
- Sanitiser: `img` is already a safe element and its `width`, `height`, `loading`, `decoding`, `alt` survive; **`src` is dropped by the current config** (`->allowMediaSchemes([])`, `->allowRelativeMedias(false)`) [VERIFIED: probe, default output `<img alt="x" width="10" .../>` without src]. Change to `->allowRelativeMedias(true)` and register a `InternalImageSrcSanitizer implements AttributeSanitizerInterface` for `img`/`src` that returns the value only when it matches `#^/admin/knowledge/attachments/[0-9a-f]{8}-...-[0-9a-f]{12}$#` (else `null`). Verified: the custom sanitiser keeps the internal path and drops `https://evil.example/x.png`, `//evil.example/a.png` and a path with a query string. The chain is sequential per attribute (`DomVisitor` applies every matching sanitiser and stops on `null`), so the URL sanitiser can never re-admit what the custom one dropped.

### Pattern 9: The delivery route and controller
Register inside the existing `->authenticatedRoutes(function (): void { ... })` of `AdminPanelProvider` (read: it already holds `files.document` etc. with `[SetNoReferrerPolicy::class, 'signed', 'throttle:document-files']`). The new route has **no** `signed` middleware (D-11) but reuse `SetNoReferrerPolicy` and add a throttle limiter of its own. The URL is therefore `/admin/knowledge/attachments/{attachment}` (the panel path is `admin`; CONTEXT.md writes it without the prefix). Order, copying `DocumentFileController`: Partner or no Admin access `abort(403)` first (the article scope would otherwise answer 404, the same reason `ViewKnowledgeArticle::mount` aborts 403 first), `Str::isUuid` else 404, load the image (system read is not needed: Admin), authorise `view` on the owning article loaded `withTrashed()` (an archived article stays viewable), or for a pending image require `uploaded_by === user`, then conditional 304, then stream.
```php
return $disk->response($path, null, [
    'Content-Type' => $image->mime_type,                    // stored type, never sniffed
    'Content-Length' => (string) $image->size_bytes,
    'Cache-Control' => 'private, max-age=300',
    'ETag' => '"'.$image->sha256.'"',
    'X-Content-Type-Options' => 'nosniff',
    'Content-Security-Policy' => "default-src 'none'; sandbox",
    'Content-Disposition' => 'inline',                      // no filename: names can carry client names
    'Vary' => 'Cookie',
]);
```
Never answer 304 before the authorisation step. A guest hitting the URL gets the panel's login redirect, never bytes (assert the response is not an image). The route needs a branch in `tests/Isolation/RouteWalkTest.php::walkedUrl()`, which throws on any parameter it does not know (`"The route walk does not know the parameter [...]"`). `RouteAuditTest` needs nothing for an authenticated route (Filament `Authenticate` counts).

### Pattern 10: Cleanup job and delete for good
- `Jobs/PruneKnowledgeImages extends KokpitJob`, `#[Idempotent(how: '...')]`, scheduled `->daily()->name('kokpit-knowledge-image-prune')->onOneServer()` in `routes/console.php`; the class must be appended to `EXPECTED_APPLICATION_JOBS` in `tests/Arch/JobContractTest.php` and `ScheduleOnOneServerTest` already enforces the schedule shape. Pattern copied from `PruneExpiredDocumentArchives` (chunkById, object first).
- Pending: `article_id IS NULL AND created_at < now() - interval '24 hours'`.
- Orphans: images with `article_id IS NOT NULL` and no row in `knowledge_article_version_images`. Pass 1 stamps `orphaned_at` where null and clears it where a reference exists again (a restore can re-reference an image during the grace period). Pass 2 deletes where `orphaned_at < now() - interval '7 days'`: inside a transaction, `lockForUpdate` the row, **recheck** no reference exists, delete the object, then the row. The `RESTRICT` composite FK is the last line of defence against a racing save (the save holds the row lock, and a reference insert would fail or the delete would). A missing object is not an error (S3 delete is idempotent).
- Invariant to test: an image referenced by the *current* body always has a row for the *latest* version, so "no version references it" is the whole orphan test and the job needs no Markdown parsing (D-16). The UI-SPEC badges use the same table: "V aktuálním textu" is a reference from the version whose `version_no` equals the article's.
- `DeleteImageForGood`: Admin gate (`update` on the article), reason validated like `RedactVersion::reasonLength` (required after trim, NUL refused, max 500; extract that helper instead of copying), one transaction: lock the image row, delete its reference rows, delete the object, delete the row, one `activity()` row `event('image_deleted')` with `['reason_recorded' => true]` and **no** file name; add the label under `lang/cs/kokpit.php` next to `'version_redacted' => 'Verze redigována'`. The reason string is overwritten after use, as `RedactVersion` does.

### Anti-Patterns to Avoid
- **Enabling Filament `attachFiles` with hooks:** inserts a link, defaults to public, leaves an exposed storing method (see Pattern 5).
- **`imagedestroy()`:** deprecated in 8.5.
- **`imagescale()` for the resize:** returned `false` on an alpha WebP in the probe; use `imagecopyresampled`.
- **Trusting `UploadedFile::getMimeType()` or the extension:** both are client-influenced; the pipeline reads bytes.
- **Parsing Markdown in the cleanup job** (D-16) or only trusting the AST for retention (use the regex superset).
- **Emitting the missing-image span as `HtmlInline`** (escaped by `html_input => 'escape'`).
- **`ImageColumn` with a relative path:** `ImageColumn::getImageUrl` returns the state only when `filter_var($state, FILTER_VALIDATE_URL)` passes, otherwise it asks the disk for `temporaryUrl()`/`url()`, which would put a **bucket URL** into the table [VERIFIED: `vendor/filament/tables/src/Columns/ImageColumn.php:220-253`]. Feed it an absolute `route('...')` URL, and add a test that no table cell contains the bucket host.
- **Any `Storage::temporaryUrl`/`url` call for these objects.**

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Image decode/resize/encode | a pixel loop or a format parser | GD functions | Edge cases (palette, alpha, interlace) are handled by libgd; only orientation, frame count and header sniffing are custom, and those are small and tested |
| Browser to bucket upload, progress, CORS | an own multipart endpoint | Livewire temporary upload (`$wire.upload`) | Infra (CORS, 1-day expiry, 10 MB rule, throttle) already exists and is pinned by `UploadConfigTest` |
| Notification UI | custom toast markup | `FilamentNotification` (JS) | Already global on the page |
| Streaming a private object | manual `fpassthru` | `$disk->response($path, null, $headers)` | Existing pattern in `DocumentFileController` |
| HTML sanitising | regex over the output | `symfony/html-sanitizer` + one `AttributeSanitizerInterface` | The module's second layer; the arch seam forbids another sanitiser |
| Job retry/system-context contract | an own job base | `KokpitJob` + `#[Idempotent]` | `JobContractTest` enforces it |
| Number/UUID keys | custom id generation | `uuidv7()` column default + `HasUuids` via `KokpitModel` | Project rule: UUID v7 keys |

**Key insight:** the only code that must be written by hand is what the libraries deliberately do not do: apply the EXIF orientation, count frames of animated containers, and check the header before decoding. Everything else is an existing pattern in this repository.

## Runtime State Inventory

Not a rename/refactor/migration phase. Greenfield additions only. One adjacent item: `lang/cs/knowledge.php` gets a new `images` array and a `render.image.missing` key (parity pinned by `KnowledgeLangTest`).

## Common Pitfalls

### Pitfall 1: Orientation lost together with EXIF
**What goes wrong:** phone photos appear rotated 90 degrees after the strip.
**Why:** GD ignores the Orientation tag, then drops it. **Avoid:** read APP1 orientation first, rotate, then encode. **Warning sign:** a fixture with Orientation 6 and non-square pixels comes out with swapped dimensions only if the code applied it; assert the swapped width/height in the test.

### Pitfall 2: Animated content passes as a still
GIF, APNG and animated WebP all "decode" through GD as the first frame. Detect by structure before decoding (Pattern 3). Test with runtime-assembled two-frame GIF, `acTL` PNG and VP8X-animation WebP.

### Pitfall 3: The deploy target may lack GD or its WebP support
`zerops.yml` builds with `--ignore-platform-reqs`, so a missing extension would not stop the build; Zerops documents that `php-nginx` "bundles more extensions, though not all" [CITED: docs.zerops.io/guides/php-tuning], and lists `ext-gd` among those commonly missing from the *build* base. Add `php85-gd` to the run `prepareCommands` [ASSUMED package name], declare `ext-gd` in `composer.json`, and add a `gd` check to `kokpit:deploy:verify` that requires `gd_info()` to report `WebP Support`, `GIF Create Support`, `PNG Support`, `JPEG Support` all true (the command already has a `check()` list; it also needs `kokpit.deploy_verify.steps.gd` labels). That makes a missing capability fail the deploy instead of failing on the first upload.

### Pitfall 4: Version history makes orphans rare; redaction must update the index
See Summary. Without the `RedactVersion` resync the reference table would claim an image that no stored text mentions any more, and a leaked screenshot whose lines were redacted would never be cleaned except by the manual delete.

### Pitfall 5: A cached image outlives a delete or a logout
`Cache-Control: private, max-age=300` means a browser may show a cached copy for up to 5 minutes after a delete-for-good or logout, and the 304 path must authorise first. This is accepted by D-12; document it, and prove the server-side behaviour (a fresh request after logout or delete is refused or 404).

### Pitfall 6: The restore flow and foreign-reference refusal collide if "unknown" is refused
D-04 refuses foreign images; D-18 requires restore to succeed when an image was deleted. Refuse **only** a UUID that resolves to a row of another article (or another user's pending image); an unknown UUID is skipped. A test for each side is required.

### Pitfall 7: PHP warnings become exceptions
Laravel converts warnings to `ErrorException`. GD emits warnings on corrupt input (`gd-png: libpng warning`, `imagecreatefromstring(): Data is not in a recognized format`). Wrap decode in a `try { ... } catch (\ErrorException)` mapped to `NotAnImage`/`Failed`, and keep all fixtures free of `@`-suppressed deprecations (see `imagedestroy`).

### Pitfall 8: Livewire temporary file left behind
The original (with EXIF) sits in `livewire-tmp/` until the 1-day bucket expiry rule removes it. Call `$file->delete()` in a `finally` immediately after the pipeline, success or failure. The README states the bucket rule exists and "the deploy rehearsal checks" it, so no new infra, but state in the plan that a crash between upload and `finally` leaves an original for at most a day.

### Pitfall 9: Route walk and canary declarations are mandatory, not optional
Every touch-point below fails an existing audit test until declared. Listed in the Validation Architecture (Wave 0).

### Pitfall 10: Markdown injection through the file name
See Pattern 5: sanitise the default alt text on the server.

## Code Examples

### Sanitiser rule (verified by probe)
```php
// Source: probe san.php this session (symfony/html-sanitizer v8.1.11)
final class InternalImageSrcSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array { return ['img']; }
    public function getSupportedAttributes(): ?array { return ['src']; }
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return preg_match('#^/admin/knowledge/attachments/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#', $value) === 1 ? $value : null;
    }
}
// config additions: ->allowRelativeMedias(true)->allowElement('img', ['src','alt','width','height','loading','decoding'])->withAttributeSanitizer(new InternalImageSrcSanitizer)
```

### Fictional test fixtures assembled at runtime (hygiene rule)
```php
// Source: probe gd.php. JPEG from GD, then a hand-built APP1 (Orientation=6, GPS IFD) spliced after the SOI marker.
$jpeg = /* imagejpeg of a 40x20 red canvas */;
$tiff = 'II'.pack('vV', 42, 8);
$ifd0 = pack('v', 2).pack('vvVV', 0x0112, 3, 1, 6).pack('vvVV', 0x8825, 4, 1, 8 + 2 + 24 + 4).pack('V', 0);
$gps  = pack('v', 1).pack('vvV', 1, 2, 2)."N\0\0\0".pack('V', 0);
$app1 = "Exif\0\0".$tiff.$ifd0.$gps;
$withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
```
A format-independent leak test also injects a **sentinel** (a string built from fragments) into every container (JPEG COM and APP1, PNG `tEXt`/`eXIf`, GIF comment extension, WebP `EXIF` chunk) and asserts the sentinel is absent from the stored bytes, plus `exif_read_data` reports no `GPS`/`IFD0` section for the JPEG. The SVG fixture is a bare `<svg ...>` string; the "renamed non-image" is any text with a `.png` name; the oversized file is `str_repeat` past 10 MiB; the bomb is the 33-byte PNG header; the animated GIF is the single-frame GIF with its data block duplicated (verified: walker returns 2, GD still decodes it).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `imagedestroy($img)` after use | let GC free `GdImage` objects | PHP 8.0 no-op, deprecated 8.5 | Calling it fails under Laravel's error handler |
| Trust `exif_read_data`/`mime_content_type` | magic bytes + decode + re-encode | long-standing upload guidance | Re-encode is the only reliable metadata strip |
| Public Filament attachments | private disk + authorised stream | project decision (PITFALLS.md, Pitfall 1) | No bucket URL anywhere |

**Deprecated/outdated:** Filament `MarkdownEditor::fileAttachmentsVisibility()` throws (always public); `saveUploadedFileAttachmentsUsing` is a deprecated alias of `saveUploadedFileAttachmentUsing` [VERIFIED: `HasFileAttachments.php`].

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Zerops `alpine/php-nginx@8.5` ships GD with WebP, or `php85-gd` exists and includes WebP | Pitfall 3, Standard Stack | Upload of WebP (or all images) fails on the deploy target; the proposed deploy-verify check turns it into a failed deploy instead |
| A2 | libwebp/GD refuses a truly animated WebP (probe only proved a header-only container returns `false`) | Pattern 3 | An animated WebP might decode as a still; mitigated by the explicit VP8X check |
| A3 | 24 MP pixel cap and the memory formula are acceptable for the real production `memory_limit` (host shows 128M, DDEV -1, production unknown) | Pattern 1 | Too high a cap fails with a fatal out-of-memory instead of a clean refusal; the preflight reduces this |
| A4 | CodeMirror 5 `paste`/`drop` handlers and the `$wire` object reachable through the Alpine/Livewire root behave as in Filament's own code | Pattern 5 | JS rework; covered by the end-of-phase human check |
| A5 | Owner confirms that APNG and animated WebP are refused like animated GIF (D-09 names GIF only) | Pattern 3 | If the owner prefers "accept first frame", drop the two extra checks |
| A6 | ICC profile loss (Display P3 to sRGB values) is acceptable for screenshots | Pattern 4 | Slightly less saturated colours in stored images |
| A7 | `php85-gd` is the Alpine package name (the repo already uses `php85-pcntl`, `php85-posix`) | Installation | Wrong name fails `prepareCommands`; check in the first rehearsal |

## Open Questions

1. **Does the Zerops run image already provide GD with WebP?**
   - Known: the repo's CI and DDEV do; the Zerops docs only say the runtime bundles "more" extensions than the build base.
   - Unclear: the exact extension list of `php-nginx@8.5`.
   - Recommendation: add `php85-gd` explicitly, declare `ext-gd`, add the `kokpit:deploy:verify` GD check, and confirm in the deploy rehearsal. Imagick stays out.
2. **Animated APNG/WebP policy** (A5): recommend refusal with the Animated copy; needs a one-line owner confirmation before the plan locks the refusal enum.
3. **Table and column names** are discretionary; the names in Pattern 6 are a recommendation. The orphan timestamp (`orphaned_at`) is set by the job on first sight, so the effective delay is 7 to 8 days with a daily run; say so in the job docblock.
4. **Where the notice about reference text `![alt](kokpit-attachment:<uuid>)` in the version diff wraps** is claimed by UI-SPEC to be covered by `overflow-wrap: anywhere`; `kokpit-diff.css` has that rule at lines 76, 84, 155 (grep), but the executor should confirm it applies to the diff text cell with a 36-character UUID.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP GD (WebP, GIF, PNG, JPEG) | pipeline | yes (host, DDEV, CI list) | GD 2.3.3, PHP 8.5.13 in DDEV | none needed; deploy target unverified (see Pitfall 3) |
| ext-exif | tests only (proving the strip) | yes (host, DDEV, CI) | — | the production code does not need it |
| Imagick | not used | DDEV only | — | n/a |
| S3-compatible private bucket (RustFS) | storage, Livewire temp upload | yes in DDEV (`kokpit:storage:check`) | — | tests use the faked default disk (`FILESYSTEM_DISK=local` in `phpunit.xml`) |
| Horizon / scheduler | cleanup job | yes (DDEV daemons `queue-worker`, `scheduler`) | — | — |
| Postgres 18 (`uuidv7()`) | migrations | yes (DDEV `database.version: "18"`) | 18 | — |

**Missing dependencies with no fallback:** none confirmed. **Unverified:** GD with WebP on the Zerops runtime.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3 on PHPUnit 13.3, Larastan level 8, Pint |
| Config file | `phpunit.xml` (suites Unit, Arch, Feature, Isolation, Concurrency, Audit) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Knowledge/<File>Test.php` |
| Full suite command | `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` (not `--parallel`; project rule) and `ddev exec vendor/bin/pest --testsuite=Audit` |

Project rule: in a worktree write code and tests and run only cheap checks (Pint, static analysis where it works without the DB); migrations and the full run happen after the merge into the HEAD branch, and that result is the verdict.

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| IMG-01 | PNG, JPEG, WebP, single-frame GIF accepted; SVG, renamed non-image, over 10 MB, over pixel cap, animated GIF/APNG/WebP refused with the right enum | unit | `pest tests/Unit/Knowledge/ImagePipelineTest.php` | no, Wave 0 |
| IMG-01 | Livewire upload then `storeImageUpload()` returns id/name or the Czech title/body; nothing inserted on failure; Partner refused | feature | `pest tests/Feature/Knowledge/ImageUploadTest.php` | no, Wave 0 |
| IMG-01 | editor field keeps `fileAttachments(false)`; script and data attributes registered | feature | `pest tests/Feature/Knowledge/ImageEditorWiringTest.php` | no, Wave 0 |
| IMG-02 | stored bytes carry no EXIF/GPS/sentinel for every format; orientation applied; final dimensions <= 2400 | unit | `pest tests/Unit/Knowledge/ImageMetadataStripTest.php` | no, Wave 0 |
| IMG-02 | controller: guest refused (no bytes), Partner 403, admin 200 with the D-12 header set, foreign/unknown 404, 304 only after authorisation, no bucket host in any page or table cell | feature | `pest tests/Feature/Knowledge/KnowledgeAttachmentRouteTest.php` | no, Wave 0 |
| IMG-02 | renderer: internal ref becomes `<img>` with src/width/height/lazy only for context images; external URL and foreign/unknown stay alt text; sanitiser drops other `src` | unit | `pest tests/Unit/Knowledge/KnowledgeMarkdownTest.php` (extend) | yes (extend) |
| IMG-02 | route walk and canary: Partner never reaches an image of any client | isolation | `pest tests/Isolation/RouteWalkTest.php tests/Isolation/CanaryRegistryTest.php` | yes (extend) |
| IMG-03 | `SaveArticle` binds pending, indexes references per version, refuses a foreign image, ignores an unknown UUID, restore succeeds with a deleted image | feature | `pest tests/Feature/Knowledge/SaveArticleImagesTest.php` | no, Wave 0 |
| IMG-03 | `RedactVersion` resyncs references; image referenced by an older version survives; unreferenced image deleted after 7 days; pending after 24 h; re-reference during grace clears `orphaned_at` | feature | `pest tests/Feature/Knowledge/PruneKnowledgeImagesTest.php` | no, Wave 0 |
| IMG-03 | delete for good removes object, row, references; activity row has `reason_recorded` and no reason or file name | feature | `pest tests/Feature/Knowledge/DeleteImageForGoodTest.php` | no, Wave 0 |
| IMG-03 | cleanup vs concurrent save cannot leave a reference to a deleted image | concurrency | `pest tests/Concurrency/KnowledgeImageCleanupConcurrencyTest.php` | no, Wave 0 (pattern: `KnowledgeSaveConcurrencyTest` + worker script) |
| IMG-03 | schema: tables, composite FKs, checks, frozen triggers, key defaults | feature | `pest tests/Feature/Schema/KnowledgeTablesTest.php` (extend) | yes (extend) |

### Sampling Rate
- **Per task commit:** the test file of the task (cheap, in DDEV after merge; Pint in the worktree).
- **Per wave merge:** the Knowledge folders `tests/Unit/Knowledge`, `tests/Feature/Knowledge`, `tests/Arch`, `tests/Isolation`.
- **Phase gate:** full suite + Audit green before `/gsd-verify-work`; then the human check of the JS flow (paste, drop, button, progress, notice) in DDEV, because no JS test tooling exists.

### Wave 0 Gaps
- [ ] `tests/Support/ImageFixtures.php`: runtime builders (EXIF JPEG, sentinel injector per format, animated GIF/APNG/WebP, SVG, bomb header, oversized), helper prefix per Pest's single namespace convention
- [ ] New test files listed above
- [ ] Declarations that fail existing audits until added: `tests/Support/PartnerMatrix.php` (`adminOnlyModels()` for both models; `panelClasses()` for the new relation manager), `tests/Arch/ModelDeclarationTest.php`, `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` (`Gate::policy(..., AdminOnlyPolicy::class)` x2), `tests/Support/CanaryRegistry.php` + `tests/Isolation/CanaryRegistryTest.php`, `tests/Support/ChannelMatrix.php`, `tests/Isolation/RouteWalkTest.php::walkedUrl()`, `tests/Arch/JobContractTest.php` (`EXPECTED_APPLICATION_JOBS`), `tests/Arch/KnowledgeSeamTest.php` (writers), `tests/Feature/Repo/RepositoryFilesTest.php` (it names the Phase 19 model; check what it pins), `tests/Feature/Knowledge/KnowledgeLangTest.php`, `lang/cs/kokpit.php` event label. This list is the set of files that mention `KnowledgeVersionRedaction` today [VERIFIED: grep this session], so it is the proven footprint of adding a Knowledge model.
- [ ] Framework install: none.

## Security Domain

### Applicable ASVS Categories (level 1, `security_asvs_level: 1`)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (inherited) | panel `Authenticate` + 2FA middleware on the route; guest never gets bytes |
| V3 Session Management | yes (inherited) | no signed URL (D-11); access dies with the session |
| V4 Access Control | yes | policy of the owning article, Partner 403 first, pending image only for its uploader, composite FK for same-article references |
| V5 Input Validation | yes | magic bytes + decode + size/pixel caps; server-side sanitised file name; custom `img[src]` sanitiser |
| V7 Logging | yes | activity rows carry ids and `reason_recorded` only; no file name, no reason, no bytes in any log or exception |
| V8 Data Protection | yes | `Cache-Control: private`, no bucket URL in HTML, original never persisted, EXIF dropped |
| V12 Files and Resources | yes | upload size cap, content-based type, re-encode, store outside web root on a private bucket, `nosniff`, sandbox CSP, no user-controlled path (key derived from the UUID) |
| V6 Cryptography | no new use | sha256 is an ETag, not a security control |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SVG or HTML polyglot served from the app origin | Tampering / Elevation | SVG refused; only re-encoded raster stored; `Content-Type` from DB; `nosniff`; `CSP: default-src 'none'; sandbox` on the image response |
| Decompression bomb | Denial of service | header-only dimension gate before decode, pixel cap, memory preflight, 10 MB cap |
| EXIF/GPS and hidden metadata leak | Information disclosure | GD re-encode (probe-proven), sentinel test per format |
| Animated container smuggling extra frames | Tampering | structural frame/animation checks before decode |
| IDOR on `/knowledge/attachments/{uuid}` | Information disclosure | auth + policy, 403 for Partner before lookup, same refusal shape for unknown and foreign |
| Bucket URL leak | Information disclosure | `ImageColumn` fed an absolute app route; grep tests for the bucket host; no `temporaryUrl` use |
| Cross-article reference to read another article's image | Information disclosure | composite FK + refusal in `SaveArticle` + renderer context limited to the article |
| Markdown injection via file name | Tampering | server-side alt-text sanitising |
| Race between cleanup and save | Integrity | row locks + RESTRICT FKs + concurrency test |
| Cached copy after delete/logout | Information disclosure (residual) | accepted 5 min private cache (D-12), documented |

## Project Constraints (from CLAUDE.md)

- Fictional data only in code, tests, fixtures, planning docs and commit messages; secrets and EXIF coordinates in fixtures are assembled at runtime from fragments; use `example.com`, `12345678`, `/Users/example/`. Run `scripts/check-sensitive.sh` before commits; never bypass the hook.
- Everything under `.planning/` is written in English; never write Notion content into `.planning/`.
- Stack fixed: PHP 8.5, Laravel, Filament (SPA mode), PostgreSQL, S3-compatible **private** bucket, queue; AGPL-compatible dependencies only (no new package is added).
- Security: Partner never sees another client's data; enforced by Policies and global scopes, not only UI; declare every new route, page, relation manager, Livewire component and model in `PartnerMatrix`/`RouteRules` in the same pull request.
- UUID v7 keys everywhere; FK, unique and check constraints enforced in the database.
- Czech UI text only in `lang/cs/knowledge.php` (and `lang/cs/kokpit.php` for the activity label); code and tests in English; activity log stores metadata only.
- Worktrees: no migrations or full test runs inside a worktree; final verdict after the merge, in DDEV, with the CI-shaped command (no `--parallel`).
- Everything goes through the GSD workflow for edits.

## Sources

### Primary (HIGH confidence)
- Repository files read this session: `app/Domain/Knowledge/Support/KnowledgeMarkdown.php`, `LinkAndImagePolicy.php`, `MarkdownNormaliser.php`, `Actions/SaveArticle.php`, `RedactVersion.php`, `RestoreVersion.php`, `ArticleInput.php`, `Models/*`, `app/Filament/Resources/KnowledgeArticleResource.php` and pages, `app/Providers/Filament/AdminPanelProvider.php`, `app/Providers/KnowledgeServiceProvider.php`, `app/Http/Controllers/DocumentFileController.php`, `app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php`, `routes/console.php`, `config/filesystems.php`, `config/livewire.php`, `config/kokpit.php`, migrations `2026_11_25_000200` and `2026_11_26_000100`, `resources/js/knowledge-editor.js`, `tests/Support/RouteRules.php`, `PartnerMatrix.php`, `tests/Arch/KnowledgeSeamTest.php`, `JobContractTest.php`, `tests/Isolation/RouteWalkTest.php`, `README.md` and `CONTRIBUTING.md` upload sections, `zerops.yml`, `site.conf.tmpl`, `.github/workflows/hygiene.yml`
- Vendor reads: `filament/forms` `MarkdownEditor.php`, `HasFileAttachments.php`, `dist/components/markdown-editor.js` (EasyMDE insert logic); `filament/tables` `ImageColumn.php`; `symfony/html-sanitizer` `AttributeSanitizerInterface`, `UrlAttributeSanitizer`, `DomVisitor`
- Probes run this session (scratchpad): GD EXIF strip, orientation, palette/alpha, header-only bomb, finfo SVG, GIF frame walker, APNG, WebP alpha/animated flag, sanitiser and CommonMark image output; DDEV `gd_info()` and `imagedestroy` deprecation
- Livewire uploads docs (https://livewire.laravel.com/docs/uploads): `$wire.upload` signature, S3 temp cleanup
- Filament notifications docs (https://filamentphp.com/docs/5.x/notifications/overview): `FilamentNotification` from JavaScript

### Secondary (MEDIUM confidence)
- Zerops PHP tuning guide (https://docs.zerops.io/guides/php-tuning): runtime bundles more extensions than the build base, `apk add php8x-<ext>` pattern, `ext-gd` commonly missing from the build base

### Tertiary (LOW confidence)
- WebSearch for the Zerops `php-nginx@8.5` extension list found nothing authoritative; GD/WebP presence on the deploy target remains unverified (A1)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, GD capability proven in DDEV and CI list; deploy target MEDIUM
- Architecture: HIGH, every seam (renderer, SaveArticle, panel routes, job contract, audits) read in the repository
- Pitfalls: HIGH for the probed ones (orientation, animation, alpha, imagedestroy, ImageColumn, EasyMDE link insertion); MEDIUM for browser/Livewire morph behaviour (A4)

**Research date:** 2026-11-26
**Valid until:** 2026-12-26 (stable stack; re-check Filament/Livewire minor versions and the Zerops extension list before execution)
