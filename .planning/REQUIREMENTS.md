# Requirements: Kokpit v1.1 Knowledge Center

**Defined:** 2026-11-25
**Core Value:** Tracked time turns into an issued, payable invoice in one pass, with no unbilled time or unpaid invoice ever slipping through unnoticed.
**Milestone goal:** A company "second brain" inside Kokpit: general and project/client knowledge, organised in folders and tags, versioned, full-text searchable and stored in a form that AI tools can read.

## v1.1 Requirements

### Knowledge articles (KB)

- [ ] **KB-01**: Admin can create an article with a title and a body written in Markdown
- [ ] **KB-02**: Every article has its own view screen and its own edit screen
- [ ] **KB-03**: The body renders safely (tables, task lists, links, highlighted code; raw HTML is escaped, unsafe links are dropped)
- [ ] **KB-04**: A callout written as `> [!NOTE]` renders as a highlighted block
- [ ] **KB-05**: Every code block has a Copy button
- [ ] **KB-06**: The editor offers a server-rendered preview
- [ ] **KB-07**: Admin can archive and restore an article
- [ ] **KB-08**: A concurrent edit never overwrites another change silently (a stale save is rejected, not merged)

### Organisation (ORG)

- [ ] **ORG-01**: Admin manages a folder tree (create, rename, move; depth is capped, cycles are impossible)
- [ ] **ORG-02**: A non-empty folder cannot be deleted
- [ ] **ORG-03**: Articles are filed in folders and tagged with a dedicated tag type separate from client tags
- [ ] **ORG-04**: An article can be linked to a client and/or a project; client and project pages show a Knowledge tab

### Versions (VER)

- [ ] **VER-01**: Every content change creates an immutable version (gap-free numbering, no version for a save without changes)
- [ ] **VER-02**: Admin can see the version history and view an earlier version
- [ ] **VER-03**: Restoring creates a new version and passes the secret guard again
- [ ] **VER-04**: Admin can see the difference between two versions
- [ ] **VER-05**: Admin can redact a secret that reached a stored version

### Secret guard (SEC)

- [ ] **SEC-01**: Every save and every restore scans the content for secrets; the message names the rule and the line, never the value
- [ ] **SEC-02**: Unambiguous secrets block the save; ambiguous findings need acknowledgment and mark the article sensitive
- [ ] **SEC-03**: An override applies to one save only and is logged without the content
- [ ] **SEC-04**: A sensitive article cannot be shared with a client and is excluded from exports

### Images (IMG)

- [ ] **IMG-01**: Only images (PNG, JPEG, WebP, GIF) can be attached; SVG is rejected
- [ ] **IMG-02**: Images are stored privately, re-encoded without EXIF and delivered only through an authorised route
- [ ] **IMG-03**: Orphaned images are cleaned up, respecting references from stored versions

### Search (SRCH)

- [ ] **SRCH-01**: A dedicated knowledge search page exists, separate from the Filament global search
- [ ] **SRCH-02**: Search ignores diacritics and case, supports prefix matching and ranks titles above body text
- [ ] **SRCH-03**: Results show snippets and filters (kind, tag, folder, client, project) and respect the viewer's visibility
- [ ] **SRCH-04**: Search also finds incident sub-pages and the content of code, paths and commands

### Incidents (INC)

- [ ] **INC-01**: Admin can create an incident with metadata (time with timezone, severity, status, affected system)
- [ ] **INC-02**: An incident records causes (hypothesis, confirmed, ruled out), a timeline, actions taken and follow-ups with due date and status
- [ ] **INC-03**: An incident has sub-pages (overview, report, changes, runbook, monitoring, tasks) that are hidden from listings but searchable
- [ ] **INC-04**: Incidents get a number `INC-NNNN` from the existing number sequences
- [ ] **INC-05**: A follow-up can create a Kokpit task one way
- [ ] **INC-06**: Only Admin can see incidents

### Retrospectives (RETRO)

- [ ] **RETRO-01**: A project has a Create retrospective button; at most one live retrospective exists per project (the button opens the existing one)
- [ ] **RETRO-02**: A retrospective has fixed sections: description and output, what went well, what we learned, what to do better
- [ ] **RETRO-03**: A time overview (worked, billed, unbilled, estimate vs actual) is built from tracked time, visible to Admin only, without rates or prices, kept out of the Markdown body, with a Refresh action
- [ ] **RETRO-04**: Only Admin can see retrospectives

### Client sharing (SHARE)

- [ ] **SHARE-01**: Admin can share a general article with its client through a switch (only for an article linked to a client; confirmation dialog)
- [ ] **SHARE-02**: Partner sees only shared articles of their own client, read-only, as a flat list without folders, tags or versions
- [ ] **SHARE-03**: An article linked to a project is visible to Partner only when the project is visible to the client too
- [ ] **SHARE-04**: Partner never sees sensitive articles, incidents, retrospectives, version history or another client's data (proven by the Partner audit)

### Navigation and AI (NAV, AI)

- [ ] **NAV-01**: The left menu has a Knowledge group with General knowledge, Incidents and Retrospectives
- [ ] **AI-01**: Every article has a canonical AI-readable export (YAML front matter plus the original Markdown)
- [ ] **AI-02**: A Copy as Markdown for AI action exists

## v2 Requirements

Deferred to a future release.

- **API-01**: REST API and MCP access to knowledge (next milestone)
- **SRCH-05**: Semantic search with embeddings; Czech stemming; typo tolerance
- **KB-09**: Backlinks and `[[wiki links]]`
- **INC-07**: Client-facing incident summaries; overdue follow-up dashboard widget
- **KB-10**: Markdown ZIP export; starter templates

## Out of Scope

| Feature | Reason |
|---------|--------|
| Real-time co-editing | High complexity; optimistic locking is enough for one admin |
| WYSIWYG or block editor | Would store HTML or JSON as source; Markdown must stay canonical |
| Attachments other than images | Text always lives inside the article |
| Secrets vault | Out of product focus; the guard only prevents leaks |
| Folder-level permissions | Visibility is per article, never inherited |
| Public share links | Partner access goes through the panel only |
| Sharing incidents and retrospectives with clients | Time and internal details must not reach a Partner |
| "Person at fault" field | Blameless post-mortems |
| Profitability figures in retrospectives | Finance stays outside the Partner-visible and text content |
| Server-side autosave drafts | Adds a draft store that the secret guard would also have to cover |
| Importing external wiki content | Real data must never enter the public repository |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| KB-01 | Phase 18 | Pending |
| KB-02 | Phase 18 | Pending |
| KB-03 | Phase 18 | Pending |
| KB-04 | Phase 18 | Pending |
| KB-05 | Phase 18 | Pending |
| KB-06 | Phase 18 | Pending |
| KB-07 | Phase 18 | Pending |
| KB-08 | Phase 18 | Pending |
| ORG-01 | Phase 19 | Pending |
| ORG-02 | Phase 19 | Pending |
| ORG-03 | Phase 19 | Pending |
| ORG-04 | Phase 19 | Pending |
| VER-01 | Phase 18 | Pending |
| VER-02 | Phase 19 | Pending |
| VER-03 | Phase 19 | Pending |
| VER-04 | Phase 19 | Pending |
| VER-05 | Phase 19 | Pending |
| SEC-01 | Phase 18 | Pending |
| SEC-02 | Phase 18 | Pending |
| SEC-03 | Phase 18 | Pending |
| SEC-04 | Phase 23 | Pending |
| IMG-01 | Phase 20 | Pending |
| IMG-02 | Phase 20 | Pending |
| IMG-03 | Phase 20 | Pending |
| SRCH-01 | Phase 22 | Pending |
| SRCH-02 | Phase 22 | Pending |
| SRCH-03 | Phase 22 | Pending |
| SRCH-04 | Phase 22 | Pending |
| INC-01 | Phase 21 | Pending |
| INC-02 | Phase 21 | Pending |
| INC-03 | Phase 21 | Pending |
| INC-04 | Phase 21 | Pending |
| INC-05 | Phase 21 | Pending |
| INC-06 | Phase 21 | Pending |
| RETRO-01 | Phase 21 | Pending |
| RETRO-02 | Phase 21 | Pending |
| RETRO-03 | Phase 21 | Pending |
| RETRO-04 | Phase 21 | Pending |
| SHARE-01 | Phase 23 | Pending |
| SHARE-02 | Phase 23 | Pending |
| SHARE-03 | Phase 23 | Pending |
| SHARE-04 | Phase 23 | Pending |
| NAV-01 | Phase 21 | Pending |
| AI-01 | Phase 23 | Pending |
| AI-02 | Phase 23 | Pending |

**Coverage:**
- v1.1 requirements: 45 total
- Mapped to phases: 45
- Unmapped: 0 ✓

**Per phase:** Phase 18: 12, Phase 19: 8, Phase 20: 3, Phase 21: 11, Phase 22: 4, Phase 23: 7

Cross-phase clauses (traced once, proven again later; see ROADMAP.md mapping notes): the restore clause of SEC-01 is proven by VER-03 in Phase 19, the "searchable" clause of INC-03 by SRCH-04 in Phase 22, and Partner fail-closed behaviour is kept from Phase 18 on and proven in full by SHARE-04 in Phase 23.

---
*Requirements defined: 2026-11-25*
*Last updated: 2026-11-25 after roadmap creation (phases 18-23)*
