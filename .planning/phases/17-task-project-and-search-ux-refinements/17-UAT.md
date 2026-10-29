---
status: testing
phase: 17-task-project-and-search-ux-refinements
source: [17-VERIFICATION.md]
started: 2026-10-29T11:45:46Z
updated: 2026-10-29T11:45:46Z
---

## Current Test

number: 1
name: Open an image file from a file list (Admin: task, project and Soubory lists; Partner: task and project Soubory tab; images under a task comment) in Chrome, Firefox and Safari
expected: |
  A single-image lightbox opens (5xl modal, loading indicator, then the picture at most 80% of the window high, one Zavřít button, ESC and backdrop close it, no previous/next, zoom or thumbnails)
awaiting: user response

## Tests

### 1. Open an image file from a file list (Admin: task, project and Soubory lists; Partner: task and project Soubory tab; images under a task comment) in Chrome, Firefox and Safari
expected: A single-image lightbox opens (5xl modal, loading indicator, then the picture at most 80% of the window high, one Zavřít button, ESC and backdrop close it, no previous/next, zoom or thumbnails)
result: [pending]

### 2. Click Zobrazit on a PDF row in each file list of both panels and under a comment, in each browser
expected: The PDF opens in a new tab without a popup-blocker warning; a page left open past the link lifetime shows 403 in the new tab until the list re-renders (accepted, owner decision)
result: [pending]

### 3. Read a task description, a comment, the Partner task page and the board-preview panel containing headings, lists, a quote and a wide table in light and dark theme
expected: Formatting is visible (fi-prose), the table scrolls sideways in the preview, nothing overflows, contrast is acceptable in both themes
result: [pending]

### 4. Use the Signal add field with the keyboard only (type 2+ characters, arrow keys, Enter to pick, Escape, the cross to clear) and with a screen reader if available
expected: The combobox list opens and closes, the active option is announced, a pick fills `{PROJECT}: {task name} [{CODE}]` read-only with the link icon, the cross returns to free text focused in the field
result: [pending]

### 5. Open a project view and a task view as Admin and check the header actions
expected: Project: Board, Nový úkol, Změnit stav, Upravit, Archivovat in a sensible order and colour; Nový úkol is absent for a Done or archived project. Task: Přidat čas next to the timer, with client/project/task shown disabled and the helper text
result: [pending]

### 6. Hide a task from the board with the checkbox on the edit form, then look at the global board, the project board, the task list and the global search
expected: The card is gone from both boards only; it stays in the list, search and its own page; unchecking brings it back
result: [pending]

## Summary

total: 6
passed: 0
issues: 0
pending: 6
skipped: 0
blocked: 0

## Gaps
