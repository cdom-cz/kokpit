---
milestone: v1
audited: 2026-10-29 (re-run after UAT of phases 8, 9, 9.1, 10)
status: tech_debt
scores:
  requirements: 142/143   # AP-03 deferred to backlog 999.1, not counted as unsatisfied
  phases: 19/19           # all phases have a VERIFICATION.md, none with failed truths
  integration: 17/21      # wiring links connected; 4 partial (G1-G4)
  flows: 8/9
gaps:
  requirements: []        # no unsatisfied or orphaned requirement
  integration:
    - id: G1
      requirements: [IN-10, TI-08, IN-07]
      detail: "A fully partial-credited invoice, or an unpaid issued proforma, leaves its time entries billed and locked (IssueCreditNote.php:106-123, storno throws credit_nothing)."
    - id: G2
      requirements: [IN-06, PA-01]
      detail: "RecordPayment::refuses() does not reject a manual payment on a converted proforma (books no income)."
    - id: G3
      requirements: [RE-04]
      detail: "UnbilledPerClient dashboard widget covers only the current month; older unbilled time has no dashboard alert."
    - id: G4
      requirements: [P13-05]
      detail: "payment_confirmations row can stay in 'sending' (killed worker / lost job); no reaper, no System indicator, no resend path."
  flows:
    - flow: "Fully credited invoice or unpaid proforma -> time released"
      breaks_at: "IssueCreditNote.php:117-122"
tech_debt:
  - phase: 08-exchange-rates-and-reports
    items:
      - "UAT 7/7 passed. WR-01 orphan file for a failed export record (PruneExpiredReportExports) still noted"
  - phase: 10-invoicing
    items:
      - "UAT 13/13 passed. WR-01/WR-03 still open in code (G1, G2)"
  - phase: 10.1-invoices-stay-fully-editable-until-sent-with-a-manual-unlock
    items:
      - "WR-03/WR-04 deferred: cancelled/converted document not DB-locked; retry after failed lock step may re-mail (at-least-once)"
  - phase: 11-stripe-payments
    items:
      - "Live Stripe and visual checks pending (UAT 0/8); four review warnings recorded"
  - phase: 12-partner-audit
    items:
      - "WR-01 policy registry completeness and WR-02 unprobed abilities still open; docs overclaim (IN-05)"
      - "No hosted CI run yet for the Phase 12 head (no PR open)"
  - phase: 13-unified-e-mail-layout-and-html-e-mail-templates
    items:
      - "Real mail-client look (Outlook, Gmail, mobile) pending (UAT 0/1); WR-01 partly closed (G4)"
  - phase: 14-online-payment-method-and-invoice-currency
    items:
      - "Live Filament editor browser check pending; no UAT file"
  - phase: 07-rest-api
    items:
      - "07-UAT lists 2 major issues; integration check found both closed in code (commit 18a9c2b; ApiTokenResource replaceMountedAction). UAT file not updated."
  - phase: bookkeeping
    items:
      - "REQUIREMENTS.md: EX-01..03, RE-05, DO-01..03, FI-02 still unchecked and 'Gaps Found' although their VERIFICATION.md files find them satisfied"
      - "AP-03 deferred to backlog 999.1"
      - "17-03-SUMMARY.md has no requirements-completed entry for P17-02 (verification marks it satisfied)"
      - "VALIDATION.md still status: draft for phases 4, 6, 11-16 (Nyquist not reconciled)"
      - "ROADMAP.md checkboxes for phases 11 and 12 unchecked; STATE.md status still 'verifying'"
nyquist:
  compliant_phases: [1, 2, 3, 5, 7, 8, 9, 9.1, 10, 10.1, 17]
  partial_phases: [12, 13, 14, 15]
  not_validated_phases: [4, 6, 11, 16]
  missing_phases: []
  overall: partial
---

# Milestone v1 Audit

## Summary

No unsatisfied or orphaned requirement and no phase with a failed must-have. The core flow (time -> draft -> issued invoice -> PDF/e-mail -> payment by transfer, cash or Stripe -> CZK income -> dashboard) is wired end to end in code. Status is `tech_debt` because of four integration warnings, many pending human checks and stale bookkeeping.

## Phase verification

| Phase | Status | Score |
|---|---|---|
| 1-10, 9.1, 10.1, 15, 16, 17 | passed (UAT of 8, 9, 9.1, 10 done) | all must-haves |
| 11 | human_needed | 13/13 |
| 12 | human_needed | 7/8 (CI run on hosted runner missing) |
| 13 | human_needed | 11/11 |
| 14 | human_needed | 10/10 |

## Requirements (3-source cross-check)

- 142 satisfied by VERIFICATION plus SUMMARY frontmatter. AP-03 is explicitly deferred.
- Partial (bookkeeping only): P17-02 missing from SUMMARY frontmatter; EX-01..03, RE-05, DO-01..03, FI-02 satisfied per VERIFICATION but unchecked in REQUIREMENTS.md.
- No orphans.

## Integration findings

Source: gsd-integration-checker, re-run after the UAT commits (static read, no blockers). No code changed since the first run; G1-G4 are all still open.

| ID | Severity | Summary |
|---|---|---|
| G1 | Warning (core value) | Time stays billed/locked after full partial-credit or for an unpaid proforma |
| G2 | Warning | Manual payment accepted on converted proforma, no income booked |
| G3 | Warning | Unbilled dashboard tile is current-month only |
| G4 | Warning | Payment confirmation can stick in `sending`, no reaper or resend |
| G5 | Tech debt | Partner audit proof limited to declared set (P12 WR-01/02) |
| G6 | Info | P10.1 WR-03/WR-04 deferred |

Closed: P7 running-entry overflow, P7 token empty-state button. P17 search leaks nothing to Partner.

## Flows

All complete except "fully credited invoice / unpaid proforma -> time released" (G1) and a partial "unbilled time visible on dashboard" (G3).

## Re-run notes

- G1-G4 re-verified against current code: all STILL OPEN (G1 `IssueCreditNote.php` partial() never releases entries; G2 `RecordPayment::refuses()` ignores converted proformas; G3 `UnbilledPerClient` current month only; G4 no reaper for `sending` confirmations).
- Consequence of G1: the only release path for a fully partial-credited invoice is the manual "release from invoice" action, unavailable once the invoice is sent.
- Consequence of G2: a payment can exist on a converted proforma with no income transaction.
- New findings: none. The re-check was shallow, so this is not proof of absence.
