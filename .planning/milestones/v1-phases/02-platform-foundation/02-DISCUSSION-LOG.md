# Phase 2: Platform Foundation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-07
**Phase:** 2-Platform Foundation
**Areas discussed:** Partner isolation, Admin install and 2FA, Money and rounding, Sequence allocator

---

## Partner isolation

| Question | Options | Selected |
|----------|---------|----------|
| Partner-to-client link | 1 account = 1 client; user-client pivot (M:N); account bound to a contact | 1 account = 1 client |
| Data-layer default-deny | Scope + Policy fail-closed; Policy only with Gate::before; separate Partner Resources | Scope + Policy, fail-closed |
| Registry test | Mandatory interface/attribute; manual allowlist; dynamic HTTP test as Partner | Mandatory interface/attribute |
| Canary data in Phase 2 | Test-only tenant model; minimal Client model now; abstract harness only | Test-only tenant model |

---

## Admin install and 2FA

| Question | Options | Selected |
|----------|---------|----------|
| Install command | Interactive + flags; one-time link; generated password | Interactive + flags |
| 2FA enforcement | Mandatory after first login; optional; mandatory for Partners too | Mandatory after first login |
| 2FA mechanism and recovery | Native Filament + recovery codes; TOTP without codes; third-party package | Native Filament + recovery codes |

---

## Money and rounding

| Question | Options | Selected |
|----------|---------|----------|
| Money value object | Thin own VO over brick/money; fully own VO; brick/money directly | Thin own VO over brick/money |
| Rounding | HALF_UP once per line; HALF_EVEN; round only on the invoice total | HALF_UP once per line |
| Rate and exchange-rate precision | NUMERIC outside Money; integer x 10^6; per-currency dynamic minor units | NUMERIC(20,10) outside Money |

---

## Sequence allocator

| Question | Options | Selected |
|----------|---------|----------|
| Mechanism | Counters table + FOR UPDATE; PostgreSQL sequence; MAX()+1 with unique index | Counters table + FOR UPDATE |
| Scope and reset | Generic scope key; typed columns; one table per type | Generic scope key |

---

## Claude's Discretion

Package migration UUID strategy, morph map names, schema-test mechanics, CI layout, PHPStan level, licence allowlist tooling, DDEV details.

## Deferred Ideas

None.
