---
phase: "11"
slug: "stripe-payments"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-18"
---

# Phase 11 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3.2 on PHPUnit 13.3, DDEV PostgreSQL + Redis |
| **Config file** | `phpunit.xml`, `tests/Pest.php` (existing) |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Payments` |
| **Full suite command** | `ddev exec vendor/bin/pest` (gate: `ddev composer ci`) |
| **Estimated runtime** | ~120 seconds (full suite) |

---

## Sampling Rate

- **After every task commit:** the touched file's targeted test plus `ddev exec vendor/bin/pest tests/Arch`
- **After every plan wave:** `ddev exec vendor/bin/pest tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch`
- **Before `/gsd-verify-work`:** full suite and `ddev composer ci` green, `scripts/check-sensitive.sh` clean
- **Max feedback latency:** 180 seconds

---

## Per-Task Verification Map

Filled by the planner from PLAN.md tasks. Requirement-level coverage (from RESEARCH.md):

| Requirement | Behavior | Test Type | Automated Command | File Exists |
|-------------|----------|-----------|-------------------|-------------|
| PA-01 | Link created in a job after commit; Stripe failure never blocks issuing and alerts Admin | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CreatePaymentLinkTest.php` | ❌ W0 |
| PA-02 | Pay button in PDF, e-mail, Admin and Partner detail; pay page states | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Payments/PayPageTest.php tests/Feature/Payments/PayButtonOutputsTest.php` | ❌ W0 |
| PA-03 | Global, client and document switches | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardSwitchTest.php` | ❌ W0 |
| PA-04 | Link deactivated on payment, storno, credit note; regenerate; one live link | feature + DB | `ddev exec vendor/bin/pest tests/Feature/Payments/LinkLifecycleTest.php` | ❌ W0 |
| PA-05 | Webhook signature, unique event, async processing, replay | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/StripeWebhookTest.php` | ❌ W0 |
| PA-06 | Matching payment creates payment and income, deactivates link | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/MatchPaymentTest.php tests/Feature/Invoicing` | ❌ W0 |
| PA-07 | Duplicate payment stored without income; concurrency | feature + concurrency | `ddev exec vendor/bin/pest tests/Feature/Payments/DuplicatePaymentTest.php tests/Concurrency/PaymentMatchConcurrencyTest.php` | ❌ W0 |
| PA-08 | Unmatched and refund records, overview, dashboard tile, System slot, prune | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/CardPaymentsOverviewTest.php tests/Feature/Payments/PruneWebhookCallsTest.php tests/Feature/Operations/HealthIndicatorsTest.php` | ❌ W0 |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Support/FakeStripeGateway.php` and `StripeGateway` binding
- [ ] `tests/Support/StripeWebhookSigner.php` (secret assembled at runtime from fragments)
- [ ] `tests/Feature/Payments/*` files listed above
- [ ] `tests/Concurrency/payment-match-worker.php` and `PaymentMatchConcurrencyTest.php`
- [ ] Edits to `JobContractTest`, `HealthRegistryTest`, `ActivityAllowlistTest`, `ModelDeclarationTest`, `CanaryRegistry`, `EnvExampleTest`, `RepositoryFilesTest`
- [ ] Spike: pay route renders the simple layout outside `/admin`
- [ ] `ddev composer require stripe/stripe-php:^22.0`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real Stripe test-mode payment through a Payment Link | PA-01, PA-06 | Needs a live Stripe test account and keys, which never enter the repository | Use the Stripe CLI to forward webhooks to a local instance and pay with a Stripe test card |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 180s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
