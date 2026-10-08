<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Concerns\ManagesTaskBoard;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The global kanban board of all tasks: one column per status, cards ordered
 * by position, dragged with `wire:sort` (KB-01, KB-02).
 *
 * Admin only. A Partner has no board anywhere (KB-03): the page is refused at
 * mount, so a forged `moveCard` call from a Partner never reaches the handler.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The board moves cards across all clients; a Partner has no board and never moves a task.')]
class TaskBoardPage extends Page
{
    use EnforcesPageAccessRule, ManagesTaskBoard;

    protected static ?string $slug = 'task-board';

    protected static ?int $navigationSort = 31;

    protected string $view = 'filament.pages.task-board';

    public static function getNavigationLabel(): string
    {
        return __('kokpit.task_board.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedViewColumns;
    }

    public function getTitle(): string
    {
        return __('kokpit.task_board.title');
    }
}
