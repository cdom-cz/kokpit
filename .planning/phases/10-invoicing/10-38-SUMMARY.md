---
phase: 10-invoicing
plan: 38
subsystem: invoicing-pdf-download
tags: [invoicing, pdf, signed-route, partner-isolation, documents]
status: complete

requires:
  - phase: 10-invoicing
    provides: "Partner-scoped Invoice query and InvoicePolicy (plan 10-05), the Admin-only invoice_admin row with pdf_document_id and its link guard (plans 10-06, 10-08), the PDF stored as a document owned by the invoice at issue (plan 10-20)"
provides:
  - "files.invoice route (/admin/files/invoices/{invoice}): signed, short-lived, user-bound, throttled like files.document, covered by the */admin/files/* SPA exception"
  - "InvoicePdfController: scoped invoice and policy first, then the PDF link read as system, the document loaded only when the invoice owns it, streamed with nosniff and private, no-store"
  - "InvoicePdfUrl::download(Invoice, User) and InvoicePdfUrl::ROUTE, the only way a PDF gets a link"
  - "Route walk coverage of the new route (signed for the signed-in user, canary invoice of each side)"
affects: [10-26]

actuals:
  tokens: 6450
  tasks: 2
  commits: 2
plan_head_before: cfa3e4fdeb32f54122dd0fe3157b14d92c3f2c12
plan_head_after: 4eb4f90882094c8679cfb0607961eb736d9e51f9
commits: 2

coverage:
  - deliverable: "A Partner downloads the stored PDF of an issued document of the own client, and the Admin the PDF of any issued document, through a signed link bound to the user, as an attachment with nosniff and private, no-store"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfRouteTest.php#streams the stored PDF of an issued invoice to the Partner of its client as an attachment"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfRouteTest.php#streams the same PDF to the Admin through a link built for the Admin"
        status: pass
    human_judgment: false
  - deliverable: "An unsigned, tampered or expired link and a link signed for another user are 403 with the no-referrer header"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfRouteTest.php#refuses an unsigned, tampered or expired link and a link signed for another user with 403"
        status: pass
    human_judgment: false
  - deliverable: "A foreign, draft, unknown or malformed invoice, an issued document without a PDF, a deleted object and a link repointed to a file of another owner are 404 (never 403), naming nothing in the body; the owner check holds with the database guard dropped"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfRouteTest.php#answers a Partner 404, never 403, for the issued invoice of another client and for the own draft"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfRouteTest.php#answers 404 to the Partner and the Admin for a link repointed to a document of another owner, without relying on the database guard"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfRouteTest.php#answers 404 to the Partner and the Admin when the PDF was deleted"
        status: pass
    human_judgment: false
  - deliverable: "The Document scope stays closed: a Partner finds no document row of the invoice file and the file route refuses it, only the invoice route hands out the bytes"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/InvoicePdfRouteTest.php#keeps the Document scope closed"
        status: pass
    human_judgment: false
  - deliverable: "The route walk knows files.invoice: Partner A gets 404 for client B's invoice PDF with no trace, is served the own, the Admin is served both, a Partner without a client is refused"
    requirement: "IN-09"
    verification:
      - kind: test
        ref: "tests/Isolation/RouteWalkTest.php#refuses the invoice PDF route for the issued invoice of client B to Partner A with a 404 and no trace of client B"
        status: pass
      - kind: test
        ref: "tests/Isolation/RouteWalkTest.php#serves Partner A the invoice PDF route for the own issued invoice and the Admin for both clients, so the refusal comes from the scope"
        status: pass
    human_judgment: false

key-files:
  created:
    - app/Http/Controllers/InvoicePdfController.php
    - app/Domain/Invoicing/Support/InvoicePdfUrl.php
    - tests/Feature/Invoicing/InvoicePdfRouteTest.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "R-38-1 applied: the PDF has its own signed route files.invoice; Document::constrainForPartner is not widened and the controller never finds the document through the Document scope"
  - "Check order: user binding 403, uuid 404, scoped invoice and issued 404 (the Admin's query also holds drafts), policy 403, then as system only invoice_admin.pdf_document_id is read and the document loaded with owner_type invoice and owner_id the invoice id (404), then media (404)"
  - "The owner of the linked document is re-checked in the controller as well as by the database guard, proven by a test that drops the guard"
  - "A Partner without a client is refused by the panel (403) before the controller; the tests accept 403 or 404 there, like the existing file route walk"

patterns-established:
  - "A route that hands out a file of a closed model reaches it through a scoped parent record and reads the closed link as system after the policy"

requirements-completed: [IN-09, IN-04]

duration: "13 min"
completed: 2026-10-17
---

# Phase 10 Plan 38: Signed invoice PDF route Summary

**One signed, user-bound download route for the stored PDF of an issued document, authorised through the Partner-scoped invoice and the policy and reading the Admin-only PDF link only as system, with the owner of the linked file re-checked in the controller.**

## Accomplishments

- `InvoicePdfController` and `InvoicePdfUrl` with the route `files.invoice` next to `files.document` (same middleware, throttle and TTL); the analog's check order, extended with the issued check and the owner check on the linked document.
- Probing reveals nothing: foreign, draft, unknown, malformed, PDF-less, deleted-object and repointed links are all 404 without naming the document.
- Route walk teaches `walkedUrl()` the `invoice` parameter and gains three tests (client B refused, own/Admin served, no-client Partner refused).

## Task Commits

1. Task 1 (tracer) and the refusal matrix of Task 2 in `InvoicePdfRouteTest`: `f2c1a15`
2. Task 2 route walk: `4eb4f90`

The refusal-matrix tests of Task 2 were written together with the tracer in the same test file, so Task 2 added only the route walk commit; every behaviour on its list is covered by a test in `InvoicePdfRouteTest.php`.

## Deviations from Plan

None - plan executed exactly as written. (The tracer and the Task 2 behaviour tests share one commit because the controller was written with its full check order from the start; the tracer feedback gate was the same `<verify>` run, re-run green before the route walk was touched.)

## Known Stubs

None.

## Threat Flags

None. The new endpoint is the one in the plan's threat model (T-10-68, T-10-70), both mitigated and tested.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Isolation`: 683 passed
- Full suite `ddev exec vendor/bin/pest --compact`: 5699 passed, 3 skipped
- Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on both commits; acceptance greps pass

## Next Phase Readiness

Plan 10-26 (Partner screens) can link to the route through `InvoicePdfUrl::download()` at click time.

## Self-Check: PASSED

- FOUND: app/Http/Controllers/InvoicePdfController.php, app/Domain/Invoicing/Support/InvoicePdfUrl.php, tests/Feature/Invoicing/InvoicePdfRouteTest.php
- FOUND commits: f2c1a15, 4eb4f90
