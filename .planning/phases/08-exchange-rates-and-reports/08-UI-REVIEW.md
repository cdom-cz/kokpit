# Phase 08 — UI Review

**Audited:** 2026-10-15
**Baseline:** 08-UI-SPEC.md (approved 2026-10-14; carried flags F-1, F-2, F-11, F-12 applied)
**Screenshots:** not captured (no dev server answered on localhost ports 3000, 5173 or 8080). This is a code-only audit: Tailwind and inline-style token audit, copy audit against the contract, state and access review. Visual findings below are inferred from markup and must be confirmed at the visual gate.
**Interaction captures:** off (`workflow.ui_interaction_capture` is false for this run)

---

## Pillar Scores

| Pillar | Score | Key Finding |
|--------|-------|-------------|
| 1. Copywriting | 4/4 | Czech strings match the contract verbatim across rates, report, exports, dashboard, Partner and PDF strings |
| 2. Visuals | 2/4 | Time report tree: the name column has no width floor while seven numeric cells are `nowrap`, so task titles wrap per word (known owner todo, cause confirmed in code) |
| 3. Color | 2/4 | Panel primary is still Amber (carried flag F-1); white on the primary buttons measures about 3.2:1, below AA for 14px text; `primary` and `warning` share a hue |
| 4. Typography | 3/4 | Sizes and weights are on the declared scale, but two placeholder labels use `opacity: 0.7`, below the contract floor of 0.8 |
| 5. Spacing | 3/4 | Mostly on the 4px scale; the effective-rate block uses off-contract values `0.75rem`, `1.75rem` and `2.5rem` (0.75rem is named in F-2) |
| 6. Experience Design | 3/4 | Every state has a surface and the failure paths are wired; the E3 deadline order departs from the contract, and a stale export row only refreshes on manual reload |

**Overall: 17/24**

Scoring basis: a score of 4 means no contract deviation was found in that pillar. Pillars 2 and 3 are scored below 3 because of a known owner-reported defect and an unresolved contract flag that fails a contrast check.

---

## Top 3 Priority Fixes

1. **Time report tree is hard to read (known: owner todo `2026-10-14-redesign-unreadable-time-report-layout`)** — task titles wrap one word per line, rows become very tall, and the three hierarchy levels are hard to tell apart; users cannot scan the unbilled view that is the core value of the page — Fix path is the existing todo: give the Název column a `min-width` (for example 16rem) and remove `white-space: nowrap` from the amount cells, or move the seven numeric columns into a grouped layout. Not re-scoped here; the todo owns the work.
2. **Primary colour and contrast (carried flag F-1)** — the accent is Amber while the contract declares Indigo, and white 14px semibold text on the primary buttons ("Stáhnout dnešní kurzy", "Doplnit historii", "Zkontrolovat úkol") is about 3.2:1, below the 4.5:1 AA minimum — Decide at project level: switch `AdminPanelProvider` primary to `Color::Indigo` as 02 declares, then re-measure both modes; if Amber stays, amend 02 and darken the button text role. The gate must record the measured ratio.
3. **Off-contract spacing and dimmed placeholder text in the effective-rate block and tree** — `padding: 0 1rem 0.75rem …` and `gap: 0.5rem 2.5rem` (time-report.blade.php lines 363–364) and `opacity: 0.7` on the "Bez projektu" / "Bez úkolu" placeholders (lines 310, 314) break the scale and the 0.8 opacity floor — Replace `0.75rem` with `0.5rem` or `1rem`, `2.5rem` with `2rem` or `1.5rem`, `1.75rem` with `1.5rem`, and set the placeholder opacity to `0.8`; no new markup is needed.

---

## Detailed Findings

### Pillar 1: Copywriting (4/4)

Strings checked against the Copywriting Contract:

- Rates (`lang/cs/kokpit.php` lines 515–625): "Kurzy ČNB", "Stáhnout dnešní kurzy", "Doplnit historii", both empty states, all toasts, backfill errors and the `failed` / `not_published` texts match verbatim.
- Time report (lines 728–831): filters, summary tiles, notes, tree columns, "Pevná cena" tooltip, "Chybí kurz" tooltip, effective-rate block and row cap match. The "Exportovat report" group, its three items and the "Exporty" link match.
- Exports: columns, statuses, "Stáhnout export", queued toast, gone toast, failure reason, bell texts match. The subheading uses the `:days` placeholder via `ReportExport::ttlDays()`.
- Dashboard (lines 177–246): hours stats, unbilled widget, deadlines plural forms (`{1}`, `[2,4]`, `[5,*]`), plan count plural forms, partner texts match.
- PDF labels (`lang/cs/work_report.php`): match the Czech column of the PDF copy table.

Minor (not scored against the pillar):
- `kokpit.exports.gone` is shown as a danger toast title with no body. The contract gives one sentence, so this is compliant.
- The contract lists the single word "Zavřít" as a non-blocking copy flag at checker sign-off. It is not present in the audited strings.

### Pillar 2: Visuals (2/4)

- **Time report tree (B3), known:** `resources/views/filament/pages/time-report.blade.php` line 65 sets `white-space: nowrap` on every numeric cell. The Název `th` (lines 282–285) has no width or `min-width`, and the name span uses `overflow-wrap: anywhere` (line 310). With the 60rem table floor (line 247) the name column gets the leftover width, so titles break per word. This matches the owner's screenshot description and is covered by the existing todo; it is not re-listed as new work.
- **Hierarchy:** levels are separated only by indent (1.5rem, 3rem, 4.5rem) plus font weight (600 for client and project, 400 below). With the weight drop and no level marker, client and project rows look alike at narrow widths.
- **Positive:** the summary tiles lead with the unbilled value and the billing badges (info / success with lock icon / gray), as the contract's visual anchor requires.
- **Admin dashboard (E3 / E4):** the deadlines cell and the plan block read clearly; the overdue state carries the badge, the text line and the date colour (not colour alone).
- **Known, not re-listed:** no overdue highlight on `/admin/tasks` and no collapsible dashboard widgets; both are pending todos.

### Pillar 3: Color (2/4)

- **Carried flag F-1 (open):** the UI-SPEC records that the panel primary is Amber (`Color::Amber`) while 02 declares Indigo. Per the contract's own note, this could not be re-verified in `AdminPanelProvider` during this audit; the finding is taken from UI-SPEC F-1.
- **Contrast:** the contract's own measurement is about 3.2:1 for white text on the Amber primary buttons. That is below 4.5:1 for 14px semibold text (AA). The contract accepts this as a flag; the audit does not, because the three buttons are the page's primary actions.
- **Hue collision:** `primary` (Amber, Partner "Ke kontrole", active period/split toggles) and `warning` (Chybí kurz, running timer) are the same hue. The contract avoids both on one surface, which holds in the markup, but the user cannot tell "action" from "warning" by colour alone. Icons and labels carry the meaning.
- **Positive:** hard-coded hex values: none in the audited Blade files. Colours come from `var(--primary-600)`, `var(--danger-600)` and `var(--gray-500)`. The grayscale PDF palette matches the contract hex values.
- **60/30/10:** the report and the rates page keep the accent to the active toggles and the one primary action, as specified.

### Pillar 4: Typography (3/4)

- **Sizes:** screen markup uses 14px (`0.875rem`, 7 uses) and 30px (`1.875rem`, the tile values), plus Filament's stock sizes. Declared sizes are 14, 16, 24, 30; no off-scale size found.
- **Weights:** screen markup uses 600 (20 uses) and 400 (the tree `level < 2 ? 600 : 400` expression). PDF uses 400 and 700, as declared.
- **Finding (contract breach):** `resources/views/filament/pages/time-report.blade.php` lines 310 and 314 set `opacity: 0.7` on the "Bez projektu" and "Bez úkolu" placeholder spans. The contract states secondary text never goes below 0.8 opacity. Fix: set `opacity: 0.8`.
- **Warning (verify):** `$mutedStyle` and the effective-rate labels use `opacity: 0.8` (lines 68, 355, 357) on top of `var(--gray-500)`. That is inside the contract, but it stacks two dimming steps. Measure the contrast of the muted lines in both modes at the gate.
- **Tabular numerals:** applied to tree cells, tile values and the rates rate column, as the contract requires.

### Pillar 5: Spacing (3/4)

Custom markup spacing values in the report and widget views (counts from the audit grep): `0.5rem` (33), `1rem` (28), `0.25rem` (12), plus the layout values below.

- **Off-contract (named in F-2):** `time-report.blade.php` line 363 uses `padding: 0 1rem 0.75rem …`. The contract forbids `0.75rem` in new custom markup.
- **Off-contract:** line 364 `gap: 0.5rem 2.5rem` (2.5rem is 40px, not a scale token) and line 363 `calc(… + 1.75rem)` (28px, not a token).
- **Compliant:** tree indents are `1.5rem`, `3rem`, `4.5rem` (24, 48, 72px), exactly as the contract specifies. The 60rem table floor equals 960px.
- **Compliant:** tomorrow-plan and deadlines cells use `0.25rem` / `0.5rem` / `1rem` only, per the widget comments.
- **Verify:** the period button group (`time-report.blade.php` line 135) is `display: flex` with no wrap. On a 375px phone the three period buttons plus gaps should fit in the 343px content width, but this was not measured; check at the mobile gate.

### Pillar 6: Experience Design (3/4)

**States covered:**
- Empty: rates (no rates, day without list), report (no entries; tiles show `0:00` and `—`), exports (no exports), unbilled widget, deadlines widget, plan block, Partner tasks and projects (each with its own empty state, plus the stock Partner fallback in `Dashboard::content()`).
- Error: download failure (fixed danger toast, nothing stored), backfill range validation as field errors, PDF generation failure (`report()` + fixed toast, no partial file), export failure (bell notification without exception text), gone file (danger toast from the row action).
- Loading: export rows poll every 10s only while a row is pending; widgets poll with `visible.60s`; stock Filament in-flight state on the download action.
- Access: report, rates and exports are closed to Partners at boot; Partner widgets declare `canView()` and hide for the Admin; Admin-only widgets are refused at boot.

**Findings:**
- **Contract drift (E3, "Nejbližší termíny"):** UI-SPEC E3 says "by nearest due date, then by priority". `app/Filament/Widgets/UpcomingDeadlines.php` lines 116–123 order by priority first, then due date. The code comment records an owner requirement, but the contract was not amended. Either amend E3 or change the query. This is a WARNING: the behaviour is documented, the contract is stale.
- **Stale export row:** the exports table polls only while a row is `Pending` (`app/Filament/Resources/ReportExportResource.php` line 113). After a gone-file click, the row stays until a manual reload. The contract says it "disappears on the next refresh", which the current poll does not guarantee. WARNING.
- **Accent focus and keyboard:** tree toggles are real `<button>` elements with `aria-expanded`, `aria-controls` and a per-row accessible name; Enter and Space work natively. Positive.
- **Custom range:** "Vlastní" is a navigation link, not a reveal; the date inputs appear after the server round trip. The contract describes a reveal. Info-level deviation, behaviour is correct.
- **Unit column:** `unit_amount` uses Filament's `->numeric()`, which groups thousands. The contract lists "1000" as an example value. Info only.
- **Partner dashboard (not visually verified):** the `in_review` highlight is inline style (per U-11) and the pinned column lists are checked. A runtime check of the rendered HTML for "Never on a Partner surface" strings was not run in this audit.

**Known, not re-listed:** the time report layout (Pillar 2), overdue highlight on `/admin/tasks` and collapsible dashboard widgets are pending todos. The owner's "today's plan under hours stats" todo is also pending; the current Admin plan block is tomorrow's plan, as the contract specifies (U-9).

---

## Contract Coverage Summary

| Surface | Status in code | Notes |
|---------|----------------|-------|
| A CNB rates overview | Built | Stats, actions, filters, empty states match; Stav colour carries icon and text |
| B Time report | Built, layout defect (known) | Tree readability; spacing and opacity breaches in the effective block |
| C Exports list | Built | Poll only while pending (see Pillar 6) |
| D PDF work report | Built (template reviewed; header and footer come from the page script in `WorkReportPdf`) | Running header and page footer are not in the Blade; rendering not checked |
| E Admin dashboard | Built | E3 order departs from contract |
| F Partner dashboard | Built | `in_review` highlight inline, as specified |
| G System slot and CNB alert | Not audited | Outside the files named in the audit brief |
| H Export bell | Built | Ready and failed notifications exist (`app/Domain/Reports/Notifications/`) |

---

## Registry Safety

Not applicable. The UI-SPEC declares no shadcn and no third-party registries (`components.json` is not part of this phase). No registry audit ran.

---

## Files Audited

- `.planning/phases/08-exchange-rates-and-reports/08-UI-SPEC.md`
- `app/Filament/Pages/TimeReportPage.php`
- `app/Filament/Pages/Dashboard.php`
- `resources/views/filament/pages/time-report.blade.php`
- `app/Filament/Widgets/HoursStats.php`
- `app/Filament/Widgets/UnbilledPerClient.php`
- `app/Filament/Widgets/UpcomingDeadlines.php`
- `app/Filament/Widgets/TomorrowPlan.php`
- `app/Filament/Widgets/PartnerTasks.php`
- `resources/views/filament/widgets/tomorrow-plan.blade.php`
- `resources/views/filament/widgets/partner-tasks.blade.php`
- `resources/views/filament/widgets/upcoming-deadlines-cell.blade.php`
- `resources/views/filament/widgets/unbilled-per-client-cell.blade.php`
- `resources/views/filament/widgets/unbilled-per-client-footer.blade.php`
- `app/Filament/Resources/ExchangeRateResource.php`
- `app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php`
- `app/Filament/Resources/ExchangeRateResource/Widgets/ExchangeRateStats.php`
- `app/Filament/Resources/ReportExportResource.php`
- `app/Filament/Resources/ReportExportResource/Pages/ListReportExports.php`
- `resources/views/pdf/work-report.blade.php`
- `app/Domain/Reports/WorkReport/WorkReportPdf.php` (decoration and page script only)
- `lang/cs/kokpit.php` (dashboard, rates, exports, report blocks)
- `lang/cs/work_report.php`
- `.planning/todos/pending/*.md` (four owner todos, for known-issue context)

Not read (outside the brief's file list or not needed for the score): `AdminPanelProvider` (F-1 taken from UI-SPEC), `PartnerProjects.php` (column pinning spot-checked only), System page (Surface G), `WorkReportPdf` header and footer markup in full.

Screenshot gate: `.planning/ui-reviews/` gitignore gate ran (captures excluded). No captures were taken.
