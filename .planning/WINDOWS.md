---
schema_version: 1
open_count: 1
waived_count: 0
fixed_count: 0
total_count: 1
last_updated: 2026-10-14T11:34:16.656Z
---

# Broken Windows Ledger

> Cross-phase defect register. With `workflow.windows_enforce` enabled, `/gsd-ship` blocks while `open_count > 0`.
> Waive with `gsd-tools windows waive <id> "<reason>"` (reason required).
> Mark fixed with `gsd-tools windows fixed <id>`.

| id | phase | kind | file | line | description | status | reason | recorded_at | resolved_at |
|----|-------|------|------|------|-------------|--------|--------|-------------|-------------|
| 1 | 07 | deviation | config/scribe.php |  | Scribe docs show untranslated rule sentences on earlier endpoints; see deferred-items.md | open |  | 2026-10-14T11:34:16.656Z |  |

````json
[
  {
    "id": 1,
    "kind": "deviation",
    "phase": "07",
    "file": "config/scribe.php",
    "line": null,
    "description": "Scribe docs show untranslated rule sentences on earlier endpoints; see deferred-items.md",
    "status": "open",
    "reason": "",
    "recorded_at": "2026-10-14T11:34:16.656Z",
    "resolved_at": null,
    "milestone": null
  }
]
````
