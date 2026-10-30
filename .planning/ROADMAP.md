# Roadmap: Kokpit

## Milestones

- ✅ **v1** — Phases 1-17.1 (shipped 2026-10-30) — [archive](milestones/v1-ROADMAP.md)

## Phases

<details>
<summary>✅ v1 (Phases 1-17.1) — SHIPPED 2026-10-30</summary>

- [x] Phase 1: Repository Hygiene (11/11 plans) — completed 2026-10-08
- [x] Phase 2: Platform Foundation (13/13 plans) — completed 2026-10-08
- [x] Phase 3: Operations Foundation (19/19 plans) — completed 2026-10-08
- [x] Phase 4: Clients and Projects (21/21 plans) — completed 2026-10-09
- [x] Phase 5: Tasks and Kanban (22/22 plans) — completed 2026-10-09
- [x] Phase 6: Time Tracking (14/14 plans) — completed 2026-10-14
- [x] Phase 7: REST API (20/20 plans) — completed 2026-10-14
- [x] Phase 8: Exchange Rates and Reports (27/27 plans) — completed 2026-10-29
- [x] Phase 9: Documents and Finance (28/28 plans) — completed 2026-10-29
- [x] Phase 9.1: Transactions API (INSERTED)
- [x] Phase 10: Invoicing (41/41 plans) — completed 2026-10-29
- [x] Phase 10.1: Invoices stay fully editable until sent, with a manual unlock (INSERTED)
- [x] Phase 11: Stripe Payments (20/20 plans)
- [x] Phase 12: Partner Audit (9/9 plans)
- [x] Phase 13: Unified e-mail layout and HTML e-mail templates (9/9 plans)
- [x] Phase 14: Switchable payment method and independent invoice currency
- [x] Phase 15: Cash payment method for invoices and cash receipt documents
- [x] Phase 16: Per-client e-mail sending toggle
- [x] Phase 17: Task, project and search UX refinements
- [x] Phase 17.1: Close gap G1/G2, release time on full partial credit and reject payments on converted proformas (INSERTED)

Full phase details: [milestones/v1-ROADMAP.md](milestones/v1-ROADMAP.md). Requirements: [milestones/v1-REQUIREMENTS.md](milestones/v1-REQUIREMENTS.md). Audit: [milestones/v1-MILESTONE-AUDIT.md](milestones/v1-MILESTONE-AUDIT.md).

</details>

## Backlog

### Phase 999.1: API Timer (BACKLOG)

**Goal**: An API client starts and stops the timer and reads the currently running one, with the same rules as the UI (auto-stop of the running timer on start, one running timer per user) and idempotent start
**Requirements**: AP-03
**Origin**: Removed from Phase 7 on 2026-10-14 (see `.planning/phases/07-rest-api/07-CONTEXT.md`, deferred)
**Plans**: 0 plans
