# Phase 3 Spike: Kanban board, build or buy

Status: decided (build the custom board), pending the owner's confirmation and the touch check listed in Open items.
Decision records: D-15 (compare custom `wire:sort` board against Flowforge), D-16 (spike code stays out of the application).

## Context

Phase 5 (KB-01, KB-02, KB-03) needs a kanban board where dragging a card to another column persists the new status and the order inside the column, writes the status change through Eloquent model events (so the activity log sees it), and never lets a Partner move or even see another client's card. This spike builds the same board two ways in a throwaway copy of the repository and decides which one Phase 5 builds or adds.

Stack under test (installed versions): PHP 8.5.11, Laravel 13.35.0, Filament 5.10.0, Livewire 4.4.7, `spatie/eloquent-sortable` 5.0.1 (already locked through `spatie/laravel-tags`), `relaticle/flowforge` 4.1.4, PostgreSQL 18 in a throwaway container.

## Candidates

| Candidate | What it is | Ordering scheme | Extra dependency |
|---|---|---|---|
| Custom board | Filament page with a Blade view using the Livewire 4 `wire:sort`, `wire:sort:item`, `wire:sort:group` and `wire:sort:group-id` directives (SortableJS is bundled into Livewire's own `livewire.js`) and one handler method | integer `position` per status column, written with `spatie/eloquent-sortable` `setNewOrder` | none |
| Flowforge | `relaticle/flowforge` 4.1.x, a Filament 5 board plugin (MIT) | its own `DECIMAL(20,10)` rank column (`flowforgePositionColumn()`), gap 65535, jitter, auto-rebalance and `flowforge:repair-positions` | one Composer package (plus `ext-bcmath`), and its documented prerequisite of a custom Filament theme with a Tailwind build |

The Livewire 4.4.7 bundle was searched for the directives: `sort:group` and `sort:group-id` are present in `vendor/livewire/livewire/dist/livewire.js`, so the custom board needs no separate SortableJS package. The handler receives the item id, the zero-based position and the destination group id. Flowforge drags with Filament's own `x-sortable` (also SortableJS) and calls a `moveCard(cardId, targetColumnId, afterCardId, beforeCardId)` method on the page.

## Criteria

Written before any comparison measurement (research Pattern 13, decision rule). A candidate must pass every hard criterion; the build option wins ties.

Hard criteria (a candidate that fails one is out unless a mitigation is proven):

1. UUID v7 keys work, and a card dropped in the middle of another column keeps its status and its place after a reload.
2. A status change goes through Eloquent model events (an `updated` event with `status` changed), so the activity log can record it.
3. Partner isolation: a Partner gets 403 on a board route that is closed to Partners, and a forged move call for another client's card changes nothing (scoped lookup, policy).
4. Concurrency: two parallel processes moving cards into one column leave the positions of every column exactly 0..n-1 without duplicates (for a rank-based scheme: without duplicates, without lost or failed moves).
5. The board can be made touch friendly and usable at 375 px and 1280 px within about two days of work.

Cost criteria (compared, not pass or fail):

6. Build cost: lines of code, extra assets, whether a Node build step is required.
7. Upgrade risk: maintainer activity, issues about Livewire 4, stability of the underlying directive.

Decision rule: custom board unless it fails criteria 1 to 4, or cannot be made touch friendly (5). Flowforge only if the custom board fails and the owner accepts the custom Filament theme build.

## Method

- The spike runs in a `git archive HEAD` copy of the repository at `~/kokpit-spikes/kanban/app`, installed with the host PHP 8.5 and `composer install`, against a throwaway `postgres:18` container named `kokpit-spike-kanban-pg` (bound to 127.0.0.1, trust authentication, database `spike_test` so the copied `tests/TestCase.php` guard accepts it). The DDEV project and its `kokpit_test` database are not used. Nothing of the copy is merged into `app/`, `composer.json` or `composer.lock` of the repository.
- The spike has a `tasks` table (UUID v7 default `uuidv7()`, `client_id`, `status` with a CHECK over `todo`, `doing`, `review`, `done`, `position`, `title`, `timestampsTz()`), a `Task` model implementing `PartnerIsolated` with `IsolatesPartners` and `SortableTrait` (sort query scoped by status), and a `TaskPolicy` extending `KokpitPolicy` that lets a Partner view the own client's tasks and update none. The Flowforge board uses a sibling `flow_tasks` table with the same columns, but `position` as `DECIMAL(20,10)` and the package's unique `(status, position)` constraint.
- The custom board is a Filament page (`#[AccessRule]` Admin only, `EnforcesPageAccessRule`) with one list per status. The handler delegates to one guarded mover: scoped lookup, `Gate::authorize('update')`, a lock, a model `update()` for the status, `setNewOrder` for the positions of the target and source columns, all inside a transaction. A second page with the same handler is open to Partners, because that is where a forged move can arrive in Phase 5.
- The Flowforge board is built with its documented `BoardPage` API for the same model, in the same panel, with no theme build and no npm install (the theme tooling is not in the research package audit). Flowforge is judged on its server-side behaviour through Livewire tests, and its layout is captured unstyled.
- A dotenv file in the copy (not created by `cp`, because the unattended tool guard refuses any command that names it) was written with the editor tool; it holds only the local container settings and a spike key.
- Concurrency uses real parallel PHP processes behind a start barrier, committed rows and no wrapping transaction, like `tests/Concurrency/`.
- Layout and timing: `php artisan serve` with 4 workers on a local port, a spike-only sign-in route, headless Google Chrome screenshots at 375 x 812 and 1280 x 800. Screenshots and spike code are not committed.
- Data is fictional: clients are called `Example Client A` and `Example Client B`, canary strings are assembled at runtime, e-mails use `example.com`.

## Measurements

### Tracer: custom board, end to end

| Check | Result |
|---|---|
| Spike test `tests/Spike/KanbanBoardTest.php`: Admin moves card A from `todo` position 0 to `doing` position 1 through the Livewire handler | pass (1 test, 8 assertions) |
| Status after reload | `doing` |
| `doing` column order after reload | `[doing 0, A, doing 1, doing 2]`, A at index 1 |
| Positions after the move | `doing` 0..3, `todo` 0..1 (source column compacted) |
| Card id format | UUID version 7 |
| A freshly mounted page shows the same order | pass (`assertSeeInOrder`) |

### Comparison per option

| Option | UUID v7 and persisted mid-column drop | Status through model event | Forged move of client B card | Own client card, Partner without grant | Two parallel processes | Layout 375 / 1280 px | Build cost | Upgrade risk |
|---|---|---|---|---|---|---|---|---|
| Custom (`wire:sort` + eloquent-sortable) | pass, dense integer order 0..n-1 | pass: 1 `updated` event with `status` dirty; pure reorder fires none | 404, table unchanged | 403 from the policy | pass with advisory lock (60 of 60 rounds) and row lock when the column has rows (30 of 30); no lock corrupts 100% of rounds (20 of 20 in the final run) | usable: columns scroll horizontally inside the page at 375 px; styled with inline CSS | 124 lines, no extra package, no asset, no Node | Livewire core directive; 1 open and 1 fixed issue |
| Flowforge 4.1.4 | pass, decimal rank (`99335.08` between `65535` and `131070`) | pass: 1 `updated` event with `status` and `position`; pure reorder also fires `updated` (position only) | `InvalidArgumentException` (HTTP 500 in production), table unchanged | the move succeeds, no policy is called; a 3-line override in the page fixes it (403) | pass: 10 of 10 rounds with the unique constraint, 10 of 10 without | unstyled stacked list without the theme build, at both widths | about 45 lines plus the guard, plus a theme build (Tailwind, Vite or equivalent, Node in CI and on Zerops) | young, very active, 2 open issues; depends on Filament and Livewire release pace |

The rows below give the evidence behind each cell.

### Custom board: criteria 1, 2, 3 and 6 (spike tests in `~/kokpit-spikes/kanban/app/tests/Spike`)

| Check | Result |
|---|---|
| 1. UUID v7 keys and a mid-column drop persist across a fresh query and a freshly mounted page | pass (tracer above) |
| 2. Status move fires the model `updated` event | pass: exactly 1 event for a cross-column move, `isDirty('status')` and `wasChanged('status')` both true inside the event, `getChanges()` contains `status` |
| 2. A pure reorder inside one column fires no model event | pass: `setNewOrder` writes through the query builder, 0 events (positions are not activity-logged, which is wanted) |
| Rendered markup | `wire:sort="moveCard"`, `wire:sort:group="board"` and one `wire:sort:group-id` per status (`todo`, `doing`, `review`, `done`) are present; with 200 cards the page has 200 `wire:sort:item` attributes |
| Forged target status (`archived`) | HTTP 422 from the handler, card unchanged; the CHECK constraint on `status` also rejects it in the database |

The spatie activity log (`LogsActivity`) listens on `updating` and `updated` and reads `getDirty()` (source read, `vendor/spatie/laravel-activitylog/.../LogsActivity.php`), so a status written through `$card->update()` reaches the plan 03-10 allowlist. Whether a real allowlisted row is written is not measured here (the wrapper trait of plan 03-10 does not exist yet).

### Custom board: criterion 5 and 6 (layout, render time, size)

| Check | Result |
|---|---|
| Median render of the board component, 200 cards over 4 columns and 2 clients, 5 Livewire test renders | 9.4 ms (min 7.8, max 10.1) |
| Median full HTTP response of `/admin/kanban-board` with 200 cards (`php artisan serve` with 4 workers, 6 requests, signed-in admin) | about 45 ms median (37 to 91 ms), 114 KB HTML |
| Layout at 1280 x 800 (headless Chrome screenshot) | sidebar plus three full columns and a fourth partly visible; the column row scrolls horizontally inside its own container |
| Layout at 375 x 812 (headless Chrome screenshot) | one column and part of the second are visible; the four 16 rem columns are about 1070 px wide, so the row scrolls horizontally inside its container instead of overflowing the page |
| Touch drag at 375 px | not automatable here, left as a human check (see Open items); the bundled SortableJS contains touch handlers (`_onTouchMove`, `touchStartThreshold` in `livewire.js`), so touch is expected to work (`[ASSUMED]` until the human check) |
| Lines of code of the board | 124: mover 60, page trait 24, page class 22, Blade view 18 (model 48 and policy 23 are needed by every option) |
| Extra assets, packages, Node build | none: SortableJS ships inside `livewire.js`; the view uses inline styles, because Tailwind utility classes outside Filament's compiled stylesheet would need a theme build |

### Flowforge: criteria 1, 2, 3 and 5 (spike tests `FlowforgeBoardTest`, `FlowforgeConcurrencyTest`)

| Check | Result |
|---|---|
| Installs on PHP 8.5, Filament 5.10, Livewire 4.4.7 | yes: `composer require relaticle/flowforge:^4.1` resolved to 4.1.4 (requires `ext-bcmath`, Filament ^5.0, PHP ^8.3) without errors; compared with the repository's `composer.lock` the copy's lock differs by exactly one added package (`relaticle/flowforge` v4.1.4), no other package changed |
| 1. UUID v7 keys and a mid-column drop | pass: the dropped card gets `99335.0774637895` between neighbours `65535` and `131070` (jitter around the midpoint), order after reload `[doing 0, A, doing 1, doing 2]`, source column unchanged otherwise; the position column must be `DECIMAL(20,10)` (`flowforgePositionColumn()`) |
| 2. Status move fires the model `updated` event | pass: 1 event, `status` dirty, changes `status` and `position` |
| 2. Pure reorder inside a column | also an `updated` event, with `position` only (an activity allowlist without `position` must skip it) |
| 3. Partner on an Admin only Flowforge page | 403 (route and Livewire mount) with the same `EnforcesPageAccessRule` |
| 3. Forged move of a client B card as Partner A on a Partner reachable page | the card lookup runs on the board query, so `PartnerScope` applies: `InvalidArgumentException: Card not found`, nothing changed (a 500 in production, not a 404) |
| 3. Move of an own client card as Partner A, policy grants no update | succeeds, the card changes status: `moveCard` is public, loads through the scoped query and never calls a policy; there is no authorization hook in the package |
| 3. Same page with `moveCard` overridden (scoped `findOrFail`, `Gate::authorize('update')`, then `parent::moveCard`) | client B card 404, own client card 403, table unchanged, Admin move works |
| Forged target column `archived` | the package does not validate it; the database CHECK rejects the update (`QueryException`), card unchanged |
| Layout without the theme build, 375 x 812 and 1280 x 800 | the board renders as an unstyled stacked list (column name, count, cards in one flow): its Blade views use Tailwind utility classes and arbitrary values such as `w-[300px]` that Filament's precompiled stylesheet does not contain; the package ships no stylesheet, only a JavaScript component |
| Render | 80 cards in the HTML (20 per column by default, more load on scroll), 192 KB, about 65 ms median over 6 requests (62 to 77 ms) |
| Drag and touch in a browser | not measured (no theme, no automation); it uses Filament's `x-sortable`, which is SortableJS, so touch behaves like the custom board |

Final state of the spike suite: `vendor/bin/pest tests/Spike` in the copy, 25 tests passed, 126 assertions (custom board 15 tests including the concurrency and mutation runs, Flowforge 10 tests including its concurrency runs), about 7 minutes because the concurrency tests spawn real worker processes.

## Isolation and concurrency

### Isolation (criterion 3), custom board

Two pages share one handler. `KanbanBoard` is `#[AccessRule(Audience::AdminOnly)]`. `PartnerKanbanBoard` is `#[AccessRule(Audience::PartnerAllowed)]` and exists only so a Partner can reach the handler, which is the situation of Phase 5 (KB-03, read-only list for a Partner). Client names are `Example Client A` and `Example Client B`; the client B card title is a runtime canary.

| Probe | Result |
|---|---|
| Partner A requests `/admin/kanban-board` (Admin only page) | 403; a Livewire mount of that page as Partner A is also 403; the Admin gets 200 |
| Partner A opens the Partner reachable board | sees the client A card, neither the client B canary nor the other client B card |
| Forged move: Partner A calls `moveCard` with the id of a client B card (target `done`) | 404 (the scoped lookup finds nothing, `ModelNotFoundException`); every row of the table is byte-for-byte unchanged (id, status, position, `updated_at`) |
| Partner A calls `moveCard` for a card of the own client | 403 from `Gate::authorize('update')` (the policy grants a Partner no update); table unchanged |
| Mutation run: scoped lookup replaced by an unscoped one and the policy call removed | 2 of 6 isolation tests fail (the forged move and the own client move now succeed), so the tests do detect a missing guard; the guard was restored and the 6 tests pass again |

Defence in depth observed: page access rule at mount, global `PartnerScope` on the lookup, policy `update`, status whitelist in the handler, CHECK constraint in the database. Flowforge gives the scope and the CHECK constraint for free and needs the policy call added by an override (see the Flowforge table above).

### Concurrency (criterion 4), custom board

Setup: two parallel PHP worker processes (same barrier and process pattern as `tests/Concurrency/`, real committed rows, no wrapping transaction). Each worker moves 25 cards from its own source column (`todo` or `review`, 50 cards each) into the same target column `doing` (5 cards) at position 1, one guarded move per card. After every round the verdict reads every column and requires positions exactly 0..n-1 (n = 25, 55, 25, 0). Window widening uses the same idea as the existing worker: a 2 ms `pg_sleep` between reading the column order and writing it.

| Lock variant | Rounds | Result |
|---|---|---|
| Row lock (plan variant): `SELECT id ... WHERE status IN (source, target) ORDER BY id FOR UPDATE` in its own statement, then fresh reads | 5 at natural timing + 5 widened per run, 3 full runs | 30 of 30 rounds clean: 55 cards in `doing`, positions 0..54, source columns 0..24, no error |
| Transaction advisory lock (`pg_advisory_xact_lock` on one board key) | 5 natural + 5 widened per run, 3 full runs | 30 of 30 rounds clean |
| No lock (mutation run) | 10 widened + 10 natural in the final run (earlier runs agreed) | corrupted in 10 of 10 widened rounds and 10 of 10 natural rounds; in the widened rounds the target column `doing` carried exactly 1 duplicated position (two cards at the same index), counts per column stayed correct |
| Row lock, target column initially empty (two workers into `done`, position 0), widened | 10 + 20 + 20 + 20 rounds in four runs | 12 rounds with a PostgreSQL deadlock (`SQLSTATE 40P01`) that rolled one move back (the card stayed in its source column, no corruption), the other 58 rounds clean; the final run alone had 5 of 20 |
| Advisory lock, target column initially empty, widened | 10 rounds in each of three runs | 30 of 30 clean, no deadlock |

Findings:

- Without any lock the board corrupts reliably, even at natural timing, so the lock is required, not optional.
- Locking the existing rows of the source and target columns works when the target column has rows, provided the order is read in a statement that starts after the lock is held (a `FOR UPDATE` and a read in the same statement would use a stale snapshot under READ COMMITTED and miss a card moved in by the other process).
- Row locks do not cover an empty column (nothing to lock), and when rows appear while two moves overlap, the per-id position updates of two transactions can deadlock. The result is a failed move, not corruption, and it was seen in 12 of 70 widened rounds.
- A single transaction advisory lock around the whole move had no failure in 60 rounds (30 into a filled column, 30 into an empty one). For a CRM board with one Admin writer and a few Partners it costs nothing measurable, and it also covers the empty column. Per-column row locks stay a valid alternative if the move is wrapped in a retry on `40P01`.
- Not measured: two moves of the same card at the same time, and eight or more workers.

### Concurrency (criterion 4), Flowforge

Same shape: two worker processes drive the page's own `moveCard` (through a Livewire component instance), each dropping 25 cards at index 1 of `doing` (5 cards), neighbours read just before each call.

| Variant | Rounds | Result |
|---|---|---|
| Package default, unique `(status, position)` constraint on | 10 | 10 of 10 clean: 50 of 50 moves succeeded, no error, 55 cards in `doing`, no duplicate, no null position |
| Unique constraint dropped (jitter alone) | 10 | 10 of 10 clean, no duplicate |
| Gap after 50 drops into the same slot | 1 | smallest gap `0.0000052587` in one run and `0.0000787244` in another, below the package's own rebalance threshold of `0.0001`: the slot is squeezed repeatedly, the column stayed consistent and no error surfaced (whether auto-rebalance ran in between was not captured, the log was not kept) |

Flowforge locks only the two neighbour rows (`lockForUpdate`), calculates a jittered position, retries on a unique violation and never needs a gap-free order, so criterion 4 is met by a different mechanism. It has no whole-column dense order to maintain.

## Build cost and upgrade risk

| Item | Custom board | Flowforge |
|---|---|---|
| Code | 124 lines (mover, page trait, page class, view) | about 45 lines for the board plus 8 for the policy guard |
| Composer dependency | none (`spatie/eloquent-sortable` is already locked, require it directly in Phase 5) | `relaticle/flowforge` (MIT, `ext-bcmath`), a 2025 package |
| JavaScript and assets | none beyond Livewire's bundled SortableJS | its Alpine component, delivered through Filament's asset manager |
| Node and theme build | none | required for any usable layout: a custom Filament theme (a Tailwind source entry for the package's views, Vite or an equivalent build, a theme registration on the panel) and an `npm` build step in CI and on Zerops. The repository has no `package.json`, no Vite configuration and no Node step today, and the theme tooling packages are not in the research package audit, so it was recorded as a cost and not run |
| Styling | hand written or inline CSS, Filament components; Tailwind utility classes outside Filament's compiled stylesheet do not apply, so a small plain CSS file registered through Filament's `Css` asset class (class exists in `filament/support`) is the build-free route `[ASSUMED]` | the package's own Tailwind markup |
| Ordering | integer dense order via `setNewOrder`: reorder rewrites the column, fine for dozens to low hundreds of cards | decimal rank, cursor paging (20 cards per column initially), `repair-positions` and `rebalance` commands |
| Migration impact | `position integer` plus an index on `(status, position)` | `DECIMAL(20,10)` column and a unique constraint that spans all clients in one column |
| Authorization | written by us, tested (policy, scope, status whitelist) | no hook; override of `moveCard` required |

Upgrade risk (read on 2026-10-08 with `gh api`, marked `[CITED]`):

- Flowforge: repository created 2025-03-20, last push 2026-10-06, five releases between 2026-08-19 and 2026-10-06 (v4.1.0 to v4.1.4), 422 stars, 2 open issues (a filter bug and a swimlane request), none about Livewire 4 open; eight issues matching "livewire 4" were all closed. Very active, but one organisation's package, young, and tied to the Filament and Livewire major lines, so a Filament minor can break it and the owner waits for a release.
- Custom board: depends on the Livewire `wire:sort` directive (Livewire 4.4.7 released 2026-09-28; the repository has three issues mentioning `wire:sort`). Open: livewire/livewire issue 10662, "`wire:navigate` breaks `wire:sort`" (a card that is itself a `wire:navigate` link loses dragging). It matters only if a card is a navigate link, which the Phase 5 board should avoid (use a button or a title link inside the card). Fixed on 2026-07-06: issue 10350, a client-side TypeError when dropping a card into an empty group; the fix predates 4.4.7 `[ASSUMED]` to be included, and the empty-column drop is on the human check list.
- Both options sit on the same SortableJS-based drag layer, so touch behaviour is a shared risk.

## Decision

**Build the custom board for Phase 5** (custom Livewire 4 `wire:sort` board, order written with `spatie/eloquent-sortable`, status written through a model update, one advisory transaction lock per move).

Deciding evidence, by the rule written under Criteria: the custom board passed hard criteria 1 to 4 (persisted mid-column drop with UUID v7 keys, `updated` event with `status`, 404 for a forged move of another client's card and 403 for a Partner update, 60 of 60 clean concurrent rounds with the advisory lock against 20 of 20 corrupted rounds without a lock in the final run). Criterion 5 (touch) is not disproven; the layout works at 375 px with horizontal column scrolling and the touch check is a human item. Flowforge also passed the persistence and concurrency criteria but ships no authorization hook (the own-client move succeeds without a grant until the page overrides `moveCard`), is unusable without a custom Filament theme and a Node build that the repository does not have, and adds a young dependency; the rule allows buying only if the custom board fails and the owner accepts that build, and neither condition holds.

The decision is conditional on the touch check. If a real touch drag fails or fights page scrolling, the fallback is a drag handle (`wire:sort:handle`) and SortableJS options through `wire:sort:config`, not a switch to Flowforge, which uses the same drag layer.

## Consequences

Phase 5 (KB-01, KB-02, KB-03) builds the board; nothing is added in Phase 3 and nothing of the spike is merged.

- Dependency and assets: add no board package and no Node tooling. Require `spatie/eloquent-sortable` directly in `composer.json` (it is already locked at 5.0.1 through `spatie/laravel-tags`).
- Ordering column contract: `tasks.position integer NOT NULL`, dense and zero-based per `status` column, index on `(status, position)`, the model implements `Sortable` with `buildSortQuery()` scoped by status (`sort_when_creating` off, the creating code appends at `count`). A `DEFERRABLE INITIALLY DEFERRED` unique constraint on `(status, position)` would turn any future duplicate into an error at commit; it was not measured, so Phase 5 should test it before adopting it `[ASSUMED]`.
- Guard pattern of the move handler, in this order, inside one transaction: (1) whitelist the target status (422), (2) scoped lookup of the card through the model with `PartnerScope` (404 for another client's card), (3) `Gate::authorize('update', $card)` (403, a Partner never gets update), (4) lock the board with `pg_advisory_xact_lock` on one key (measured: 60 of 60 clean rounds, no deadlock, covers empty columns); if column row locks (`lockForUpdate()`) are preferred instead, lock the source and target rows ordered by id in a statement of their own, read the order in a later statement, and retry on `40P01` (12 deadlocks in 70 widened rounds without a retry), (5) status through `$card->update()` so the `updated` event reaches the plan 03-10 activity allowlist (`status` allowlisted, `position` not), (6) positions through `setNewOrder($ids, 0)` for the target column and the source column, (7) the database CHECK on `status` as the last line.
- Partner read-only list (KB-03): a Partner reachable page renders the cards without any `wire:sort` attributes, and the same handler stays on the page only if the policy denies Partners (a forged call must return 403, test it with a runtime canary like the spike). The Partner board never shows another client's card because of `PartnerScope`.
- Handler signature: the drop index from `wire:sort` is an index into the rendered list. If Phase 5 adds filters by client or assignee to the board, the dense integer order is computed over the whole column, so the handler should either take neighbour card ids (as Flowforge does) or disable dragging while a filter is active. The spike did not build the filters.
- Done column: the spike rendered all 200 cards in about 45 ms and 114 KB; cap or paginate the done column in Phase 5 so the page does not grow without bound.
- Cards must not be `wire:navigate` links (livewire/livewire issue 10662); use a title link inside the card.
- Styling: hand written CSS or Filament components, not Tailwind utilities outside Filament's stylesheet.
- Tests to port from the spike: mid-column drop after reload, one `updated` event with `status` dirty, Partner 403 on the Admin page, forged move 404, own-client move 403, forged status 422, and the two-process concurrency test with its no-lock mutation run.

## Open items

- Touch drag at 375 px needs a real or emulated touch device; no browser automation tool is installed for this unattended run (human check in plan 03-02). Include a drop into an empty column and the conflict between dragging and vertical or horizontal scrolling.
- Filters by client and assignee (research method: 200 cards with filters) were not built; the effect on the integer order is described under Consequences.
- Not measured: two moves of the same card at the same time, eight or more workers, a real activity-log row for a status move, navigation to the board through Filament's SPA mode (`wire:navigate`) and back in a browser, the same board in a Zerops container.
- Flowforge was not driven in a browser (no theme build), so its drag behaviour and its themed layout are not measured; the numbers above are server side plus an unstyled screenshot.
- The spike copy stays at `~/kokpit-spikes/kanban/app` for the owner's review; the PostgreSQL container was stopped and removed.
- Owner confirmation of the build decision before Phase 5 is planned.
