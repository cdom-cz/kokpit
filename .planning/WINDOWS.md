---
schema_version: 1
open_count: 7
waived_count: 0
fixed_count: 1
total_count: 8
last_updated: 2026-10-17T16:20:59.421Z
---

# Broken Windows Ledger

> Cross-phase defect register. With `workflow.windows_enforce` enabled, `/gsd-ship` blocks while `open_count > 0`.
> Waive with `gsd-tools windows waive <id> "<reason>"` (reason required).
> Mark fixed with `gsd-tools windows fixed <id>`.

| id | phase | kind | file | line | description | status | reason | recorded_at | resolved_at |
|----|-------|------|------|------|-------------|--------|--------|-------------|-------------|
| 1 | 07 | deviation | config/scribe.php |  | Scribe docs show untranslated rule sentences on earlier endpoints; see deferred-items.md | fixed |  | 2026-10-14T11:34:16.656Z | 2026-10-14T11:48:33.136Z |
| 2 | 08 | unrun-verify | app/Filament/Pages/TimeReportPage.php |  | 08-09 Task 3 human check not run: open /admin/time-report in DDEV with a year of entries and confirm the SPA progress bar shows while it loads and the filter strip stays usable | open |  | 2026-10-14T20:47:55.575Z |  |
| 3 | 08 | unrun-verify | app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php |  | 08-13 human-check: in-flight state of the download button with a slowed CNB answer and white-on-primary contrast in light and dark mode (UI-SPEC F-1) were not run | open |  | 2026-10-14T21:27:38.175Z |  |
| 4 | 08 | unrun-verify | app/Filament/Widgets/UnbilledPerClient.php |  | 08-15: the Admin dashboard layout (one column below lg, two from lg, widgets side by side), the Pevná cena and Chybí kurz badges in the unbilled table and the contrast of the running-timer text were not looked at in a browser; the markup and figures are asserted by tests | open |  | 2026-10-14T21:39:50.714Z |  |
| 5 | 08 | unrun-verify | app/Domain/Reports/Notifications/ReportExportReadyNotification.php |  | Plan 08-16 human check not run: open the export bell and a CSV export in a Czech spreadsheet in DDEV (stock bell styling with long period text, columns split at semicolon, decimal commas) | open |  | 2026-10-14T21:55:19.520Z |  |
| 6 | 08 | unrun-verify | resources/views/filament/pages/time-report.blade.php |  | Plan 08-14 task 3 human-check not run: the 375px visual pass of /admin/time-report (horizontal scroll inside the tree, toggles with Tab/Enter/Space and focus ring, long names wrapping) and the Alpine expand/collapse behaviour are untested in a browser | open |  | 2026-10-14T22:36:17.164Z |  |
| 7 | 08 | unrun-verify | app/Domain/Reports/Jobs/BuildReportExport.php |  | 08-21: the XLSX workbook (frozen header pane, auto filter, CZK-missing comments, number formats) was verified by reading the package, not by opening it in a spreadsheet application | open |  | 2026-10-14T23:35:44.136Z |  |
| 8 | 10 | unrun-verify | tests/Feature/Invoicing/DraftEditorTest.php |  | Plan 10-25 human check not run: draft with 15 lines at 375 px and desktop width (items table scrolls inside its wrapper, no page overflow, delete buttons at least 24 px) | open |  | 2026-10-17T16:20:59.421Z |  |

````json
[
  {
    "id": 1,
    "kind": "deviation",
    "phase": "07",
    "file": "config/scribe.php",
    "line": null,
    "description": "Scribe docs show untranslated rule sentences on earlier endpoints; see deferred-items.md",
    "status": "fixed",
    "reason": "",
    "recorded_at": "2026-10-14T11:34:16.656Z",
    "resolved_at": "2026-10-14T11:48:33.136Z",
    "milestone": null
  },
  {
    "id": 2,
    "kind": "unrun-verify",
    "phase": "08",
    "file": "app/Filament/Pages/TimeReportPage.php",
    "line": null,
    "description": "08-09 Task 3 human check not run: open /admin/time-report in DDEV with a year of entries and confirm the SPA progress bar shows while it loads and the filter strip stays usable",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-10-14T20:47:55.575Z",
    "resolved_at": null,
    "milestone": null
  },
  {
    "id": 3,
    "kind": "unrun-verify",
    "phase": "08",
    "file": "app/Filament/Resources/ExchangeRateResource/Pages/ListExchangeRates.php",
    "line": null,
    "description": "08-13 human-check: in-flight state of the download button with a slowed CNB answer and white-on-primary contrast in light and dark mode (UI-SPEC F-1) were not run",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-10-14T21:27:38.175Z",
    "resolved_at": null,
    "milestone": null
  },
  {
    "id": 4,
    "kind": "unrun-verify",
    "phase": "08",
    "file": "app/Filament/Widgets/UnbilledPerClient.php",
    "line": null,
    "description": "08-15: the Admin dashboard layout (one column below lg, two from lg, widgets side by side), the Pevná cena and Chybí kurz badges in the unbilled table and the contrast of the running-timer text were not looked at in a browser; the markup and figures are asserted by tests",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-10-14T21:39:50.714Z",
    "resolved_at": null,
    "milestone": null
  },
  {
    "id": 5,
    "kind": "unrun-verify",
    "phase": "08",
    "file": "app/Domain/Reports/Notifications/ReportExportReadyNotification.php",
    "line": null,
    "description": "Plan 08-16 human check not run: open the export bell and a CSV export in a Czech spreadsheet in DDEV (stock bell styling with long period text, columns split at semicolon, decimal commas)",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-10-14T21:55:19.520Z",
    "resolved_at": null,
    "milestone": null
  },
  {
    "id": 6,
    "kind": "unrun-verify",
    "phase": "08",
    "file": "resources/views/filament/pages/time-report.blade.php",
    "line": null,
    "description": "Plan 08-14 task 3 human-check not run: the 375px visual pass of /admin/time-report (horizontal scroll inside the tree, toggles with Tab/Enter/Space and focus ring, long names wrapping) and the Alpine expand/collapse behaviour are untested in a browser",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-10-14T22:36:17.164Z",
    "resolved_at": null,
    "milestone": null
  },
  {
    "id": 7,
    "kind": "unrun-verify",
    "phase": "08",
    "file": "app/Domain/Reports/Jobs/BuildReportExport.php",
    "line": null,
    "description": "08-21: the XLSX workbook (frozen header pane, auto filter, CZK-missing comments, number formats) was verified by reading the package, not by opening it in a spreadsheet application",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-10-14T23:35:44.136Z",
    "resolved_at": null,
    "milestone": null
  },
  {
    "id": 8,
    "kind": "unrun-verify",
    "phase": "10",
    "file": "tests/Feature/Invoicing/DraftEditorTest.php",
    "line": null,
    "description": "Plan 10-25 human check not run: draft with 15 lines at 375 px and desktop width (items table scrolls inside its wrapper, no page overflow, delete buttons at least 24 px)",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-10-17T16:20:59.421Z",
    "resolved_at": null,
    "milestone": null
  }
]
````
