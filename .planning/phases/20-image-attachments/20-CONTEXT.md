# Phase 20: Image Attachments - Context

**Gathered:** 2026-11-26
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin can put screenshots and other images into a knowledge article while no image becomes publicly reachable or carries hidden metadata (IMG-01, IMG-02, IMG-03).

Today the Phase 18 renderer turns every Markdown image into its alt text (`LinkAndImagePolicy`) and the editor has no image button (Phase 18 D-07). This phase adds the upload path, the re-encode pipeline, the internal image reference, the authorised delivery route and the orphan cleanup.

Out of this phase: other attachment types (Out of Scope in REQUIREMENTS.md), a shared image library across articles, animated GIFs, a page-wide Content-Security-Policy, client sharing of images (Phase 23), image search or OCR.

</domain>

<decisions>
## Implementation Decisions

### Inserting images in the editor (IMG-01)
- **D-01:** Three ways to add an image: a toolbar button (file picker), paste from the clipboard (screenshots) and drag and drop onto the editor. The image is inserted at the cursor as `![alt](kokpit-attachment:<uuid>)`. This lifts the "no image button" limit of Phase 18 D-07 for this one button only; the built-in EasyMDE preview stays off.
- **D-02:** An image is uploaded immediately and stored as *pending* (no article binding yet). `SaveArticle` binds the pending images whose UUIDs appear in the body to the article inside its save transaction. This works for a new, not yet saved article. Pending images that never get bound are removed by the cleanup job after 24 hours.
- **D-03:** The reference is `kokpit-attachment:<uuid>`. Alt text is optional: it is prefilled with the file name and the Admin can change or clear it. No alt-text requirement.
- **D-04:** An image belongs to exactly one article (`article_id`). A reference to an image of another article, or to an unknown UUID, is not rendered as an image (alt text only) and `SaveArticle` refuses a body that references a foreign image. No cross-article reuse.
- **D-05:** At upload the UI shows a one-line notice that screenshots may contain secrets (they cannot be scanned), next to the existing sensitive flag.

### Limits, format and validation (IMG-01, IMG-02)
- **D-06:** Accepted types are PNG, JPEG, WebP and static GIF. Maximum upload size 10 MB. The longer side is scaled down to at most 2400 px during re-encode. A pixel-count limit is checked before decoding to defuse decompression bombs.
- **D-07:** The type is decided from the file content (magic bytes), not from the extension or the client MIME type, and the file must decode successfully. SVG, a non-image renamed to an image extension, and everything else is refused with a Czech message.
- **D-08:** Every accepted image is re-encoded and only the re-encoded output is stored; the original is never kept. The format is preserved (PNG stays PNG, JPEG stays JPEG, WebP stays WebP, GIF stays GIF). Re-encoding drops EXIF and all other metadata, proven with a fictional test photo carrying a GPS position.
- **D-09:** An animated GIF (more than one frame) is refused with a message. A single-frame GIF is accepted.

### Delivery and display (IMG-02)
- **D-10:** Images are streamed through the application: an authorised route (`/knowledge/attachments/{uuid}`) behind the panel authentication and the policy of the owning article loads the object from the private S3 disk and returns it. No bucket URL, no temporary URL and no redirect to the bucket ever appears in a page or response. Guest and a Partner without access are refused (fail closed, declared in the Partner matrix and route rules in the same pull request).
- **D-11:** No signed URL is used. The stale-link criterion is proven as: a copied or stored URL requested without a valid session, or after logout, is refused, and no bucket URL occurs anywhere in the HTML.
- **D-12:** Response headers: `Cache-Control: private` with a short max-age (5 minutes) and an ETag for revalidation, `X-Content-Type-Options: nosniff`, `Content-Type` from the stored type, and `Content-Security-Policy: default-src 'none'; sandbox` on the image response only.
- **D-13:** The renderer resolves `kokpit-attachment:<uuid>` to an `<img>` that points at the authorised route, only for images that belong to the article being rendered. External image URLs stay plain alt text. The sanitiser allows `img` only with the internal route as `src`. Preview, view screen, version view, diff and restore all use the same renderer.
- **D-14:** Authorisation follows the article, not the version: an image is served as long as it exists and the viewer may open its article. A stored version only references the UUID, so viewing or restoring an earlier version shows its images as long as they still exist.
- **D-15:** No page-wide Content-Security-Policy is introduced in this phase (the application sends none today and a policy for Filament and Livewire is separate, risky work).

### Lifecycle and cleanup (IMG-03)
- **D-16:** `SaveArticle` records in the same transaction which images the saved body and the new version reference, in a reference table keyed by version and attachment (index of references written at save time). Cleanup works from SQL over that table and never parses Markdown.
- **D-17:** An image is an orphan when neither the current body nor any stored version references it. A scheduled job deletes an orphan 7 days after it became one (the S3 object and the row); an image still referenced by any stored version always survives. Pending images are deleted after 24 hours.
- **D-18:** Restoring a version that references an image that has been deleted renders that reference as alt text and the restore still succeeds; the restore flow shows no error for it.
- **D-19:** The leaked-secret case: a manual Admin action deletes an image for good, immediately, with a required reason. It removes the S3 object and the row, so all references in the body and in every version render as alt text. The activity log records who, which article and that a reason exists, never the reason text. This is the image counterpart of the Phase 19 version redaction.

### Claude's Discretion
- Image library choice (GD is present with WebP; Imagick is present in DDEV but not guaranteed on the deploy target), the exact table and column names, S3 key layout, the JavaScript for the toolbar button, paste and drop, the Czech wording, the job schedule time, and how the pixel-count limit is expressed.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope and requirements
- `.planning/ROADMAP.md` §"Phase 20: Image Attachments" and the research flag line (image library on the deploy target, CSP, insertion at the editor cursor)
- `.planning/REQUIREMENTS.md` — IMG-01, IMG-02, IMG-03 and the Out of Scope row "Attachments other than images"
- `.planning/STATE.md` §Blockers/Concerns — Phase 20 item (image library, WebP on the deploy target, CSP, orphan retention)

### Prior decisions
- `.planning/phases/18-knowledge-articles-and-secret-guard/18-CONTEXT.md` — D-06 and D-07 (editor tabs, toolbar without image button, server-rendered preview), the single save path and stale check
- `.planning/phases/19-folders-tags-and-version-history/19-CONTEXT.md` — version snapshots, restore through `SaveArticle`, redaction (D-11 to D-13)

### Research
- `.planning/research/PITFALLS.md` — Pitfall 1 (renderer, internal image scheme, CSP) and Pitfall 2 (screenshots cannot be scanned)
- `.planning/research/ARCHITECTURE.md` — `app/Domain/Knowledge/` layout
- `.planning/research/SUMMARY.md` — K4 image attachments notes

### Existing code
- `app/Domain/Knowledge/Support/LinkAndImagePolicy.php` — currently replaces every image by its alt text; must learn the internal scheme
- `app/Domain/Knowledge/Support/KnowledgeMarkdown.php` — the only place a body becomes HTML; sanitiser allowlist
- `app/Domain/Knowledge/Actions/SaveArticle.php` — single save path where pending images are bound and references recorded
- `app/Domain/Knowledge/Actions/RestoreVersion.php`, `app/Domain/Knowledge/Actions/RedactVersion.php` — restore and redaction paths
- `config/filesystems.php` — private `s3` disk; `ProductionConfigGuard` refuses other disks
- `tests/Support/PartnerMatrix.php`, `tests/Support/RouteRules.php` — every new route, page and Livewire component declared in the same pull request
- `CONTRIBUTING.md` — repository hygiene (fictional fixtures only, secrets assembled at runtime)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `KnowledgeMarkdown` renderer, `LinkAndImagePolicy`, sanitiser: extended instead of replaced.
- `SaveArticle` transaction (lock, stale check, secret guard, version row, activity log): the place to bind pending images and write references.
- S3 private disk (`aws/aws-sdk-php`, `league/flysystem-aws-s3-v3`), RustFS in DDEV and CI, `StorageCheck`.
- PHP GD with WebP support (host and DDEV); Imagick loaded in DDEV only. No image package in `composer.json` today.
- Scheduled commands and queue (Horizon) for the cleanup job.

### Established Patterns
- Domain Actions in `app/Domain/Knowledge/Actions`; Czech UI text only in `lang/cs/knowledge.php`, code and tests in English.
- Activity log stores metadata only, never body, reason text or titles.
- Partner fail-closed: new routes and components declared in the Partner matrix and route rules in the same pull request.
- Migrations and the full test run happen after the merge into the HEAD branch, not in a worktree.

### Integration Points
- Editor screen of the knowledge article (toolbar button, paste and drop), the article view, version view and diff screens, a new image route, a new migration for the image and reference tables, the scheduled cleanup job, and an Admin delete-image action.

</code_context>

<specifics>
## Specific Ideas

Success criteria are proven with fictional fixtures: a generated test photo with a GPS EXIF position, a renamed non-image, an SVG, an oversized file and an animated GIF, all assembled at runtime. No real screenshots or data in git.

</specifics>

<deferred>
## Deferred Ideas

- Page-wide Content-Security-Policy for Filament and Livewire (separate hardening work).
- Animated GIF support (needs a re-encode path that is safe on the deploy target).
- Sharing images with clients (Phase 23).

</deferred>

---

*Phase: 20-Image Attachments*
*Context gathered: 2026-11-26*
