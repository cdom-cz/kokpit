---
schema_version: 1
open_count: 1
waived_count: 0
fixed_count: 1
total_count: 2
last_updated: 2026-10-14T20:47:55.575Z
---

# Broken Windows Ledger

> Cross-phase defect register. With `workflow.windows_enforce` enabled, `/gsd-ship` blocks while `open_count > 0`.
> Waive with `gsd-tools windows waive <id> "<reason>"` (reason required).
> Mark fixed with `gsd-tools windows fixed <id>`.

| id | phase | kind | file | line | description | status | reason | recorded_at | resolved_at |
|----|-------|------|------|------|-------------|--------|--------|-------------|-------------|
| 1 | 07 | deviation | config/scribe.php |  | Scribe docs show untranslated rule sentences on earlier endpoints; see deferred-items.md | fixed |  | 2026-10-14T11:34:16.656Z | 2026-10-14T11:48:33.136Z |
| 2 | 08 | unrun-verify | app/Filament/Pages/TimeReportPage.php |  | 08-09 Task 3 human check not run: open /admin/time-report in DDEV with a year of entries and confirm the SPA progress bar shows while it loads and the filter strip stays usable | open |  | 2026-10-14T20:47:55.575Z |  |

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
  }
]
````
