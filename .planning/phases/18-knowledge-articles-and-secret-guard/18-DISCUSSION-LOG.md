# Phase 18: Knowledge Articles and Secret Guard - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-11-25
**Phase:** 18-knowledge-articles-and-secret-guard
**Areas discussed:** Secret guard rules, Editor and preview, Concurrent edit conflict, List/view/callouts

---

## Secret guard rules

| Option | Description | Selected |
|--------|-------------|----------|
| High-precision patterns only | PEM keys, cloud/Stripe/GitHub tokens, JWT, URLs with credentials block; the rest needs acknowledgment | ✓ |
| Patterns plus password assignment | Also blocks `password=`/`secret=`/`token=` with a value | |
| Everything with acknowledgment only | No hard block; conflicts with SEC-02 | |

**User's choice:** High-precision patterns only.

| Option | Description | Selected |
|--------|-------------|----------|
| Title and body | | |
| Body only | | ✓ |

**User's choice:** Body only (differs from the recommendation).

| Option | Description | Selected |
|--------|-------------|----------|
| Save again with acknowledgment | Refusal with findings, then a "save anyway" button | ✓ |
| Checkbox before save | Always-visible acknowledgment checkbox | |

**User's choice:** Save again with acknowledgment.

| Option | Description | Selected |
|--------|-------------|----------|
| Sensitive until Admin clears it | Fail closed | ✓ |
| Automatic from last save | | |

**User's choice:** Sensitive until Admin clears it.

---

## Editor and preview

| Option | Description | Selected |
|--------|-------------|----------|
| Write / Preview tabs | | ✓ |
| Side by side | | |
| Modal on demand | | |

**User's choice:** Write / Preview tabs.

| Option | Description | Selected |
|--------|-------------|----------|
| Basic toolbar | No images, no built-in preview or fullscreen | ✓ |
| Everything EasyMDE offers | | |

**User's choice:** Basic toolbar.

| Option | Description | Selected |
|--------|-------------|----------|
| Leave warning | | ✓ |
| No warning | | |

**User's choice:** Leave warning.

---

## Concurrent edit conflict

| Option | Description | Selected |
|--------|-------------|----------|
| Notice, keep my text, load current in new tab | | ✓ |
| Notice, switch to new version | | |
| Notice with overwrite option | | |

**User's choice:** Notice, keep my text.

| Option | Description | Selected |
|--------|-------------|----------|
| No-op after normalisation | LF, trimmed trailing spaces and blank lines; fenced code untouched | ✓ |
| Byte for byte | | |

**User's choice:** No-op after normalisation.

| Option | Description | Selected |
|--------|-------------|----------|
| Title change creates a version | | ✓ |
| Body only | | |

**User's choice:** Title change creates a version.

---

## List, view and callouts

| Option | Description | Selected |
|--------|-------------|----------|
| Five GitHub alert types | NOTE, TIP, IMPORTANT, WARNING, CAUTION | ✓ |
| NOTE and WARNING only | | |

**User's choice:** Five GitHub alert types.

| Option | Description | Selected |
|--------|-------------|----------|
| Title, version, edited, sensitive; active by default | | ✓ |
| Title and edited only | | |

**User's choice:** Title, version, edited, sensitive.

| Option | Description | Selected |
|--------|-------------|----------|
| Copy exact text with confirmation | | ✓ |
| Copy only | | |

**User's choice:** Copy exact text with confirmation.

---

## Claude's Discretion

- Article kind naming and folder tree implementation (owner chose to leave to the planner).
- Renderer and sanitiser details, highlighter choice, scanner pattern list, view layout, icons, Czech wording.

## Deferred Ideas

None.
