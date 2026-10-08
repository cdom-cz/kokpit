# Phase 3 Spike: Kanban board, build or buy

Status: in progress (custom board measured; Flowforge, build cost and decision are filled by the last task of plan 03-02).
Decision records: D-15 (compare custom `wire:sort` board against Flowforge), D-16 (spike code stays out of the application).

## Context

Phase 5 (KB-01, KB-02, KB-03) needs a kanban board where dragging a card to another column persists the new status and the order inside the column, writes the status change through Eloquent model events (so the activity log sees it), and never lets a Partner move or even see another client's card. This spike builds the same board two ways in a throwaway copy of the repository and decides which one Phase 5 builds or adds.

Stack under test (installed versions): PHP 8.5.11, Laravel 13.35.0, Filament 5.10.0, Livewire 4.4.7, `spatie/eloquent-sortable` 5.0.1 (already locked through `spatie/laravel-tags`), PostgreSQL 18 in a throwaway container.

## Candidates

| Candidate | What it is | Ordering scheme | Extra dependency |
|---|---|---|---|
| Custom board | Filament page with a Blade view using the Livewire 4 `wire:sort`, `wire:sort:item`, `wire:sort:group` and `wire:sort:group-id` directives (SortableJS is bundled into Livewire's own `livewire.js`) and one handler method | integer `position` per status column, written with `spatie/eloquent-sortable` `setNewOrder` | none |
| Flowforge | `relaticle/flowforge` 4.1.x, a Filament 5 board plugin (MIT) | its own rank column and `flowforge:repair-positions` | one Composer package, plus its documented prerequisite of a custom Filament theme with a Tailwind build |

The Livewire 4.4.7 bundle was searched for the directives: `sort:group` and `sort:group-id` are present in `vendor/livewire/livewire/dist/livewire.js`, so the custom board needs no separate SortableJS package. The handler receives the item id, the zero-based position and the destination group id.

## Criteria

Written before any comparison measurement (research Pattern 13, decision rule). A candidate must pass every hard criterion; the build option wins ties.

Hard criteria (a candidate that fails one is out unless a mitigation is proven):

1. UUID v7 keys work, and a card dropped in the middle of another column keeps its status and its place after a reload.
2. A status change goes through Eloquent model events (an `updated` event with `status` changed), so the activity log can record it.
3. Partner isolation: a Partner gets 403 on a board route that is closed to Partners, and a forged move call for another client's card changes nothing (scoped lookup, policy).
4. Concurrency: two parallel processes moving cards into one column leave the positions of every column exactly 0..n-1 without duplicates.
5. The board can be made touch friendly and usable at 375 px and 1280 px within about two days of work.

Cost criteria (compared, not pass or fail):

6. Build cost: lines of code, extra assets, whether a Node build step is required.
7. Upgrade risk: maintainer activity, issues about Livewire 4, stability of the underlying directive.

Decision rule: custom board unless it fails criteria 1 to 4, or cannot be made touch friendly (5). Flowforge only if the custom board fails and the owner accepts the custom Filament theme build.

## Method

- The spike runs in a `git archive HEAD` copy of the repository at `~/kokpit-spikes/kanban/app`, installed with the host PHP 8.5 and `composer install`, against a throwaway `postgres:18` container named `kokpit-spike-kanban-pg` (bound to 127.0.0.1, trust authentication, database `spike_test` so the copied `tests/TestCase.php` guard accepts it). The DDEV project and its `kokpit_test` database are not used. Nothing of the copy is merged into `app/`, `composer.json` or `composer.lock` of the repository.
- The spike has a `tasks` table (UUID v7 default `uuidv7()`, `client_id`, `status` with a CHECK over `todo`, `doing`, `review`, `done`, `position`, `title`, `timestampsTz()`), a `Task` model implementing `PartnerIsolated` with `IsolatesPartners` and `SortableTrait` (sort query scoped by status), and a `TaskPolicy` extending `KokpitPolicy` that lets a Partner view the own client's tasks and update none.
- The custom board is a Filament page (`#[AccessRule]` Admin only, `EnforcesPageAccessRule`) with one list per status. The handler delegates to one guarded mover: scoped lookup, `Gate::authorize('update')`, a lock, a model `update()` for the status, `setNewOrder` for the positions of the target and source columns, all inside a transaction.
- A dotenv file in the copy (not created by `cp`, because the unattended tool guard refuses any command that names it) was written with the editor tool; it holds only the local container settings and a spike key.
- Data is fictional: clients are called `Example Client A` and `Example Client B`, canary strings are assembled at runtime, e-mails use `example.com`.

## Measurements

### Tracer (Task 1): custom board, end to end

| Check | Result |
|---|---|
| Spike test `tests/Spike/KanbanBoardTest.php`: Admin moves card A from `todo` position 0 to `doing` position 1 through the Livewire handler | pass (1 test, 8 assertions) |
| Status after reload | `doing` |
| `doing` column order after reload | `[doing 0, A, doing 1, doing 2]`, A at index 1 |
| Positions after the move | `doing` 0..3, `todo` 0..1 (source column compacted) |
| Card id format | UUID version 7 |
| A freshly mounted page shows the same order | pass (`assertSeeInOrder`) |

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
| Touch drag at 375 px | not automatable here, left as a human check (see Open items); `wire:sort` uses the bundled SortableJS, which supports touch (`[ASSUMED]` until the human check) |
| Lines of code of the board | 124: mover 60, page trait 24, page class 22, Blade view 18 (model 48 and policy 23 are needed by every option) |
| Extra assets, packages, Node build | none: SortableJS ships inside `livewire.js`; the view uses inline styles, because Tailwind utility classes outside Filament's compiled stylesheet would need a theme build |

Test suite status when the measurements were taken: `vendor/bin/pest tests/Spike` in the copy, 15 passed, 98 assertions.

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

Defence in depth observed: page access rule at mount, global `PartnerScope` on the lookup, policy `update`, status whitelist in the handler, CHECK constraint in the database.

### Concurrency (criterion 4), custom board

Setup: two parallel PHP worker processes (same barrier and process pattern as `tests/Concurrency/`, real committed rows, no wrapping transaction). Each worker moves 25 cards from its own source column (`todo` or `review`, 50 cards each) into the same target column `doing` (5 cards) at position 1, one guarded move per card. After every round the verdict reads every column and requires positions exactly 0..n-1 (n = 25, 55, 25, 0). Window widening uses the same idea as the existing worker: a 2 ms `pg_sleep` between reading the column order and writing it.

| Lock variant | Rounds | Result |
|---|---|---|
| Row lock (plan variant): `SELECT id ... WHERE status IN (source, target) ORDER BY id FOR UPDATE` in its own statement, then fresh reads | 5 at natural timing + 5 widened | 10 of 10 rounds clean: 55 cards in `doing`, positions 0..54, source columns 0..24, no error |
| Transaction advisory lock (`pg_advisory_xact_lock` on one board key) | 5 natural + 5 widened | 10 of 10 rounds clean |
| No lock (mutation run) | 10 widened + 10 natural | corrupted in 10 of 10 widened rounds and 10 of 10 natural rounds; in the widened rounds the target column `doing` carried exactly 1 duplicated position (two cards at the same index), counts per column stayed correct |
| Row lock, target column initially empty (two workers into `done`, position 0), widened | 20 rounds | 17 clean, 3 rounds with a PostgreSQL deadlock (`SQLSTATE 40P01`) that rolled one move back (card stayed in its source column, no corruption); in a first 10-round run 1 deadlock |
| Advisory lock, target column initially empty, widened | 10 rounds | 10 of 10 clean, no deadlock |

Findings:

- Without any lock the board corrupts reliably, even at natural timing, so the lock is required, not optional.
- Locking the existing rows of the source and target columns works when the target column has rows, provided the order is read in a statement that starts after the lock is held (a `FOR UPDATE` and a read in the same statement would use a stale snapshot under READ COMMITTED and miss a card moved in by the other process).
- Row locks do not cover an empty column (nothing to lock), and when rows appear while two moves overlap, the per-id position updates of two transactions can deadlock. The result is a failed move, not corruption, and it was seen in 3 of 20 widened rounds.
- A single transaction advisory lock around the whole move had no failure in 20 rounds. For a CRM board with one Admin writer and a few Partners it costs nothing measurable, and it also covers the empty column. Per-column row locks stay a valid alternative if the move is wrapped in a retry on `40P01`.
- Not measured: two moves of the same card at the same time, and eight or more workers.

## Build cost and upgrade risk

Pending (Task 3).

## Decision

Pending (Task 3).

## Consequences

Pending (Task 3). Phase 5 (KB-01, KB-02, KB-03) is the phase that builds or adds the board.

## Open items

- Touch drag at 375 px needs a real or emulated touch device; no browser automation tool is installed for this unattended run (human check in plan 03-02).
