<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Concerns\ManagesTaskBoard;
use App\Filament\Resources\ProjectResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * The kanban board of one project (KB-01): the same view, columns and drop
 * handler as the global board, narrowed to the tasks of the project.
 *
 * Admin only, like the global board. A drop keeps the cards of other projects,
 * which this board does not show, in their relative order in the global column
 * (D-02): the mover recomputes the order over the whole column.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The project board moves cards in the global column order; a Partner has no board and never moves a task.')]
final class ProjectBoard extends Page
{
    use EnforcesPageAccessRule, InteractsWithRecord, ManagesTaskBoard;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.pages.task-board';

    /**
     * A resource page is asked with the record parameters; the declaration
     * decides, whatever record is requested.
     *
     * @param  array<string, mixed>  $parameters
     */
    #[\Override]
    public static function canAccess(array $parameters = []): bool
    {
        return AccessRules::allows(self::class);
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        $project = $this->boardProject();

        return __('kokpit.task_board.project_title', [
            'key' => $project === null ? '' : $project->key,
            'name' => $project === null ? '' : $project->name,
        ]);
    }

    protected function boardProject(): ?Project
    {
        $record = $this->getRecord();

        return $record instanceof Project ? $record : null;
    }
}
