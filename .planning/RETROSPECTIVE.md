# Retrospective

## Milestone: v1 — Kokpit

**Shipped:** 2026-10-30
**Phases:** 20 | **Plans:** 325 | **Tasks:** 691

### What Was Built

- Repository hygiene tooling first, then a platform whose conventions (UUID v7, `timestamptz`, `Money`, gap-free sequences, DB immutability, default-deny Partner scope) are enforced by failing tests.
- The full flow client -> project -> task -> time -> invoice -> payment -> income, with REST API, CNB rates, reports, Stripe payments, cash receipts and unified HTML e-mails.
- An independent Partner audit against a hand-written permission matrix.

### What Worked

- Building conventions and their enforcing tests before features (Phases 1-3) kept later phases consistent.
- Technology spikes (PDF engine, kanban board) before the dependent phases avoided rework.
- A milestone audit with gap closure (inserted Phase 17.1) caught the unbilled-time release gaps before shipping.

### What Was Inefficient

- Phase verification status went stale for most phases (`verification_status=stale`), so the close needed an override instead of a verified closeout.
- UAT and human verification of phases 10.1, 11, 12, 13 and 14 were left pending to the end.
- Phase 10 and 8 grew to 41 and 27 plans; later scope (cash, currency, e-mail) arrived as inserted or appended phases.
- A broken YAML frontmatter in one quick-task summary blocked the acknowledge step.

### Patterns Established

- Declared isolation and access rules per class (`#[AccessRule]`, `#[NotPartnerScoped]`), with architecture tests that fail on undeclared ones.
- Domain Actions as the single write path shared by UI and API.
- Test fakes assembled at runtime so no single line looks like real data.

### Key Lessons

1. Re-verify phases (or refresh their status) as they close; do not leave `human_needed` for the milestone end.
2. Quote backticks and other YAML-special values in SUMMARY frontmatter.
3. Keep gap closure as inserted decimal phases; it kept the audit trail clean.

### Cost Observations

- Model mix: not recorded
- Sessions: not recorded
- Notable: 1906 commits over 25 days

## Cross-Milestone Trends

| Milestone | Phases | Plans | Days | Closeout |
|-----------|--------|-------|------|----------|
| v1 | 20 | 325 | 25 | override_closeout |
