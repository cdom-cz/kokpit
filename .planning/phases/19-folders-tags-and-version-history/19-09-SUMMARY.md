---
phase: 19-folders-tags-and-version-history
plan: 09
subsystem: knowledge
tags: [restore, redaction, version-history, immutability, secret-guard, concurrency, arch-rules]
requires:
  - "19-01: redaction_no, restored_from_version_no, knowledge_version_redactions, the redaction setting and trigger variant"
  - "19-02: copy keys knowledge.redact.*, activity event labels"
  - "19-04: SaveArticle::update() with expectedRedactionNo and restoredFromVersionNo, StaleArticle::$redactionChanged"
provides:
  - "RestoreVersion::handle(User, KnowledgeArticle, int versionNo, int expectedVersion, int expectedRedactionNo, bool acknowledge = false): SaveResult"
  - "RedactVersion::handle(User, KnowledgeArticle, int versionNo, RedactionScope, string lines, mixed reason): RedactResult; MARKER, PEM_BLOCK_MAX_LINES, MAX_REASON_LENGTH"
  - "RedactionLines::parse(string, int lineCount): list<int>; MAX_INPUT_LENGTH"
  - "RedactResult (versionNo, current, redactedLines, keyRanges, remainingFindings)"
  - "activity event version_redacted with redacted_version_no, scope, current, reason_recorded"
  - "Arch: KNOWLEDGE_SEAM_WRITERS map, setting rule, reason persistence ban"
affects: [19-11 restore and redaction modals, 19-12]
tech-stack:
  added: []
  patterns:
    - "restore as a save: article lock first, version read second, then SaveArticle::update()"
    - "controlled immutability exception: query-builder body-only UPDATE inside a transaction-local setting that is switched off right after"
    - "reason length stored, reason text discarded at once (OD-1)"
key-files:
  created:
    - app/Domain/Knowledge/Actions/RestoreVersion.php
    - app/Domain/Knowledge/Actions/RedactVersion.php
    - app/Domain/Knowledge/Actions/RedactionLines.php
    - app/Domain/Knowledge/Actions/RedactResult.php
    - tests/Feature/Knowledge/RestoreVersionTest.php
    - tests/Feature/Knowledge/RedactVersionTest.php
    - tests/Unit/Knowledge/RedactionLinesTest.php
    - tests/Concurrency/KnowledgeRestoreRedactConcurrencyTest.php
    - tests/Concurrency/knowledge-version-worker.php
  modified:
    - tests/Arch/KnowledgeSeamTest.php
decisions:
  - "R-19-09-1 to R-19-09-5 implemented as planned; the setting text is written only in the two set_config string literals, there is no separate SETTING constant (see Deviations)"
  - "Redaction of a line range that starts inside a private key range found earlier does not search again; key ranges are reported only when they extend past the selected line"
  - "RedactionLines treats a number of more than nine digits as lines_invalid, so no integer overflow can reach line_missing"
  - "R-19-09-OQ3 (owner decision OD-3): redacting version k does not cascade; the redaction modal of 19-11 must show knowledge.redact.later_versions_keep next to the rotate callout"
metrics:
  tasks: 3
  completed: 2026-11-26
status: complete
commits: 3
plan_head_before: ed7812d712dcc9ad639da70f88e2294e937678b1
plan_head_after: 296cd89950c0e5eb06d3cf37261c8de90528a16c
actuals:
  tokens: 36000
  tasks: 3
  commits: 3
---

# Phase 19 Plan 09: Restore and Redact Version Actions Summary

Restoring an earlier version as a new version through the one save path (stale check, normaliser and secret guard again), and redacting a stored version through the one controlled exception to immutability, with Arch rules that keep both seams narrow and a real-process test of the lock order.

## What was built

- `RestoreVersion` (R-19-09-1): authorises, locks the article through the scoped query, authorises the locked row, and only then reads the version; hands the version's title and body to `SaveArticle::update()` with `restoredFromVersionNo`. No classification is passed, so folder, links, tags and the sensitive flag stay. PDO failures become `ArticleWriteFailed`. The caller's article instance is refreshed.
- `RedactionLines` (R-19-09-2): parses `3, 7-9` into sorted unique 1-based line numbers; field errors on `lines` (`lines_required`, `lines_invalid`, `line_missing` with n).
- `RedactVersion` and `RedactResult` (R-19-09-3): validates the reason (string, no NUL, non-blank, at most 500 characters) and keeps only its length; locks the article with `withTrashed()`, reads the version, splits the body keeping separators (so CRLF in code stays byte for byte), selects lines (private key header extends to the END line within 200 lines, or to the end of the contiguous block; blank and already redacted lines skipped), checks the body limit before the database, writes the redaction row, switches the setting on, updates the version body (and the article body plus `redaction_no + 1` when it is the current version) with the query builder, switches the setting off, writes one `version_redacted` activity row with numbers, scope and booleans only, and rescans the new body after the commit.
- `KnowledgeSeamTest` (R-19-09-4): the single-writer constant became the reviewed map `KNOWLEDGE_SEAM_WRITERS` (`body_md`: SaveArticle and RedactVersion, `version_no`: SaveArticle, `redaction_no`: RedactVersion); new rules that only RedactVersion names the setting and that no statement naming the redaction model, the redaction table or (in knowledge files) `withProperties` carries a `'reason' =>` key; each with an inline self-check, and a check that every listed writer still writes its column.
- Concurrency proof (R-19-09-5): `knowledge-version-worker.php` (modes `redact-hold` and `restore`, refuses a database not ending in `_test`) and `KnowledgeRestoreRedactConcurrencyTest` (three rounds, restore started 300 ms after a redaction that holds the article lock for 1500 ms; asserts the restore copied the marker; trigger-aware cleanup over both guarded tables with an enabled-trigger assertion).

## Verification

Run in the worktree: `php -l` on every new and changed file (clean), the greps of the plan acceptance criteria (all met), `scripts/check-sensitive.sh` on every new file (clean), the lefthook hook on each commit (sensitive-content and gitleaks passed). The Arch helper functions were also executed outside Pest against the real `app/` tree: no writer, setting or reason violations, `body_md` 3 and 2 writes, `version_no` 5, `redaction_no` 1 in the reviewed writers, setting named twice in RedactVersion, and the inline self-check fixtures report 7 / 2 / 3 / 2 / 0 as the tests expect.

Not run in the worktree (no database or vendor directory there; per project rules these run in DDEV after the wave 3 merge, one Pest process at a time, never `--parallel`):

- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/RestoreVersionTest.php`
- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge/RedactionLinesTest.php tests/Feature/Knowledge/RedactVersionTest.php tests/Arch/KnowledgeSeamTest.php tests/Feature/Schema/KnowledgeTablesTest.php`
- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Concurrency/KnowledgeRestoreRedactConcurrencyTest.php` (alone; runtime to be recorded after the first run: three rounds with a 5 s barrier each, expected well under one minute)
- `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Concurrency/KnowledgeSaveConcurrencyTest.php` (regression, alone)

The tracer feedback gate of Task 1 (re-running `<verify>` end to end) could not run for the same reason; the first post-merge run of `RestoreVersionTest` is that gate.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] No separate `SETTING` constant in RedactVersion**
- **Found during:** Task 2
- **Issue:** R-19-09-3 lists a private `const string SETTING`, but the acceptance criteria require the literal `set_config('kokpit.redacting_knowledge_version', 'on', true)` (and the verify step the `'off'` literal) to appear exactly once in the file. A constant used inside the SQL strings would make those greps fail, and an unused private constant would be a static-analysis finding.
- **Fix:** the setting text lives only in the two SQL string literals; the Arch rule (a) still guarantees that no other app file names it.
- **Files modified:** app/Domain/Knowledge/Actions/RedactVersion.php
- **Commit:** 5a7e4938

**2. [Rule 2 - Missing critical functionality] Unit test boots the application**
- **Found during:** Task 2
- **Issue:** `RedactionLines` builds field errors with the translator, which a plain Unit test does not have.
- **Fix:** `RedactionLinesTest` calls `uses(TestCase::class)` like other Unit tests that need the application; it touches no database.
- **Files modified:** tests/Unit/Knowledge/RedactionLinesTest.php
- **Commit:** 5a7e4938

**3. [Rule 2 - Missing critical functionality] Number overflow guard in RedactionLines**
- **Found during:** Task 2
- **Issue:** an unbounded `\d+` token would overflow `(int)` and could surface a meaningless line number in `line_missing`.
- **Fix:** tokens of more than nine digits are `lines_invalid` (covered by a unit test case).
- **Files modified:** app/Domain/Knowledge/Actions/RedactionLines.php
- **Commit:** 5a7e4938

The base64-only selection test selects line 5 (the second base64 line, header and END intact) rather than a line next to the header, so the rescan assertion does not depend on how the scanner treats a marker line between a key header and its body.

## Hand-over notes

- R-19-09-OQ3 (owner decision OD-3 of 2026-11-26): redacting version k leaves every later version that still holds the same text unchanged, so the secret stays in those versions until each is redacted on its own. D-12 limits a redaction to the chosen version, and the owner chose to state this on screen: the redaction modal of 19-11 shows `knowledge.redact.later_versions_keep` next to the rotate callout.
- 19-11 callers: pass `expectedVersion` and `expectedRedactionNo` as read when the modal opened; an `ArticleWriteFailed`, `StaleArticle`, `SecretGuardRefused` and `ValidationException` (fields `lines` and `reason`) are the refusals. `RedactResult::keyRanges` feeds `knowledge.redact.notifications.key_extended` and `remainingFindings` feeds `remaining_title` and `remaining_body`.
- The activity label `version_redacted` already exists in `lang/cs/kokpit.php`; no copy was added in this plan.
- No new routes, so `tests/Support/ChannelProbe.php` and `RouteWalkTest` need no change here.

## Known Stubs

None.

## Threat Flags

None. The trust-boundary surface of this plan is the one in the plan's threat model (T-19-32 to T-19-38); no new endpoint, auth path or schema change was added.

## Self-Check: PASSED

- FOUND: app/Domain/Knowledge/Actions/RestoreVersion.php, RedactVersion.php, RedactionLines.php, RedactResult.php
- FOUND: tests/Feature/Knowledge/RestoreVersionTest.php, RedactVersionTest.php; tests/Unit/Knowledge/RedactionLinesTest.php; tests/Concurrency/KnowledgeRestoreRedactConcurrencyTest.php, knowledge-version-worker.php; tests/Arch/KnowledgeSeamTest.php
- FOUND commits: 183b49c7 (restore), 5a7e4938 (redaction and Arch rules), 296cd899 (concurrency proof)
