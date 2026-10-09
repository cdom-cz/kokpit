# Phase 05 — UI Review

**Audited:** 2026-10-09
**Baseline:** 05-UI-SPEC.md (approved, force-approved in auto mode; Flags F-1 to F-9 and the accepted open item for Dimension 1 are known departures, recorded here and not double counted)
**Screenshots:** not captured (no browser available; code-only audit)
**Interaction captures:** off (`workflow.ui_interaction_capture` is false)

---

## Pillar Scores

| Pillar | Score | Key Finding |
|--------|-------|-------------|
| 1. Copywriting | 3/4 | Czech copy is in place for lists, boards, preview, Partner and notifications; task page and edit page still use generic or object-less labels (F-9 still open) |
| 2. Visuals | 3/4 | Board has a clear focal point (column row, bold title link), icon-only buttons carry aria-label and title; the Admin task page header has no object-named actions |
| 3. Color | 2/4 | Primary role is still Amber (F-1) and "Ke kontrole" still maps to the accent role (F-8), so a status badge shares the accent; board overlays are hard-coded rgba values |
| 4. Typography | 2/4 | Board uses 12px (0.75rem) and 12.8px (0.8rem) text, a fifth and sixth size beyond the four declared (F-2 still in the code) |
| 5. Spacing | 3/4 | Mostly on the declared scale; off-scale 0.4rem card gap and 0.75rem paddings remain in the board (F-2); icon hit areas now meet 24px (F-3 resolved) |
| 6. Experience Design | 3/4 | Empty columns, drop-zone minimum, refused-move handling, 404 on foreign or archived preview, escalation error states all built; board drag has no keyboard route in the board itself (F-5, documented alternative exists) |

**Overall: 16/24**

No pillar scores 1, so no BLOCKER is raised. Every pillar below 4 has at least one specific finding.

---

## Top 3 Priority Fixes

1. **Ke kontrole status badge uses the accent role** — the accent is reserved for interactive elements (UI-SPEC Color section), and a status badge in primary colour reads as a link or active state. User impact: the status meaning of "Ke kontrole" is confused with navigation on the board, list, task page and project badge. Fix: in `app/Domain/Projects/Enums/ProjectStatus.php:36` change `self::InReview => 'primary'` to `Color::Fuchsia` (the one-line change scheduled as F-8), and update any test that asserts `'primary'`.

2. **Panel primary colour is Amber, not the declared Indigo** — `app/Providers/Filament/AdminPanelProvider.php:96` holds `'primary' => Color::Amber`. The "K upřesnění" and "Vysoká" warning badges share the hue of the accent, and white text on the Amber primary button measures about 3.2:1 (F-1). User impact: the accent no longer separates primary actions from warning state, and primary button text fails 4.5:1. Fix: switch to Indigo as 02 specifies, or amend 02 with the measured contrast, and re-check both modes at the gate.

3. **Generic and object-less labels on the Admin task pages (F-9 still open at the phase gate)** — the edit page submit still uses Filament's stock "Uložit" (no `getSaveFormAction()` override exists in `app/Filament/Resources/TaskResource/Pages/`), and the task page header still reads "Zobrazit", "Upravit", "Archivovat", "Obnovit" where the contract names "Uložit úkol", "Zobrazit úkol", "Upravit úkol", "Archivovat úkol", "Obnovit úkol". User impact: the primary action of a long edit form does not name what it saves. Fix: add a `getSaveFormAction()` label `kokpit.tasks.actions.save` ("Uložit úkol") on `EditTask`, and object-named header labels on `ViewTask` and `EditTask` via the `tasks.actions.*` keys in `lang/cs/kokpit.php`. This is a label-only change; update tests that assert the old labels.

---

## Detailed Findings

### Pillar 1: Copywriting (3/4)

Built and matching the contract:
- Admin list, quick create, board filters ("Klient", "Řešitel", "Štítek", "Priorita", "Vše", "Zrušit filtry"), card labels ("Eskalováno", "Podúkol :reference", "Otevřít úkol") are in `resources/views/filament/pages/task-board.blade.php` and `lang/cs/kokpit.php`.
- Escalation actions match the contract: "Eskalovat úkol" (warning, `ViewPartnerTask.php:51-53`), "Zrušit eskalaci" (gray, `ViewPartnerTask.php:89-91`, `ViewTask.php:152-154`), confirmation heading "Zrušit eskalaci?" and "Eskalovat úkol?" in `lang/cs/kokpit.php:497-500`.
- Profile save label is set through `getSaveFormAction()` to `kokpit.notifications.profile.save` in `app/Filament/Auth/EditProfile.php:135-137`, which satisfies the accepted open item for Dimension 1.

Findings:
- F-9 still open: `ViewTask.php` header actions and the task edit page keep generic or object-less labels (see Priority Fix 3). Known departure, recorded, not double counted.
- The board filter bar's "Vše" option and labels are correct, but the board heading and Admin list primary button are defined in the Concerns and enums, not checked against the rendered strings (no browser).

### Pillar 2: Visuals (3/4)

- Board card hierarchy is clear: reference line (muted), bold title link, badge row, meta row. Optional parts are omitted, not replaced by placeholders (`task-board.blade.php:96-110`).
- Icon-only buttons have `aria-label` and `title` (`task-board.blade.php:62-78`).
- Preview is a slide-over with a single accent footer link "Otevřít úkol" and the escalation badge leading the body (`ManagesTaskBoard.php:97-108`, `partials/task-preview.blade.php`).
- Finding: the Admin task page header keeps the four-action set with generic labels, so the page's primary anchor is not separated from secondary actions by label (see F-9).
- Finding: card meta row relies on `opacity: 0.8` text at 12px (`task-board.blade.php:97`), which reduces legibility for the due date and assignee. Contrast is unverified without a render.

### Pillar 3: Color (2/4)

- Accent discipline: the Partner "Eskalovat úkol" is warning (`ViewPartnerTask.php:53`), "Zrušit eskalaci" is gray, archive is danger. These match the contract.
- Finding (F-8, still open): `ProjectStatus::getColor()` returns `'primary'` for `InReview` (`app/Domain/Projects/Enums/ProjectStatus.php:36`). This propagates to the board list, task page and preview through `$status->getColor()` (`partials/task-preview.blade.php:26`).
- Finding (F-1, still open): `AdminPanelProvider.php:96` declares `Color::Amber`; the contract declares Indigo.
- Finding: board overlays are hard-coded `rgba(127, 127, 127, 0.1)` for columns and `rgba(255, 255, 255, 0.06)` plus a 1px `rgba(127, 127, 127, 0.3)` border for cards (`task-board.blade.php:33, 50`). These are known (F-2); the contract requires the card border to remain visible on light columns, which the border provides.
- Distribution check: primary-role use is limited to actions and to status "Ke kontrole" (the defect above). No 60/30/10 breach is found in the Admin list or task page code; a populated render is needed to confirm the split.

### Pillar 4: Typography (2/4)

Declared sizes are 14, 16, 24, 30 px and weights 400 and 600.
- `task-board.blade.php:10` and `:24` use `font-size: 0.8rem` (12.8px) for filter labels. Fifth and sixth sizes, known F-2.
- `task-board.blade.php:53, 97` use `font-size: 0.75rem` (12px) for the reference line and meta row. Fifth size, known F-2.
- Column header `<h3>` (`task-board.blade.php:35`) sets `font-weight: 600` without a size, so it inherits the body size. Acceptable, but the contract requires the label role at 14px semibold, which is implicit and not explicit in markup.
- Weights used are 400 and 600 only in the board and preview; no other weight class was found in `app/Filament` or `resources/views/filament`.
- The contract forbids new sizes; the board's 12px and 12.8px text is the observed violation. It is accepted as built (F-2), so it is recorded here and not counted again in the Priority Fixes.

### Pillar 5: Spacing (3/4)

- Declared tokens used on board: 1rem column gap, 0.5rem card gap, 0.75rem column padding, 17rem column width, 4rem minimum column height (`task-board.blade.php:29, 33, 44`). The 4rem minimum and 17rem column width match the contract.
- Off-scale values remain: `gap: 0.4rem` on the card (`task-board.blade.php:50`), `gap: 0.25rem 0.75rem` on the meta row (`:97`), `padding: 0.5rem 0.75rem` on the card (`:50`), `padding: 0.75rem` on the column (`:33`). The contract accepts 12px only as Filament internal control padding (F-2).
- Icon hit areas are 1.5rem (24px) with 0.5rem between them (`task-board.blade.php:61-79`), which meets F-3 and WCAG 2.5.8.
- Preview `dl` grid uses `gap: 0.5rem 1rem` and `gap: 1rem`, which map to the scale (`partials/task-preview.blade.php:14, 21`).

### Pillar 6: Experience Design (3/4)

Covered:
- Empty columns keep a drop zone (`min-height: 4rem`, `task-board.blade.php:44`) and show count 0 without placeholder text, per contract.
- Column cap shows `shown / total` (`task-board.blade.php:37`).
- Filters live in the URL, with a reset button shown whenever any filter is set (`task-board.blade.php:22-26`).
- Preview is authorized and 404 on foreign or archived ids (`ManagesTaskBoard.php:118-130`, docblock); no billing, checklist, tag, comment or history in the preview (U-5).
- Preview description scrolls horizontally inside its container (`partials/task-preview.blade.php:40`), resolving the overflow assumption.
- Title link has `overflow-wrap: anywhere` (`task-board.blade.php:82`), resolving R-2.
- Board page uses `Width::Full` (`ManagesTaskBoard.php:64-66`), resolving R-1.

Findings:
- Keyboard: the board's drag gesture has no keyboard reorder (`wire:sort` only, F-5). The documented alternative is the Status select on the edit page; this is accepted, but the board itself offers no keyboard move.
- Loading state on board: none custom (backstop, accepted in the contract).
- Preview footer uses `kokpit.task_board.card.open` ("Otevřít úkol"); the card open link uses the same key, so the wording is consistent but the two controls share one translation key. Not a defect.
- Escalation error and clear paths are built (`ViewPartnerTask.php`, `ViewTask.php`), consistent with the contract.

---

## Known Departures (recorded, not double counted)

| ID | Status at this review |
|----|-----------------------|
| F-1 Amber primary | Still open (`AdminPanelProvider.php:96`) |
| F-2 Off-scale board sizes and spacing | Still open, accepted as built |
| F-3 Icon hit areas | Resolved (24px targets with 8px gap) |
| F-4 Quick create lands on edit page | Accepted as built |
| F-5 No keyboard board reorder | Still open, documented alternative exists |
| F-6 Inert global search for Partner | Accepted as built |
| F-7 Checklist ticked only on edit page | Accepted as built |
| F-8 "Ke kontrole" accent role | Still open (`ProjectStatus.php:36`) |
| F-9 Generic labels on task pages | Still open at the phase gate; the contract scheduled it for 05-17 |
| R-1 Full width boards | Resolved |
| R-2 Title overflow wrap | Resolved |
| E-5 Refused move toast | Not re-checked in code in this pass; the move path lives in `ManagesTaskBoard.php` and was not audited line by line |

---

## Files Audited

- `.planning/phases/05-tasks-and-kanban/05-UI-SPEC.md`
- `.planning/phases/05-tasks-and-kanban/05-01-SUMMARY.md` to `05-17-SUMMARY.md` (scanned for UI facts)
- `resources/views/filament/pages/task-board.blade.php`
- `resources/views/filament/pages/partials/task-preview.blade.php`
- `app/Filament/Pages/TaskBoardPage.php`
- `app/Filament/Concerns/ManagesTaskBoard.php` (partial: `getMaxContentWidth`, `previewAction`, `previewData`)
- `app/Filament/Resources/TaskResource/Pages/ViewTask.php` (labels and colours)
- `app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php` (escalation actions)
- `app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php` (escalation badge colour)
- `app/Filament/Auth/EditProfile.php` (save label)
- `app/Domain/Projects/Enums/ProjectStatus.php` (status colour)
- `app/Providers/Filament/AdminPanelProvider.php` (primary colour, notification polling)
- `lang/cs/kokpit.php` (escalation and action keys)

Not audited in this pass: rendered output (no browser), the notification e-mail templates, the Partner list and create page layout, and the board drag handler.
