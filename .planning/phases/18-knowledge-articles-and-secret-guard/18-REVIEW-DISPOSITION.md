---
phase: 18
review: 18-REVIEW.md
titles: json
findings:
  - id: CR-01
    severity: critical
    disposition: fixed
    title: "PEM private key is only an overridable \"high entropy\" finding when it is indented, quoted or JSON-escaped"
  - id: WR-01
    severity: warning
    disposition: fixed
    title: "The scanner reads Markdown source, so escapes, entities and invisible characters hide a secret completely"
  - id: WR-02
    severity: warning
    disposition: fixed
    title: "Task-list toolbar button disappears after the first Preview to Write switch (SPA mode)"
  - id: IN-01
    severity: info
    disposition: fixed
    title: "Scanner has two quiet gaps that follow from its shape limits"
  - id: IN-02
    severity: info
    disposition: deferred
    title: "Same content can normalise to two canonical forms (final newline after a code block)"
  - id: IN-03
    severity: info
    disposition: deferred
    title: "Sanitiser can leave an inert `<a>` without `href`, contradicting the documented guarantee"
  - id: IN-04
    severity: info
    disposition: fixed
    title: "Database failures and concurrent archive reach the Admin as a raw server error, not a notice"
  - id: IN-05
    severity: info
    disposition: deferred
    title: "The body limit is defined in three places with comments, not code, tying them together"
  - id: IN-06
    severity: info
    disposition: deferred
    title: "EasyMDE fetches remote images referenced in an article while it is being edited"
open: 0
total: 9
---

# Phase 18: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| CR-01 | critical | fixed | Fixed by plan 18-11 (commits 488389c5, 17aa73b3): the PEM private key rule tolerates quotes, indents, tabs and an escaped line break, and a key on one line has its own rule; both block with no override (D-01), proven on the create screen by plan 18-13 (commit 36bf9724) |
| WR-01 | warning | fixed | Fixed by plan 18-11 (commits b769e1f8, 65a3adbf): the scanner strips invisible characters and checks a rendered-equivalent view (Markdown escapes, HTML entities) next to the raw line; proven on the create screen by plan 18-13 (commit 36bf9724) |
| WR-02 | warning | fixed | Fixed by plan 18-13 (commit 157e6172): the observer in knowledge-editor.js watches the document element, which SPA navigation keeps; fixed, browser check pending (18-VERIFICATION human verification item 1) |
| IN-01 | info | fixed | Fixed by plan 18-11 (commit 17aa73b3, R-18-11-6): a random run over 256 characters and a quoted password with spaces now give an Ambiguous finding, which D-01 already classes as overridable, so no new owner decision was needed |
| IN-02 | info | deferred | Deferred under the owner's standing ruling on A3 (D-10 applied literally: no code byte changes); the fix would drop the final line ending of a closing code fence; the effect is one extra version for an apparently unchanged save, no data loss and no secret involved; reopening it needs the owner to revise A3 (open question in the 18-13 SUMMARY) |
| IN-03 | info | deferred | Not exploitable: an anchor without href is inert and the sanitiser stays the second layer; the renderer is outside this closure; revisit with the link and image policy in Phase 20 |
| IN-04 | info | fixed | Fixed by plan 18-12 (commits f2ee0f8f, 2794e184, c4708dcb): a refused write and a save of an article archived in another tab end in a persistent notice that keeps the typed text; the review's NUL premise was corrected, the PostgreSQL driver cuts a bound text off at a NUL instead of raising, so ArticleInput refuses the NUL |
| IN-05 | info | deferred | Mostly guarded already: SaveArticleTest asserts that KnowledgeArticle::MAX_BODY_LENGTH equals KnowledgeMarkdown::MAX_INPUT_LENGTH and 100000, so raising one constant fails a test; only the two migration CHECK literals stay untied; add a schema assertion with the next knowledge migration in Phase 19 |
| IN-06 | info | deferred | Filament default (EasyMDE previews images inside the editor) with no PHP switch, and images are Phase 20 scope; revisit the editor configuration there |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved. A `|` in the reason is kept as prose and escaped on the next run.
Re-running the gate keeps every row it can. A row the current review no longer reports is kept and its Source cell flagged, so a finding does not leave this record silently.
