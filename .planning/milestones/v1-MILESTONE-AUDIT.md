---
milestone: v1
audited: 2026-10-30 (re-run after Phase 17.1 and quick-261030-l02)
status: tech_debt
scores:
  requirements: 143/144   # AP-03 deferred to backlog 999.1, not counted as unsatisfied
  phases: 20/20           # all phases have a VERIFICATION.md, none with failed truths
  integration: 19/22      # G1 (invoice half) and G2 closed; G3, G4 and the proforma half of G1 partial
  flows: 8/9
gaps:
  requirements: []        # no unsatisfied or orphaned requirement
  integration:
    - id: G1b
      requirements: [TI-08, IN-07]
      detail: "Time held by an unpaid issued proforma is not released (deliberately deferred, 17.1-CONTEXT D-04). Fully credited invoices now release (closed)."
    - id: G3
      requirements: [RE-04]
      detail: "UnbilledPerClient widget covers only the current month (UnbilledPerClient.php:178-194); older unbilled time has no dashboard alert."
    - id: G4
      requirements: [P13-05]
      detail: "payment_confirmations row can stay in 'sending' (killed worker / lost job); no reaper, no System indicator, no resend path."
  flows:
    - flow: "Unpaid proforma -> time released"
      breaks_at: "no release path (deferred D-04)"
    - flow: "Unbilled time visible on dashboard"
      breaks_at: "months before the current one (G3)"
closed:
  - "G1 (fully partial-credited invoice): IssueCreditNote::partial() -> creditedInFull() -> EntryBilling::release(), DB guard kokpit_invoice_fully_credited (migration 2026_10_30_000200)"
  - "G2: RecordPayment::refuses() rejects payment on a converted proforma on manual and Stripe paths"
tech_debt:
  - phase: 08-exchange-rates-and-reports
    items:
      - "WR-01 orphan file for a failed export record (PruneExpiredReportExports)"
  - phase: 10.1-invoices-stay-fully-editable-until-sent-with-a-manual-unlock
    items:
      - "G6: WR-03/WR-04 deferred pending owner decision: cancelled/converted document not DB-locked; retry after failed lock step may re-mail"
  - phase: 11-stripe-payments
    items:
      - "human_needed: live Stripe and visual checks; four review warnings recorded"
  - phase: 12-partner-audit
    items:
      - "G5: WR-01 policy registry completeness and WR-02 unprobed abilities open; docs overclaim (IN-05)"
      - "human_needed: no hosted CI run for the phase head"
  - phase: 13-unified-e-mail-layout-and-html-e-mail-templates
    items:
      - "human_needed: real mail-client look (Outlook, Gmail, mobile)"
  - phase: 14-online-payment-method-and-invoice-currency
    items:
      - "human_needed: live Filament editor browser check; no UAT file"
  - phase: 17-task-project-and-search-ux-refinements
    items:
      - "quick-261030-l02 accent-insensitive search mirrors Filament 5.11 internals (recheck on every Filament upgrade); new deploy prerequisite pg_trgm and unaccent extensions"
      - "LinkableTasks Admin-only scope not verified by the integration check"
  - phase: bookkeeping
    items:
      - "AP-03 deferred to backlog 999.1"
      - "17-03-SUMMARY.md has no requirements-completed entry for P17-02 (verification marks it satisfied)"
      - "ROADMAP.md checkboxes for phases 11-17.1 unchecked/stale"
      - "DB-backed tests of Phase 17.1 not run by this audit; run the full suite in DDEV after merge"
nyquist:
  compliant_phases: [1, 2, 3, 5, 7, 8, 9, 9.1, 10, 10.1, 17]
  partial_phases: [4, 12, 13, 14, 15]    # draft with nyquist_compliant false
  not_validated_phases: [6, 11, 16]      # draft, flag true, not reconciled by validate-phase
  missing_phases: [17.1]
  overall: partial
---

# Milestone v1 Audit

## Summary

No unsatisfied or orphaned requirement, no phase with a failed must-have, no blocker. Phase 17.1 closed gaps G1 (invoice half) and G2. Status stays `tech_debt`: G3, G4, the deferred proforma half of G1, open Phase 12/10.1 review items, pending human checks (phases 11-14) and unreconciled Nyquist validation.

## Phase verification

| Phase | Status | Score |
|---|---|---|
| 1-10, 9.1, 10.1, 15, 16, 17, 17.1 | passed | all must-haves |
| 11 | human_needed | 13/13 |
| 12 | human_needed | 7/8 |
| 13 | human_needed | 11/11 |
| 14 | human_needed | 10/10 |

## Requirements (3-source cross-check)

- All 143 mapped v1 requirements are checked in REQUIREMENTS.md and verified; SUMMARY frontmatter for IN-10, TI-08, IN-07, IN-06, PA-01 comes from 17.1-01..03. AP-03 deferred.
- Partial (bookkeeping only): P17-02 missing from SUMMARY frontmatter.
- No orphans.

## Integration findings (gsd-integration-checker, static read)

| ID | Status | Severity | Summary |
|---|---|---|---|
| G1 | closed (invoice) / open (proforma) | warning | Proforma time not released, deferred D-04 |
| G2 | closed | - | refuses() covers both payment paths |
| G3 | open | warning | Dashboard unbilled tile current month only |
| G4 | open | warning | `sending` confirmation can stick, no reaper |
| G5 | open | tech debt | Partner audit proof limited to declared set |
| G6 | open | info | P10.1 WR-03/WR-04 await owner decision |

No new cross-phase breaks from phases 13-17: per-client e-mail toggle enforced in action, job and UI; payment confirmation and cash receipt wired from RecordPayment incl. card path; new models under AdminOnly/CashReceipt policies; accent-insensitive search leaks nothing to Partner.

## Flows

All complete except "unpaid proforma -> time released" (deferred) and "unbilled time visible on dashboard" for past months (G3).

## Caveats

Static read only; no tests or migrations executed. Closure of G1/G2 rests on code and test sources; confirm with the full DDEV suite after merge.
