# Phase 12: Partner Audit - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md - this log preserves the alternatives considered.

**Date:** 2026-10-24
**Phase:** 12-Partner Audit
**Areas discussed:** Permission matrix source, Route audit and public allowlist, Canary coverage across channels, CI gate and suite structure

---

## Permission matrix source

| Option | Description | Selected |
|--------|-------------|----------|
| Standalone declaration | One file: entity x role x action, compared with reality | ✓ |
| Derive from #[AccessRule] | Test confirms code against itself | |
| Combination | Attribute for screens, explicit actions for PartnerAllowed | |

**User's choice:** Standalone declaration.
**Notes:** Source of truth: draft from PROJECT/REQUIREMENTS (the brief is not in the repo), owner confirms. Detail: actions and columns.

## Route audit and public allowlist

| Option | Description | Selected |
|--------|-------------|----------|
| Every route has a rule | Vendor routes included, justified on an allowlist | ✓ |
| Only our routes | Vendor routes filtered out | |

**User's choice:** Every route has a rule; real anonymous request plus middleware check; allowlist as a declaration file with reasons.

## Canary coverage across channels

| Option | Description | Selected |
|--------|-------------|----------|
| Matrix over CanaryRegistry | Entity x channel, not-applicable needs a reason | ✓ |
| Write individual tests | Gaps found by hand | |

**User's choice:** Matrix over CanaryRegistry; keep the 24 existing tests; fix leaks found in this phase.

## CI gate and suite structure

| Option | Description | Selected |
|--------|-------------|----------|
| New Audit testsuite | Own CI step and composer ci step | ✓ |
| Inside Isolation and Arch | No CI change | |

**User's choice:** New Audit suite; exceptions via the declaration file; no time limit, full run every time.

## Claude's Discretion

Names and shape of declaration files, parametrisation, and which channels are not-applicable per entity (with reasons).

## Deferred Ideas

None.
