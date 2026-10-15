<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Filament\Concerns\CollapsibleDashboardWidget;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Support\TaskColumns;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Otevřené úkoly" on the Partner dashboard (D-12, UI-SPEC Surface F1): the open tasks of the
 * Partner's own visible projects, the ones waiting for the Partner's review first.
 *
 * The query is the scoped `Task` model and never removes the scope: the tasks of the own
 * client's client-visible, non-archived projects exist for a Partner, an archived task is gone
 * with the soft delete scope. Only the columns of `FIELDS` are read. No time, estimate, billing
 * or checklist column is selected, so none can reach the markup or the Livewire snapshot, and the
 * widget holds only the public `collapsed` flag (Locked, a scalar UI state, no data): the rows are
 * read again at every render.
 *
 * Visible to a Partner with a client only; the Admin has no Partner dashboard, so a forged mount
 * is refused (the declaration admits the Admin, `canView()` narrows it).
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'The Partner dashboard lists the open tasks of the own client\'s visible projects through the Partner scope; the Admin is refused by canView().')]
final class PartnerTasks extends Widget
{
    use CollapsibleDashboardWidget;
    use EnforcesWidgetAccessRule;

    /** The most rows the widget shows. */
    public const int LIMIT = 20;

    /**
     * The fields the widget renders: Partner task fields, and the name of the project, which is a
     * Partner project field. A test compares them with the pinned Partner column names.
     *
     * @var list<string>
     */
    public const array FIELDS = ['reference', 'title', 'status', 'due_date', 'assignee.name', 'project.name'];

    protected static ?int $sort = 10;

    /** The page tests and the empty-state decision read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** @var view-string */
    protected string $view = 'filament.widgets.partner-tasks';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /**
     * The declaration AND a Partner with a client, and not the Admin: like the Partner resources,
     * so the Admin never gets a Partner widget.
     */
    public static function canView(): bool
    {
        $context = app(PartnerContext::class);

        return AccessRules::allows(self::class)
            && ! $context->isAdmin()
            && $context->partnerClientId() !== null;
    }

    /**
     * The open tasks, the ones in review first, then by priority, then by due date with the
     * undated last, then by reference.
     *
     * @return Collection<int, Task>
     */
    public function tasks(): Collection
    {
        $query = self::openTasks();

        $query->orderByRaw('CASE WHEN "tasks"."status" = ? THEN 0 ELSE 1 END', [ProjectStatus::InReview->value]);
        TaskColumns::orderByPriority($query);

        return $query
            ->orderByRaw('"tasks"."due_date" ASC NULLS LAST')
            ->orderBy($query->qualifyColumn('reference'))
            ->orderBy($query->qualifyColumn('id'))
            ->select(array_map($query->qualifyColumn(...), ['id', 'reference', 'title', 'status', 'due_date', 'project_id', 'assignee_id']))
            ->with(['project:id,name', 'assignee:id,name'])
            ->limit(self::LIMIT)
            ->get();
    }

    public static function dashboardWidget(): DashboardWidget
    {
        return DashboardWidget::PartnerTasks;
    }

    protected function cardHeading(): string
    {
        return __('kokpit.dashboard.partner_tasks.heading');
    }

    /**
     * The number of open tasks: a count only, never a duration or an amount.
     */
    protected function collapsedSummary(): string
    {
        return __('kokpit.dashboard.partner_tasks.summary', ['count' => self::openTaskCount()]);
    }

    /**
     * How many open tasks the Partner has, more than the rows shown included.
     */
    public static function openTaskCount(): int
    {
        return self::openTasks()->count();
    }

    /**
     * How many of the open tasks wait for the Partner's review.
     */
    public function reviewCount(): int
    {
        return self::openTasks()->where('tasks.status', ProjectStatus::InReview->value)->count();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'tasks' => $this->tasks(),
            'openCount' => self::openTaskCount(),
            'reviewCount' => $this->reviewCount(),
            'limit' => self::LIMIT,
        ];
    }

    /**
     * @return Builder<Task>
     */
    private static function openTasks(): Builder
    {
        return Task::query()->where('tasks.status', '!=', ProjectStatus::Done->value);
    }
}
