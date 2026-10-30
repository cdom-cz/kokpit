# Milestones

## v1 (Shipped: 2026-10-30)

**Phases completed:** 20 phases (1-17.1, including inserted 9.1, 10.1 and 17.1), 325 plans, 691 tasks
**Timeline:** 2026-10-06 → 2026-10-30 (25 days), 1906 commits
**Code:** about 84k lines of PHP in `app/`, about 142k lines in `tests/`
**Requirements:** 143/144 satisfied; AP-03 (API timer) deferred to backlog 999.1
**Closeout type:** override_closeout (see Known verification overrides)

**Delivered:** The whole flow of a freelancer's CRM/ERP: client -> project -> task -> tracked time -> billing -> invoice -> payment -> income, with a restricted Partner view proven by an automated audit.

**Key accomplishments:**

1. Repository hygiene before any other code: a sensitive-content scanner, lefthook pre-commit hook, gitleaks and a CI aggregator keep secrets and real data out of the public AGPL repository (Phase 1).
2. A platform whose conventions fail tests when broken: UUID v7 keys and `timestamptz` everywhere, exact `Money`, gap-free number sequences proven under parallel processes, database immutability triggers, and default-deny Partner isolation with a canary harness (Phases 2-3).
3. The working-day flow: clients with ARES lookup, projects with keys and billing terms, tasks and kanban, an always-visible timer, a token-secured `/api/v1` for time entries and transactions, CNB exchange rates, reports and dashboard (Phases 4-9.1).
4. Time becomes an issued, payable invoice in one pass: numbered invoices, proformas and credit notes, PDF with QR, e-mail, send-driven lock with Admin unlock, payment methods (transfer, online, cash with receipts) and per-document currency (Phases 10, 10.1, 14, 15).
5. Stripe payments with signed idempotent webhooks, automatic matching, duplicate and unmatched payment handling, and unified HTML e-mails including payment confirmations (Phases 11, 13).
6. An independent Partner audit with a hand-written permission matrix (every route, screen, Livewire component and endpoint has an explicit role rule), and the closing of the unbilled-time gaps G1/G2 (Phases 12, 17.1).

**Known gaps (tech debt, see `milestones/v1-MILESTONE-AUDIT.md`):**

- G1b: time held by an unpaid issued proforma is not released (deliberately deferred, 17.1 D-04).
- G3: the `UnbilledPerClient` widget covers only the current month.
- G4: a `payment_confirmations` row can stay in `sending` (no reaper, indicator or resend path).
- PHPStan `function.alreadyNarrowedType` in `ResetAdminTwoFactorCommand.php:39`.

**Known verification overrides:** 16 newly acknowledged, 0 carried forward from a prior close (see STATE.md Deferred Items). Most phases show a stale verification status; UAT of phases 10.1, 11, 12, 13 and verification of phases 11-14 still await human confirmation.

**Archives:** [roadmap](milestones/v1-ROADMAP.md), [requirements](milestones/v1-REQUIREMENTS.md), [audit](milestones/v1-MILESTONE-AUDIT.md), [phases](milestones/v1-phases/), [quick tasks](milestones/v1-quick/README.md).

**Tag:** not created (owner's decision).

---
