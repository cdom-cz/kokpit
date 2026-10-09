---
status: complete
phase: 05-tasks-and-kanban
source: [05-VERIFICATION.md]
started: 2026-10-09T01:36:53Z
updated: 2026-10-09T08:26:51.437Z
---

## Current Test

[testing complete]

## Tests

### 1. Touch drag on both boards at 375 px width
expected: Drag a card across columns, into an empty column, and scroll horizontally and vertically without an accidental drag. The card lands where dropped; status and position persist after a reload. (05-11-PLAN human-check)
result: pass

### 2. Livewire SPA navigation back to the board
expected: Open the global board, open a card's task page, go back with the panel navigation, drag again. Dragging still works without a full reload. (05-11-PLAN human-check)
result: pass

### 3. Mail rendering in Mailpit (restart the DDEV worker first)
expected: A fictional Partner comments; a task assigned to the Admin is escalated; a task assigned to a second fictional Partner of the same client is escalated; the Admin changes a status. Czech subject and text, correct task reference, working button to the right panel URL (Admin or Partner audience), no HTML tags in the excerpt. (05-16-PLAN human-check)
result: pass

### 4. Czech copy review
expected: Every string added in lang/cs/kokpit.php and lang/cs/enums.php (tasks, partner_tasks, task_board, notifications, billing types and sources, notification events and channels) is natural Czech with consistent terms and no English left; the F-9 labels are accepted or scheduled. (05-17-PLAN human-check)
result: pass

### 5. End-to-end walk as Admin and as a fictional Partner
expected: Partner creates a task and comments; Admin receives bell and mail; Admin answers with an internal and a public comment; Partner sees only the public one; Partner escalates; Admin clears the flag and moves the card on the project board; a second fictional Partner escalates a task assigned to the first Partner, who gets the escalation in the bell and clears the flag from the own task page with no priority or status control present. Every step matches ROADMAP success criteria 1 to 5. (05-17-PLAN human-check)
result: issue
reported: "The Partner should also be able to edit the task description, where applicable. Everything else is OK. (translated from Czech)"
severity: major

### 6. Visual light and dark mode check
expected: Board, task list, task page, Partner pages and the bell. Status and priority badges are distinguishable from the accent colour in both modes, the board card border is visible on light columns, 12 px text remains readable (UI-REVIEW flags F-1, F-2, F-8).
result: pass

### 7. Owner decision on open review and security items
expected: Decide whether to close CR-01 (a deactivated assignee or requester blocks every Admin edit save of that task) and T-05-44 / WR-02 / G-1 (unescaped task title and actor name in notification mails and bell bodies, Admin- and Partner-bound; Partner tags not dropped in CreateTask) with a gap-closure plan before Phase 6 / before shipping, or to accept and track them.
result: pass

## Summary

total: 7
passed: 6
issues: 1
pending: 0
skipped: 0
blocked: 0

## Gaps

- gap_id: G-05-5
  truth: "Partner creates a task and comments; ... Partner clears the flag from the own task page with no priority or status control present (every step matches ROADMAP success criteria 1 to 5)"
  status: failed
  reason: "User reported: The Partner should also be able to edit the task description, where applicable. Everything else is OK."
  severity: major
  test: 5
  artifacts: []
  missing: []
