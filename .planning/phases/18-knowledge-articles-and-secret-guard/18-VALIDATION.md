---
phase: "18"
slug: "knowledge-articles-and-secret-guard"
status: draft
nyquist_compliant: true
wave_0_complete: false
created: "2026-11-25"
---

# Phase 18 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13.3 |
| **Config file** | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Audit, Concurrency), `tests/Pest.php` |
| **Quick run command** | `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge` |
| **Full suite command** | `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` then `ddev exec vendor/bin/pest --testsuite=Audit` |
| **Estimated runtime** | ~120 seconds (quick run: ~5 seconds) |

---

## Sampling Rate

- **After every task commit:** Run `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Unit/Knowledge` plus Pint on touched files (pure, allowed in a worktree)
- **After every plan wave (post-merge into HEAD branch):** migrations, then `pest --filter=Knowledge` across Feature, Isolation, Concurrency, Arch
- **Before `/gsd-verify-work`:** Full suite, Audit suite, `ddev composer stan`, `ddev composer lint`, `ddev composer check-licenses` green; `scripts/check-sensitive.sh` clean
- **Max feedback latency:** 120 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 18-XX-XX | TBD | TBD | KB-03 | XSS | hostile corpus rendered inert, no truncation, memory cap | unit | `pest tests/Unit/Knowledge/KnowledgeMarkdownTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | KB-03, KB-05 | tamper | code block text equals source | unit | `pest tests/Unit/Knowledge/CodeBlockTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | KB-04 | — | five callout types, others stay blockquotes | unit | `pest tests/Unit/Knowledge/CalloutTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | SEC-01, SEC-02 | secret leak | rules hit fixtures, no value in findings, fail closed | unit | `pest tests/Unit/Knowledge/SecretScannerTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | VER-01 | — | normaliser idempotent, code protected | unit | `pest tests/Unit/Knowledge/MarkdownNormaliserTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | KB-01, VER-01, SEC-01..03 | secret leak | SaveArticle versioning, refusal, acknowledgment, metadata-only activity | feature | `pest tests/Feature/Knowledge/SaveArticleTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | KB-08 | lost update | stale save refused, typed text kept | feature | `pest tests/Feature/Knowledge/EditKnowledgeArticleTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | KB-08, VER-01 | lost update | one winner under parallel saves, gap-free versions | concurrency | `pest tests/Concurrency/KnowledgeSaveConcurrencyTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | VER-01 | tamper | append-only versions, folder cycle/depth/unique, CHECKs | feature | `pest tests/Feature/Schema/KnowledgeTablesTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | KB-01, KB-02, KB-06, KB-07 | — | list/create/view/edit, preview parity, archive/restore | feature | `pest tests/Feature/Knowledge/KnowledgeArticleResourceTest.php` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | Partner seam | elevation | Partner gets 403 / zero rows, matrices declared | isolation + audit | `pest tests/Isolation/KnowledgeVisibilityTest.php` and `--testsuite=Audit` | ❌ W0 | ⬜ pending |
| 18-XX-XX | TBD | TBD | Seam guards | XSS | no raw markdown rendering outside the renderer; only SaveArticle writes body_md | arch | `pest tests/Arch/KnowledgeSeamTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Support/SecretFixtures.php` — runtime-assembled fake secrets
- [ ] `tests/Support/Fixtures/knowledge-hostile-corpus.md` (or PHP heredoc helper) — fictional hostile Markdown
- [ ] `tests/Unit/Knowledge/*` — renderer, code block, callout, scanner, normaliser tests
- [ ] `tests/Feature/Schema/KnowledgeTablesTest.php` — raw-SQL integrity tests
- [ ] `tests/Concurrency/KnowledgeSaveConcurrencyTest.php` + `knowledge-save-worker.php`
- [ ] `tests/Isolation/KnowledgeVisibilityTest.php`, `tests/Arch/KnowledgeSeamTest.php`
- [ ] Edits to existing declaration files (model, morph map, canary, activity allowlist, enum labels, channel matrix)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Leave warning on reload and link click; none after save or Preview switch | KB-08 | Browser beforeunload behavior | Edit an article, change text, reload; save, reload |
| Write → Preview → Write keeps typed text; preview shows unsaved text | KB-01 | Real Filament tab round trip | Type text, switch tabs back and forth |
| Copy button copies exact block text and shows confirmation | KB-05 | Clipboard needs a real browser over HTTPS | Click Copy on a code block, paste elsewhere |
| Light/dark mode of callouts and code colours; focus ring on Copy | KB-04, KB-05 | Visual check | Toggle theme, tab to Copy |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 120s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
