---
status: complete
phase: 06-time-tracking
source: [06-VERIFICATION.md]
started: 2026-10-09T18:00:00Z
updated: 2026-10-14T07:01:54.674Z
---

## Current Test

[testing complete]

## Tests

### 1. Top-bar timer persists across SPA navigation
expected: The running-timer pill keeps ticking across three sidebar navigations with no flash of the idle state.
result: pass

### 2. Side panel docking and per-user toggle
expected: At 1440px the "Poslední záznamy" panel docks on the right and shrinks the content; closing it from the bar and reloading keeps it closed.
result: pass

### 3. 375px layout
expected: The panel toggle opens an overlay (Esc, close button, backdrop close it; starts closed on reload); the week grid scrolls horizontally; the board-card start icon has a usable touch target.
result: pass

### 4. Contrast and state colours in light and dark mode
expected: Warning and danger timer pills are legible in both modes (dark-mode danger text measured 4.05:1 by calculation, owner to judge).
result: pass

### 5. Two-click start and stop in a real browser
expected: A timer starts from a task page, list row and board card in at most two clicks; stale-icon case (WR-04) behaves acceptably.
result: pass

### 6. Forgotten-timer bell notice in a live session
expected: A timer left running past the threshold shows one bell notice with an action to the entry; no mail; the timer is not stopped.
result: pass

## Summary

total: 6
passed: 6
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps
