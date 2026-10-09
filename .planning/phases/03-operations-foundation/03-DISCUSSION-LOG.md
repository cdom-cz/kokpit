# Phase 3: Operations Foundation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-08
**Phase:** 3-Operations Foundation
**Areas discussed:** Settings and their editing, Activity log and allowlist, Failure alerts and System page, Spikes / deploy / S3 smoke test

---

## Settings and their editing

| Question | Options | Selected |
|----------|---------|----------|
| Storage | spatie/laravel-settings; own table + DTO; Claude decides | spatie/laravel-settings |
| UI layout | one page with tabs; separate pages | One page with tabs |
| Bank accounts | repeater currency + IBAN + BIC; fixed CZK/EUR/USD; several per currency with default | Repeater, then refined by user (see notes) |
| Currency link | unique currency field per account; optional, several per currency | Unique currency field |
| Numbering patterns | editable tokens with live preview; read-only | Editable with live preview |

**Notes:** User interrupted and supplied screenshots of the previous tool's bank account form with three formats (Europe 1 account number + bank code, Europe 2 IBAN only, World universal with recipient name and bank address). Captured as D-03. Real values in the screenshots are intentionally not recorded.

---

## Activity log and allowlist

| Question | Options | Selected |
|----------|---------|----------|
| Allowlist location | on the model + arch test; central config | On the model + arch test |
| Display | history tab + global overview; global only | History relation manager + global overview |
| System changes | log with causer null and source label; do not log | Log with source label |
| Retention | keep forever; configurable pruning | Keep forever |

---

## Failure alerts and System page

| Question | Options | Selected |
|----------|---------|----------|
| Alert channel | sync e-mail + DB notification; DB only; scheduler-sent | Sync e-mail + DB notification |
| Retry policy | 3 attempts 10 s/60 s/5 min; 5 attempts exponential | 3 attempts, 10 s / 60 s / 5 min |
| Thresholds | fixed in config with OK/Warning/Error; numbers only | Fixed in config with states |
| Later-phase slots | indicator registry with placeholders; hard-coded sections | Registry with placeholders |

---

## Spikes, deploy and S3 smoke test

| Question | Options | Selected |
|----------|---------|----------|
| PDF candidates | Dompdf vs spatie/laravel-pdf; only spatie; include Typst/WeasyPrint | Dompdf vs spatie/laravel-pdf |
| Kanban candidates | custom Livewire + SortableJS vs package; custom only | Custom vs Filament package |
| Spike record | decision in .planning, throwaway code outside app; chosen engine straight into app | Decision record, throwaway code |
| S3 smoke test | Artisan command + CI tests; test only | Artisan command + CI tests |
| Migrations on Zerops | before traffic switch, stop on failure; manual after deploy | Before switch, stop on failure |

---

## Claude's Discretion

Class and file names, tab layout, alert throttling window, indicator interface, heartbeat mechanism, deploy workflow layout and hardening, `zerops.yml` structure, DDEV daemons, Czech wording, spike measurement method.

## Deferred Ideas

None.
