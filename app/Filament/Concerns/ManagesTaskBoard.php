<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Tasks\Actions\MoveTask;
use App\Domain\Tasks\Board\BoardFilters;
use App\Domain\Tasks\Board\TaskBoard;
use App\Filament\Resources\TaskResource;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

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

    protected function boardFilters(): BoardFilters
    {
        return BoardFilters::none();
    }
}
