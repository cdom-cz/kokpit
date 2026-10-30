---
phase: quick-261030-gcw
plan: 01
subsystem: signal
tags: [signal, filament, css, type-ahead]
requires: []
provides:
  - Signal task suggestion panel spans the full width of the text input
affects: [resources/views/livewire/signal/day.blade.php]
key-files:
  modified:
    - resources/views/livewire/signal/day.blade.php
    - tests/Feature/Signal/SignalTaskLinkTest.php
requirements: [P17-07]
status: complete
commits: 2
plan_head_before: ed17e60dfbe6dc23a650a32db1d0647336bfbeef
plan_head_after: e8481d4bf1e403e1411aca31f78d8a0d5b413ff9
actuals:
  tokens: 400
  tasks: 2
  commits: 2
---

# Quick 261030-gcw: Widen the task hint in the Signal form Summary

The Signal add-field suggestion listbox now carries Filament's `fi-width-none` modifier, which removes the forced 14rem max-width of `fi-dropdown-panel`, so its inline `width: 100%` matches the text input.

## Tasks

| Task | Name | Commit |
| ---- | ---- | ------ |
| 1 | Let the suggestion panel span the full input width | ee8d3e2 |
| 2 | Pin the full-width panel class in the listbox test | e8481d4 |

## Deviations from Plan

None - plan executed exactly as written.

## Verification

- Worktree gates passed: class count 1 / old class 0, inline `top: 100%; left: 0; width: 100%;` still 1, no raw Blade echo, `php -l` on the test, `fi-width-none` present in the test.
- Not run in the worktree (per CLAUDE.md): Pest and Pint (DDEV, DB needed), the compiled-theme grep (`public/css/filament/filament/app.css` is gitignored, main checkout only), and the browser human-check.

## Pending after merge

- `grep -qF '.fi-dropdown-panel.fi-width-none' public/css/filament/filament/app.css`
- `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Signal/SignalTaskLinkTest.php` and `ddev exec vendor/bin/pint --test tests/Feature/Signal/SignalTaskLinkTest.php`
- Human check: suggestion panel edges line up with the text input; arrow keys, Enter and Escape still work.

## Known Stubs

None.

## Self-Check: PASSED

Both commits exist (ee8d3e2, e8481d4); both modified files present.
