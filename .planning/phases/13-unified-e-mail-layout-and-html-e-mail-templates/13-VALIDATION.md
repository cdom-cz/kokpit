---
phase: "13"
slug: "unified-e-mail-layout-and-html-e-mail-templates"
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-23"
---

# Phase 13 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Source: the Validation Architecture section of 13-RESEARCH.md.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest ^5.3 on PHPUnit ^13.3 |
| **Config file** | `phpunit.xml` (`MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`), `tests/Pest.php` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Mail tests/Feature/Invoicing/SendInvoiceEmailTest.php` |
| **Full suite command** | `ddev exec vendor/bin/pest` (plus `composer lint`, `composer stan`) |
| **Estimated runtime** | ~30 seconds quick, full suite longer |

---

## Sampling Rate

- **After every task commit:** the targeted test files of that task, plus `ddev exec vendor/bin/pint --test`
- **After every plan wave:** `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Clients tests/Feature/Tasks tests/Feature/Operations tests/Isolation tests/Arch`
- **Before `/gsd-verify-work`:** full suite, `composer lint`, `composer stan` and `scripts/check-sensitive.sh` green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

Filled in from the plans' `<automated>` commands. Requirement IDs are the phase-local P13-01 to P13-11 proposed by the research.

| Requirement | Behavior | Test Type | Automated Command | File Exists |
|-------------|----------|-----------|-------------------|-------------|
| P13-01 | Every e-mail renders through the `kokpit` theme | feature | `ddev exec vendor/bin/pest tests/Feature/Mail/MailThemeTest.php` | ❌ W0 |
| P13-02 | Brand header/footer, logo as inline cid, fail-closed settings read | feature | `ddev exec vendor/bin/pest tests/Feature/Mail/MailBrandTest.php` | ❌ W0 |
| P13-03 | Invoice mail HTML + text, literal Markdown triggers, button gate | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/SendInvoiceEmailTest.php tests/Feature/Mail/InvoiceMailHtmlTest.php` | ✅ update / ❌ W0 |
| P13-04 | Payment confirmation content, full vs partial | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/PaymentConfirmationMailTest.php` | ❌ W0 |
| P13-05 | One row and one mail per payment, no mail on replay or carry | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php` | ❌ W0 |
| P13-06..09 | Invitation, reset, task and alert e-mails on the theme, inert markup | feature + isolation | `ddev exec vendor/bin/pest tests/Isolation/NotificationMarkupTest.php tests/Feature/Clients tests/Feature/Tasks tests/Feature/Operations` | ✅ update |
| P13-10 | `payment_mail` cs/en key parity | unit | `ddev exec vendor/bin/pest tests/Feature/Localisation` | ✅ / ❌ W0 |
| P13-11 | Job, model and canary registries, no new route | arch | `ddev exec vendor/bin/pest tests/Arch` | ✅ update |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Mail/MailThemeTest.php`, `MailBrandTest.php`, `InvoiceMailHtmlTest.php`
- [ ] `tests/Feature/Invoicing/PaymentConfirmationMailTest.php`, `PaymentConfirmationTriggerTest.php`, `PaymentConfirmationSchemaTest.php`
- [ ] Shared fixture storing a fictional logo PNG generated at runtime with GD (no binary in git)
- [ ] Updates: `SendInvoiceEmailTest.php`, `PartnerPasswordResetTest.php`, `JobContractTest.php`, `ModelDeclarationTest.php`, `CanaryRegistryTest.php`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Visual quality in real mail clients | P13 (all) | Needs real clients | Open each e-mail in Mailpit and in Gmail, Outlook, Apple Mail and a mobile client; compare against the layout decisions |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
