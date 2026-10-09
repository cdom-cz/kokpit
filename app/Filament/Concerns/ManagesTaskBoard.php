<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Actions\MoveTask;
use App\Domain\Tasks\Board\BoardFilters;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource;
use App\Filament\Support\TaskTimerToggle;
use App\Providers\LocalisationServiceProvider;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The shared Livewire side of a kanban board page: the computed columns and the
 * single drop handler `moveCard` that every `wire:sort` column container calls.
 *
 * The page state holds scalars only. The columns are computed per request as
 * plain arrays, so no Eloquent model ends up in the Livewire snapshot.
 *
 * The three arguments of `moveCard` come from the browser and are untrusted:
 * the status is whitelisted here, the task id is resolved through the scoped
 * query and authorised by MoveTask. The task argument of the preview action is
 * untrusted the same way: scoped lookup, then the view Gate (D-09). So is the task id
 * of the timer button of a card (`toggleTimer`).
 */
trait ManagesTaskBoard
{
    /** The client id the board is narrowed to; empty for all clients. */
    #[Url]
    public ?string $clientFilter = null;

    /** The assignee (user id) the board is narrowed to. */
    #[Url]
    public ?string $assigneeFilter = null;

    /** The task tag id the board is narrowed to. */
    #[Url]
    public ?string $tagFilter = null;

    /** The priority value the board is narrowed to. */
    #[Url]
    public ?string $priorityFilter = null;

    /**
     * Six columns of 17rem do not fit the stock content width, so a board uses the full width.
     */
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /**
     * The previews resolved in this request, by task id: the heading, the body and the footer share one lookup.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $resolvedPreviews = [];

    /**
     * The header actions of a board: the quick creation (D-09).
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->quickCreateAction()];
    }

    /**
     * The quick creation of the list, with the project of a project board preset.
     */
    public function quickCreateAction(): Action
    {
        return TaskResource::quickCreateAction($this->boardProject());
    }

    /**
     * The slide-over preview of a card (D-09, A12): a Filament action mounted
     * from the card's eye button with the task id as its argument. There is no
     * submit; the footer links to the task page.
     */
    public function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('kokpit.task_board.card.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->slideOver()
            ->modalWidth(Width::ExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('kokpit.task_board.preview.close'))
            ->modalHeading(fn (array $arguments): string => $this->previewData($arguments)['heading'])
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('openTask')
                    ->label(__('kokpit.task_board.card.open'))
                    ->url($this->previewData($arguments)['url']),
            ])
            ->modalContent(fn (array $arguments): View => view('filament.pages.partials.task-preview', $this->previewData($arguments)));
    }

    /**
     * The data of the preview of the task named by the action argument.
     *
     * The argument comes from the browser, so it is never trusted: an id that is
     * no UUID, an unknown, archived or foreign task is not found (404) and the
     * view Gate decides the rest. Only fields that are safe next to the card
     * are read: no billing, checklist, tag, comment or history (U-5).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function previewData(array $arguments): array
    {
        $id = $arguments['task'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Task::class);
        }

        if (isset($this->resolvedPreviews[$id])) {
            return $this->resolvedPreviews[$id];
        }

        $task = Task::query()
            ->with(['assignee:id,name', 'requester:id,name', 'escalatedBy:id,name', 'parent' => static fn ($parent) => $parent->withTrashed()->select('tasks.id', 'tasks.reference')])
            ->findOrFail($id);

        Gate::authorize('view', $task);

        $date = static fn (mixed $value): ?string => $value?->format(LocalisationServiceProvider::DATE_FORMAT);

        return $this->resolvedPreviews[$id] = [
            'heading' => $task->reference.' · '.$task->title,
            'url' => TaskResource::getUrl('view', ['record' => $task->reference]),
            'reference' => $task->reference,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'start_date' => $date($task->start_date),
            'due_date' => $date($task->due_date),
            'assignee' => $task->assignee?->name,
            'requester' => $task->requester?->name,
            'parent_reference' => $task->parent?->reference,
            'escalated' => $task->escalated_at !== null,
            'escalated_by' => $task->escalatedBy?->name,
            'description' => $task->description,
        ];
    }

    /**
     * The cards of every status column, with the link to the task page.
     *
     * @return array<string, array{label: string, cards: list<array<string, mixed>>, shown: int, total: int}>
     */
    #[Computed]
    public function columns(): array
    {
        $columns = app(TaskBoard::class)->columns($this->boardFilters());

        foreach ($columns as $status => $column) {
            foreach ($column['cards'] as $i => $card) {
                $columns[$status]['cards'][$i]['url'] = TaskResource::getUrl('view', ['record' => $card['reference']]);
            }
        }

        return $columns;
    }

    /**
     * The drop handler of `wire:sort`: the item id, the zero-based drop index
     * in the destination column and the id of that column (its status).
     *
     * The handler is synchronous, so the move is stored before the response.
     */
    public function moveCard(string $item, int $position, string $group): void
    {
        $status = ProjectStatus::tryFrom($group) ?? abort(422);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        app(MoveTask::class)->handle($user, $item, $position, $status, $this->boardFilters());

        // The columns were computed before the move when this request rendered them earlier.
        unset($this->columns);
    }

    /**
     * Starts the timer for the task of a card, or stops it while that task is the running one
     * (TI-01, D-01). The id comes from the browser and is never trusted: a malformed or unknown id
     * is not found (404), the lookup goes through the Partner-scoped query and the view Gate decides
     * the rest. An archived task is looked up too, so a stale card can still stop its own timer; a
     * start on it is refused by StartTimer with the task error. The context of the new entry is
     * derived from the task there, never from the card.
     */
    public function toggleTimer(string $taskId): void
    {
        abort_unless(Str::isUuid($taskId), 404);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $task = Task::query()->withTrashed()->whereKey($taskId)->firstOrFail();

        Gate::authorize('view', $task);

        $event = app(TaskTimerToggle::class)->handle($user, $task);

        // The running task changed: the card icons are read again.
        unset($this->runningTaskId);

        if ($event !== null) {
            $this->dispatch($event);
        }
    }

    /**
     * The id of the task the signed-in user's running timer is logged to, one query per render for
     * every card; null when nothing runs.
     */
    #[Computed]
    public function runningTaskId(): ?string
    {
        $user = Auth::user();

        return $user instanceof User ? app(TaskTimerToggle::class)->runningTaskId($user) : null;
    }

    /**
     * The options of the filter selects, as id => label.
     *
     * @return array{clients: array<string, string>, assignees: array<string, string>, tags: array<string, string>, priorities: array<string, string>}
     */
    #[Computed]
    public function filterOptions(): array
    {
        /** @var array<string, string> $clients */
        $clients = Client::query()->orderBy('name')->pluck('name', 'id')->all();
        /** @var array<string, string> $assignees */
        $assignees = User::query()->orderBy('name')->pluck('name', 'id')->all();

        $tags = [];

        foreach (Tag::query()->where('type', TagType::Task->value)->get() as $tag) {
            $tags[(string) $tag->getKey()] = (string) $tag->name;
        }

        asort($tags);

        $priorities = [];

        foreach (ProjectPriority::cases() as $priority) {
            $priorities[$priority->value] = $priority->getLabel();
        }

        return ['clients' => $clients, 'assignees' => $assignees, 'tags' => $tags, 'priorities' => $priorities];
    }

    /**
     * Whether any filter is set. The client filter does not count on a board
     * of one project: the client is fixed with the project.
     */
    #[Computed]
    public function hasFilters(): bool
    {
        $filters = [$this->assigneeFilter, $this->tagFilter, $this->priorityFilter];

        if ($this->boardProject() === null) {
            $filters[] = $this->clientFilter;
        }

        return array_filter($filters, static fn (?string $value): bool => $value !== null && $value !== '') !== [];
    }

    /**
     * Whether the board shows the tasks of one project only. The view hides the
     * client filter then.
     */
    #[Computed]
    public function hasFixedProject(): bool
    {
        return $this->boardProject() !== null;
    }

    public function resetFilters(): void
    {
        $this->clientFilter = null;
        $this->assigneeFilter = null;
        $this->tagFilter = null;
        $this->priorityFilter = null;
    }

    /**
     * The project a board page is fixed to, or null for the global board. A
     * project board overrides it; the project is then part of every filter and
     * preset in the quick creation.
     */
    protected function boardProject(): ?Project
    {
        return null;
    }

    /**
     * The filters from the page state. The values come from the URL, so they are
     * validated, never trusted. On a project board the project is fixed and the
     * client filter is ignored.
     */
    protected function boardFilters(): BoardFilters
    {
        $project = $this->boardProject();

        return BoardFilters::fromInput(
            projectId: $project?->getKey(),
            clientId: $project === null ? $this->clientFilter : null,
            assigneeId: $this->assigneeFilter,
            tag: $this->tagFilter,
            priority: $this->priorityFilter,
        );
    }
}
