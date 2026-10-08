<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Actions\MoveTask;
use App\Domain\Tasks\Board\BoardFilters;
use App\Domain\Tasks\Board\TaskBoard;
use App\Filament\Resources\TaskResource;
use Illuminate\Support\Facades\Auth;
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
 * query and authorised by MoveTask.
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
     * Whether any filter is set.
     */
    #[Computed]
    public function hasFilters(): bool
    {
        return array_filter([$this->clientFilter, $this->assigneeFilter, $this->tagFilter, $this->priorityFilter], static fn (?string $value): bool => $value !== null && $value !== '') !== [];
    }

    public function resetFilters(): void
    {
        $this->clientFilter = null;
        $this->assigneeFilter = null;
        $this->tagFilter = null;
        $this->priorityFilter = null;
    }

    /**
     * The filters from the page state. The values come from the URL, so they are
     * validated, never trusted.
     */
    protected function boardFilters(): BoardFilters
    {
        return BoardFilters::fromInput(
            clientId: $this->clientFilter,
            assigneeId: $this->assigneeFilter,
            tag: $this->tagFilter,
            priority: $this->priorityFilter,
        );
    }
}
