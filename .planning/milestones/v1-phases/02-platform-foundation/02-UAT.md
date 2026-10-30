---
status: complete
phase: 02-platform-foundation
source: [02-VERIFICATION.md]
started: 2026-10-07T21:30:00Z
updated: 2026-10-07T22:48:14.445Z
---

## Current Test

[testing complete]

## Tests

### 1. Clean-clone start (FND-01, FND-20)
expected: Follow only README.md and .env.example in an empty clone; all services healthy, daemons RUNNING, bucket kokpit-dev exists, login page opens.
result: pass

### 2. Czech walk-through (FND-11, FND-17, FND-20)
expected: With KOKPIT_REQUIRE_ADMIN_2FA=true and APP_LOCALE=cs in the local .env, sign in as the Admin, complete TOTP set-up (QR code, manual key, recovery codes), sign out and sign in again with the TOTP challenge, open the dashboard and the profile; then sign in as a Partner test account. Check light and dark mode. All visible text is Czech (no English labels, no raw translation keys), dates are j. n. Y H:i in Europe/Prague, Czech diacritics render in Inter, the QR and recovery-code layout is usable, dark-mode contrast is acceptable. The Admin cannot reach the dashboard before TOTP is set up. The Partner reaches the dashboard without being forced into TOTP and sees the neutral empty state.
result: pass

### 3. CI green after the owner pushes (FND-13)
expected: Open a pull request and read the Hygiene run. Jobs scan, workflow-lint, tests, static-analysis and dependencies succeed on ubuntu-24.04 (setup-php installs PHP 8.5, postgres:18 and redis:7 services start, Pest including the concurrency child processes passes) and CI Passed is green and accepted by the organisation ruleset. The workflow triggers only on push to main and on pull_request.
result: pass

### 4. GitHub private vulnerability reporting enabled
expected: The "Report a vulnerability" button exists, because SECURITY.md names it as the only reporting channel.
result: pass

### 5. Owner decision on the primary colour (UI-SPEC A-3)
expected: AdminPanelProvider still sets Color::Amber while the approved UI-SPEC requires Indigo. Either apply the one-line change to Indigo and re-check dark-mode contrast, or accept the deviation with an override entry (must_have, reason, accepted_by, accepted_at).
result: pass
override:
  must_have: "UI-SPEC A-3: primary colour is Indigo"
  reason: "Owner keeps Color::Amber; the deviation from the approved UI-SPEC is accepted. Known trade-off: Amber with white text is about 3.2:1, below WCAG AA."
  accepted_by: "Petr Gräf"
  accepted_at: 2026-10-08

### 6. Owner decision on Admin password recovery (UI-SPEC A-1)
expected: No web or CLI path recovers a forgotten Admin password; kokpit:admin:reset-2fa resets TOTP only. Accept for Phase 2 (web reset arrives with the Phase 4 invitation mail flow) or schedule a CLI password reset before the first real deployment.
result: pass
note: "Accepted for Phase 2. Owner corrected an earlier slip: both the web reset and a CLI password reset that generates a reset link arrive in Phase 4, not Phase 2."

### 7. Backstop truth from 02-03: timestamp ties broken by UUID v7 id
expected: Confirm the convention is acceptable as documentation-only until the first sorted list exists; the first list feature must add a held-out test.
result: pass

### 8. Backstop truth from 02-08: Czech collation
expected: Confirm deferral to Phase 4; the first sortable text column must bring a collation test (ch sorts after h).
result: pass

## Summary

total: 8
passed: 8
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps
