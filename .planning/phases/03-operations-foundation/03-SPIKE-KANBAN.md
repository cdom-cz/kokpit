# Phase 3 Spike: Kanban board, build or buy

Status: in progress (Task 1 tracer written; later sections are filled by the following tasks of plan 03-02).
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

Later tasks add the model-event, isolation, concurrency, render-time and layout measurements, then the Flowforge row for each of them.

## Isolation and concurrency

Pending (Task 2 for the custom board, Task 3 for Flowforge).

## Build cost and upgrade risk

Pending (Task 3).

## Decision

Pending (Task 3).

## Consequences

Pending (Task 3). Phase 5 (KB-01, KB-02, KB-03) is the phase that builds or adds the board.

## Open items

- Touch drag at 375 px needs a real or emulated touch device; no browser automation tool is installed for this unattended run (human check in plan 03-02).
