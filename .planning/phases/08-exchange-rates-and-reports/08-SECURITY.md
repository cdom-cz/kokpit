---
phase: "08"
slug: "exchange-rates-and-reports"
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
created: "2026-10-15"
---

# Phase 8 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Partner session to the panel | A Partner uses the same panel as the Admin | Must never see measured time, rates, prices, finance, exchange rates, exports or another client's data |
| Application to the CNB rates API | Outbound HTTPS to a fixed operator-configured host | Public rate lists, validated field by field before storage |
| Application to private storage | Exports live on the private disk, served only through a checked Livewire action | Report files (time, money), owner-scoped |
| User input to filters and exports | Filter values, client and project names reach SQL, HTML, CSV, XLSX and PDF | Escaped output, explicit string cells, no formulas |
| Composer dependencies | dompdf/dompdf and openspout/openspout added in this phase | Licence gate and audit |

---

## Threat Register

72 threats (T-08-01 to T-08-71 and T-08-SC, which is identical in all 22 plans and counted once): 24 high, 32 medium, 16 low, none critical. All CLOSED. Evidence is by file and test name in the audit run of 2026-10-15; 923 Phase 8 mitigation tests passed, 2 skipped (PDF layout checks needing `pdftotext`, not security tests).

| Threat ID | Category | Severity | Disposition | Mitigation (summary) | Status |
|-----------|----------|----------|-------------|----------------------|--------|
| T-08-01 | Info disclosure | medium | mitigate | `ExchangeRate` denies Partners, AdminOnlyPolicy | closed |
| T-08-02 | Tampering | medium | mitigate | UNIQUE and three CHECKs on `exchange_rates`, numeric(20,10) | closed |
| T-08-03 | Info disclosure | high | mitigate | Partner widgets read the scoped queries only | closed |
| T-08-04 | Info disclosure | high | mitigate | Pinned Partner field sets (`FIELDS`) | closed |
| T-08-05 | Elevation of privilege | medium | mitigate | Widget access rule, `canView` refuses Admin and clientless | closed |
| T-08-06 | Tampering | medium | mitigate | No raw HTML output in widget views | closed |
| T-08-07 | Tampering | medium | mitigate | Licence gate: reviewed owner ruling, alias normalised | closed |
| T-08-08 | Tampering | high | mitigate | dompdf ^3.1 locked, licence check and audit clean | closed |
| T-08-09 | Info disclosure / SSRF | high | mitigate | Dompdf remote, PHP and JS off, chroot on `resources/views/pdf` | closed |
| T-08-10 | Tampering | medium | mitigate | PDF template uses `{{ }}` only | closed |
| T-08-11 | Tampering | high | mitigate | CNB rows validated by pattern, any bad row refuses the list | closed |
| T-08-12 | Tampering | high | mitigate | List stored under its own `validFor`, never a later day | closed |
| T-08-13 | Info disclosure | medium | mitigate | Failures carry enum and status only, no body or host | closed |
| T-08-14 | Tampering | medium | mitigate | insert-or-ignore plus unique key | closed |
| T-08-15 | Spoofing | low | accept | See Accepted Risks Log | closed (accepted) |
| T-08-16 | Tampering | high | mitigate | Single rounding point in `Money`, boundary arch test | closed |
| T-08-17 | Tampering | high | mitigate | Per-unit quotes divided by unit amount | closed |
| T-08-18 | Tampering | medium | mitigate | Lookup `rate_date <= day`, missing rate throws, never zero | closed |
| T-08-19 | Info disclosure | low | mitigate | Partner context cannot read rates | closed |
| T-08-20 | Info disclosure | high | mitigate | Work report source joins no pricing table | closed |
| T-08-21 | Denial of service | medium | mitigate | Grouped rows, 256M memory floor | closed |
| T-08-22 | Info disclosure | high | mitigate | Sources authorise Admin or system only | closed |
| T-08-23 | Info disclosure | medium | mitigate | Entry descriptions never selected | closed |
| T-08-24 | Info disclosure | high | mitigate | `ReportExport` owner scope and policy | closed |
| T-08-25 | Tampering | medium | mitigate | Storage path CHECK, server-generated path | closed |
| T-08-26 | Tampering | low | mitigate | Download name from stored period only | closed |
| T-08-27 | Denial of service (operator) | medium | mitigate | Alert only on the final run, throttled per key | closed |
| T-08-28 | Denial of service | high | mitigate | Alert sent synchronously, not through the queue | closed |
| T-08-29 | Info disclosure | medium | mitigate | Alert body holds dates only | closed |
| T-08-30 | Denial of service | medium | mitigate | Non-working days skipped, stored day not refetched | closed |
| T-08-31 | Info disclosure | high | mitigate | Time report page access rule, Admin only, leak tests | closed |
| T-08-32 | Tampering | medium | mitigate | Filters validated (uuid, client-tied project, Prague day) | closed |
| T-08-33 | Info disclosure | medium | mitigate | Reader authorises Admin or system | closed |
| T-08-34 | Tampering | medium | mitigate | SQL billing chain pinned to the PHP resolvers by parity tests | closed |
| T-08-35 | Tampering | medium | mitigate | No raw output in the report view | closed |
| T-08-36 | Tampering | medium | mitigate | Health lag counted in Czech working days | closed |
| T-08-37 | Info disclosure | low | mitigate | Health detail is Czech copy only | closed |
| T-08-38 | Info disclosure | high | mitigate | Private disk, no public or signed URL | closed |
| T-08-39 | Elevation of privilege | high | mitigate | Download re-checks access, owner scope and expiry | closed |
| T-08-40 | Denial of service | low | accept | See Accepted Risks Log | closed (accepted) |
| T-08-41 | Tampering / DoS | low | mitigate | Prune deletes the stored path only | closed |
| T-08-42 | Info disclosure | medium | mitigate | Daily system-run prune on one server | closed |
| T-08-43 | Elevation of privilege | medium | mitigate | Rates overview and stats Admin only | closed |
| T-08-44 | Info disclosure | low | mitigate | Fixed failure copy on manual download | closed |
| T-08-45 | Denial of service | low | mitigate | Manual download skips an already stored day | closed |
| T-08-46 | Tampering | medium | mitigate | Per-currency lines, missing-rate badge | closed |
| T-08-47 | Denial of service | low | mitigate | Constant query count, leaf-row cap | closed |
| T-08-48 | Tampering | low | mitigate | Prague day predicates, DST tests | closed |
| T-08-49 | Info disclosure | high | mitigate | Admin widgets never mount for a Partner | closed |
| T-08-50 | Tampering | medium | mitigate | No raw output in dashboard widgets | closed |
| T-08-51 | Tampering | low | mitigate | Dashboard figures equal the report | closed |
| T-08-52 | Tampering | high | mitigate | Spreadsheet cells always explicit strings, formula prefix neutralised | closed |
| T-08-53 | Info disclosure | high | mitigate | Export job re-validates stored filters, owner-scoped record | closed |
| T-08-54 | Info disclosure / Tampering | medium | mitigate | Notifications escape values once | closed |
| T-08-55 | Denial of service | medium | mitigate | Timeout 240 s, cursor reader, temp-file streaming | closed |
| T-08-56 | Repudiation | low | mitigate | `failed()` marks the record and rings the bell | closed |
| T-08-57 | Tampering | medium | mitigate | openspout ^4.32 locked, licence check clean | closed |
| T-08-58 | Tampering | high | mitigate | Year answer validated whole before storing, never after the run's day | closed |
| T-08-59 | Denial of service | low | mitigate | One heal claim per Prague day, 31-day lookback | closed |
| T-08-60 | Denial of service | medium | mitigate | Backfill range limits in form and job | closed |
| T-08-61 | Denial of service | low | mitigate | Job timeout below the supervisor timeout | closed |
| T-08-62 | Tampering | medium | mitigate | Backfill insert-or-ignore, never rewrites stored lists | closed |
| T-08-63 | Tampering | medium | mitigate | Effective rate refuses zero seconds, conversion day capped at today | closed |
| T-08-64 | Info disclosure | high | mitigate | Deadlines and plan widgets Admin only | closed |
| T-08-65 | Info disclosure | low | mitigate | Plan read through owner-scoped Signal models | closed |
| T-08-66 | Tampering | medium | mitigate | No raw output in deadlines and plan views | closed |
| T-08-67 | Tampering | high | mitigate | XLSX sheets formula-free, scanned for `<f>` in tests | closed |
| T-08-68 | Info disclosure | high | mitigate | Work report download re-checks access, fixed failure toast | closed |
| T-08-69 | Info disclosure | high | mitigate | Phase 8 leak block in `TimeLeakTest` with an `in_review` canary | closed |
| T-08-70 | Info disclosure | medium | mitigate | `scripts/check-sensitive.sh --all`, lefthook, CI | closed |
| T-08-71 | Repudiation | low | mitigate | `RepositoryFilesTest` pins the documented classes | closed |
| T-08-SC | Tampering (supply chain) | high | mitigate | Only 08-03 and 08-16 touch composer files, licence check and audit clean | closed |

*Status: open · closed · open — below high threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above `workflow.security_block_on` (high) count toward threats_open*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-08-01 | T-08-15 | The CNB host comes only from operator configuration (`services.cnb.base_url`); no user input selects host or path, TLS verification stays on. Low severity. | plan 08-04 disposition, confirmed by the security audit | 2026-10-15 |
| AR-08-02 | T-08-40 | A Livewire download holds the file in memory; a freelancer's export volumes are small. Above about 20 MB a controller route is the documented fallback. Low severity. | plan 08-11 disposition, confirmed by the security audit | 2026-10-15 |

*Accepted risks do not resurface in future audit runs.*

---

## Audit Notes (non-blocking)

- Plans 08-03, 08-06, 08-20 and 08-22 have no "Threat Flags" section in their SUMMARY (process gap only); their threats were checked directly in code.
- `composer audit` exits 1 only for two abandoned packages that predate the phase; it reports no security advisories.
- Hardening idea, not a gap: `SpreadsheetCells` neutralises `= + - @ TAB CR` as planned; it does not cover a leading newline, `|` or `%`.

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-15 | 72 | 72 | 0 | gsd-security-auditor (secure-phase, ASVS 1) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-15
