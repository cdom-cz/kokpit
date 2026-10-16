---
status: testing
phase: 09-documents-and-finance
source: [09-VERIFICATION.md]
started: 2026-10-16T18:30:56Z
updated: 2026-10-16T18:30:56Z
---

## Current Test

number: 1
name: DDEV browser pass (Admin and Partner)
expected: |
  With LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local. Admin: upload, preview, download, build a ZIP that includes two same-named files and download it from the bell link, archive and restore a project. Partner: sees only the shared file, uploads while creating a task, never sees hidden or internal-comment files.
awaiting: user response

## Tests

### 1. DDEV browser pass (Admin and Partner)
expected: See Current Test.
result: [pending]

### 2. Deploy rehearsal of browser-to-bucket upload (D-18)
expected: Two web containers, temporary disk unset, 10 MB direct upload, CORS, one-day expiry rule, streamed download with no 413.
result: [pending]

### 3. Finance pass
expected: Weekend EUR expense against Friday's CNB list, manual figure override, month and year on the overview.
result: [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
