---
phase: "07"
slug: "rest-api"
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
created: "2026-10-14"
---

# Phase 7 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Internet client to /api/v1 | Bearer token only, Admin tokens with abilities | Time entries, lookups, tokens |
| Visitor to /docs | Public, generated documentation | Fictional examples only |
| Admin panel to token issuing | Password and TOTP re-confirmation | Plain token shown once |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation | Status |
|-----------|----------|-----------|----------|-------------|------------|--------|
| T-07-01 | Elevation of privilege | Sanctum session guard | high | mitigate | Token-only auth, guard list empty (config/sanctum.php), panel session gets 401 | closed |
| T-07-02 | Disclosure / DoS | Malformed bearer token | high | mitigate | findToken refuses non-uuid id part; whereUuid routes; clean 404 | closed |
| T-07-03 | Elevation of privilege | Non-Admin token | high | mitigate | Token valid only for an active Admin (ApiServiceProvider) | closed |
| T-07-04 | Elevation of privilege | Ability bypass | high | mitigate | abilities middleware per route group, 403 walk in ApiAccessTest | closed |
| T-07-05 | DoS | Request flooding | medium | mitigate | Per-token read/write throttles, 429 with Retry-After | closed |
| T-07-06 | Spoofing | Stolen or replayed token | high | mitigate | Hashed storage, expiry, revocation by row delete | closed |
| T-07-07 | Disclosure | Time entry JSON | high | mitigate | Explicit resource keys, no money fields, contract test | closed |
| T-07-08 | Disclosure | Public docs content | high | mitigate | No response calls, no DB, placeholder token, fictional examples | closed |
| T-07-09 | DoS | Docs routes | medium | mitigate | throttle:api-docs per IP | closed |
| T-07-10 | Disclosure | Try It Out | medium | mitigate | try_it_out disabled | closed |
| T-07-11 | Disclosure | Generated docs committed | medium | mitigate | Gitignore rules and test | closed |
| T-07-12 | Elevation of privilege | Third-party CDN scripts on docs | medium | accept | Owner decision OD-6b; docs routes carry no session middleware | closed (accepted) |
| T-07-13 | Disclosure | 500 before first generation | low | mitigate | EnsureApiDocsGenerated answers 404 | closed |
| T-07-14 | Elevation of privilege | Partner on token screen | high | mitigate | AdminOnly access rule, server-side abort_unless | closed |
| T-07-15 | Disclosure | Plain token leak | high | mitigate | Locked Livewire property, shown once, cleared on close | closed |
| T-07-16 | Spoofing | Stolen session mints a token | high | mitigate | Password and fresh TOTP re-confirmation, 5/min throttle | closed |
| T-07-17 | Tampering | Revoke another user's token | medium | mitigate | Owner filter in RevokeApiToken | closed |
| T-07-18 | Elevation of privilege | Forged ability | high | mitigate | Abilities typed as ApiAbility enum, tryFrom | closed |
| T-07-19 | Repudiation | Token operations | low | mitigate | Activity rows for create and revoke | closed |
| T-07-20 | Tampering | SQL injection via filters | high | mitigate | Allow-lists, bound values | closed |
| T-07-21 | DoS | Non-uuid on uuid column | medium | mitigate | Validation before any query | closed |
| T-07-22 | DoS | Oversized pages | medium | mitigate | per_page max 200, cursor length cap | closed |
| T-07-23 | Disclosure | Money in list items | high | mitigate | Same explicit resource for collection | closed |
| T-07-24 | Tampering | Mass assignment | high | mitigate | Allow-listed payload keys, forceFill | closed |
| T-07-25 | Tampering | Billed entry edits | high | mitigate | Row lock plus billed check, KP001 trigger, 409 entry_locked | closed |
| T-07-26 | DoS | Interval overflow on PATCH started_at | medium | mitigate | Overflow guard covers create and PATCH of ended_at/duration_seconds, NOT PATCH of started_at alone (verified: 500) | open - below high threshold (non-blocking) |
| T-07-27 | Tampering | Offset-less instants | medium | mitigate | INSTANT regex requires Z or offset | closed |
| T-07-28 | Tampering | Running UI timer | medium | mitigate | 422 / 409 entry_running guards | closed |
| T-07-29 | Tampering | Forged context | high | mitigate | context() consistency checks, composite FKs | closed |
| T-07-30 | DoS | Oversized or deep external | medium | mitigate | 4096 bytes, 8 levels | closed |
| T-07-31 | Tampering | {} vs [] confusion | medium | mitigate | Raw body decoded as objects, object cast, CHECK constraint | closed |
| T-07-32 | Tampering | external smuggled or rendered | medium | mitigate | Not fillable or logged, dropped from panel form | closed |
| T-07-33 | Tampering | external on billed entry | high | mitigate | Frozen by KP001 trigger, 409 | closed |
| T-07-34 | Repudiation | API writes labelled as web | medium | mitigate | LabelApiActivitySource middleware | closed |
| T-07-35 | Tampering | Client-chosen source label | low | mitigate | Label reads no input, CHECK constraint | closed |
| T-07-36 | Disclosure | Lookups leak rates or contacts | high | mitigate | Allow-listed resources, canary test | closed |
| T-07-37 | Tampering | LIKE wildcards or commas | low | mitigate | escapeLike, value splitting disabled | closed |
| T-07-38 | Disclosure | Archived work by key guessing | low | mitigate | selectable* scopes, 404 | closed |
| T-07-39 | DoS | Non-uuid on lookups | medium | mitigate | Checked before any query | closed |
| T-07-40 | Disclosure | Cross-token replay | high | mitigate | Unique (token_id, key), lookups per token | closed |
| T-07-41 | Tampering | Key reuse with another body | medium | mitigate | Request hash compare, 422 | closed |
| T-07-42 | Tampering | Concurrent duplicates | high | mitigate | Atomic insertOrIgnore claim, 409 | closed |
| T-07-43 | DoS | Garbage keys, table growth | medium | mitigate | Key regex, TTL, purge, write throttle | closed |
| T-07-44 | DoS | Failed request poisons key | low | mitigate | Release on failure, CHECK on stored status | closed |
| T-07-45 | Tampering | Duplicates under concurrent retries | high | mitigate | 8-process concurrency test with mutation run | closed |
| T-07-46 | DoS | Unbounded table growth | low | mitigate | Daily purge on one server | closed |
| T-07-47 | Tampering | Purge deletes live keys | medium | mitigate | Deletes only expires_at < now() | closed |
| T-07-48 | DoS | Oversized batches | medium | mitigate | Max 100 items, one write per batch against limit | closed |
| T-07-49 | Tampering | Retried batch duplicates | high | mitigate | EnsureIdempotency on batch, 2xx replay | closed |
| T-07-50 | Tampering | Item type confusion | low | mitigate | Non-object item fails under entry | closed |
| T-07-51 | Tampering | Bad item poisons good ones | medium | mitigate | Per-item transaction, no outer one | closed |
| T-07-52 | Disclosure | Hosts, tokens, paths in docs | high | mitigate | Hygiene test scans generated files | closed |
| T-07-53 | Tampering | Failed generation ships stale docs | medium | mitigate | Last init command, exit 2 on broken route, manifest test | closed |
| T-07-54 | Elevation of privilege | Guest or Partner reaching API data | high | mitigate | Canary walk over every route | closed |
| T-07-55 | DoS | Deploy fails on docs after migration | low | accept | Owner decision D-17 | closed (accepted) |
| T-07-56 | Elevation of privilege | Later route without guard, ability, throttle | high | mitigate | Data-driven route walk with self-check | closed |
| T-07-SC-02 | Tampering | composer install (scribe) | high | mitigate | Version locked, MIT, legitimacy audit, check-licenses | closed |
| T-07-SC-04 | Tampering | composer install (query-builder) | high | mitigate | Version locked, MIT, legitimacy audit, check-licenses | closed |
| T-07-SC-other | Tampering | composer install (other plans) | low | accept | No package added | closed (accepted) |

*Status: open · closed · open — below high threshold (non-blocking)*
*Verification: mitigations confirmed by reading code (auditor, 2026-10-14); the full Pest suite was green (2907). T-07-26 was additionally reproduced with a throwaway probe: PATCH started_at 0001-01-01T00:00:00Z on a finished entry returns 500 (expected 422). Suggested fix: validate the interval against the stored end whenever started_at changes, or catch the 22003 overflow in UpdateTimeEntry, and add a PATCH test.*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-07-01 | T-07-12 | Stock Scribe theme loads CDN scripts (fonts, jsDelivr, unpkg, cdnjs); docs routes have no session middleware; residual risk is a compromised CDN script on the same origin | Owner (OD-6b) | 2026-10-14 |
| AR-07-02 | T-07-55 | Deploy may fail after the migration when docs generation fails; migrations stay backward compatible, previous version keeps serving | Owner (D-17) | 2026-10-14 |
| AR-07-03 | T-07-SC | No package added in the other plans | Plan | 2026-10-14 |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-14 | 59 | 58 | 1 (non-blocking) | gsd-security-auditor |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed (blocking threshold: high)
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-14 (one medium finding, T-07-26, open and non-blocking)
