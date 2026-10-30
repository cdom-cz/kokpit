---
status: complete
phase: 05-tasks-and-kanban
source: [05-VERIFICATION.md]
started: 2026-10-09T10:56:05Z
updated: 2026-10-09T11:16:37.195Z
---

## Current Test

[testing complete]
## Tests

### 1. Browser walk of the Partner description edit
expected: See Current Test. (05-21 Task 3 human-check, extends the earlier UAT test 5)
result: pass

### 2. Re-run of the end-to-end Admin and Partner walk (earlier UAT test 5, gap G-05-5)
expected: Partner creates a task and comments; Admin receives bell and mail; Admin answers with an internal and a public comment; Partner sees only the public one; Partner escalates; Admin clears the flag and moves the card; a second fictional Partner escalates a task assigned to the first Partner, who clears the flag from the own task page with no priority or status control. Plus: the Partner edits the description where the status allows it. Every step matches ROADMAP success criteria 1 to 5.
result: pass

### 3. Mailpit and bell rendering of a markup title
expected: A task whose title holds a Markdown link and an HTML anchor to an example.com address; a Partner comments and edits its description. In the Admin's mails and bell, title and display name read exactly as typed, as plain text: no clickable link, image or styling, no visible backslash or entity in the HTML view and the bell. Includes the new description-change mail for the Admin.
result: pass

### 4. Czech copy review of the strings added by plans 05-20 and 05-21
expected: In lang/cs/kokpit.php: partner_tasks.actions.edit_description, edit_description_heading, edit_description_submit, partner_tasks.notifications.description_saved, tasks.errors.description_stale, description_not_editable, tasks.notifications.changed.description, notifications.profile.helpers.assignment_change_admin, activity.events.description_changed. Natural Czech, terms consistent (ukol, popis, klient), no English left.
result: pass

### 5. Light and dark mode look of the new elements
expected: The "Upravit popis" modal and button on the Partner task page and the Admin's "Zmena ukolu" row on the profile page: gray action button readable in both modes, editor toolbar usable, helper text legible.
result: pass

## Summary

total: 5
passed: 5
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

<!-- Previous session (7 tests, 6 passed, test 5 = issue G-05-5) is in git history; G-05-5 was addressed by plans 05-20 to 05-22 and is re-walked as test 2 here. -->
