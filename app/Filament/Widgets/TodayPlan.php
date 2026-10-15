<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Filament\Concerns\CollapsibleDashboardWidget;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Pages\Signal\SignalTodayPage;
use Filament\Widgets\Widget;

/**
 * "Plán na dnešek" on the Admin dashboard: the signed-in Admin's own Signal plan for today (the Europe/Prague day),
 * read-only, the first three items always visible and the rest behind a toggle that is collapsed on every load
 * (D-11; UI-SPEC E4, U-9). It replaces the plan for tomorrow of the first version, on the owner's request.
 *
 * The items are what the planner page "Dnes a zítra" shows for today: the real tasks of the day, then the recurring
 * templates that fire today and have no task yet. The planner turns those into tasks when the Admin opens the day;
 * this widget never does. It calls no Signal action, so a GET of the dashboard writes nothing: no tick, no drag, no
 * add, no edit, no lock, no materialisation. Both lists come from {@see SignalDayReader}, whose queries run through
 * the owner scope of the Signal models, so another Admin's rows can never appear. A task that is already done is
 * marked.
 *
 * Admin only: a Partner is refused at boot, before anything is read. The widget does not poll; the plan changes
 * when the Admin edits it in the planner, not by the clock.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The planner is the personal working tool of one Admin; a Partner is refused at boot and never sees the plan.')]
final class TodayPlan extends Widget
{
    use CollapsibleDashboardWidget;
    use EnforcesWidgetAccessRule;

    /** The items that stay visible when the rest is collapsed. */
    public const int VISIBLE = 3;

    protected static ?int $sort = 15;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /**
     * The items of the current render, read once for the summary and the view.
     *
     * @var list<array{title: string, category_label: string, category_colour: string, recurring: bool, done: bool}>|null
     */
    private ?array $items = null;

    /** @var view-string */
    protected string $view = 'filament.widgets.today-plan';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /**
     * Today's items in the order of the planner page: the tasks by colour and position, then the templates.
     *
     * @return list<array{title: string, category_label: string, category_colour: string, recurring: bool, done: bool}>
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $reader = app(SignalDayReader::class);
        $day = SignalCalendar::today();
        $tasks = $reader->tasks($day);

        $items = [];

        foreach ($tasks as $task) {
            $items[] = [
                'title' => $task->title,
                'category_label' => $task->category->getLabel(),
                'category_colour' => $task->category->getColor(),
                'recurring' => $task->recurring_id !== null,
                'done' => $task->is_done,
            ];
        }

        foreach ($reader->shadows($day, $tasks) as $template) {
            $items[] = [
                'title' => $template->title,
                'category_label' => $template->category->getLabel(),
                'category_colour' => $template->category->getColor(),
                'recurring' => true,
                'done' => false,
            ];
        }

        return $this->items = $items;
    }

    public static function dashboardWidget(): DashboardWidget
    {
        return DashboardWidget::TodayPlan;
    }

    protected function cardHeading(): string
    {
        return __('kokpit.dashboard.plan.heading');
    }

    /**
     * How many of today's items are done; none when there is no item (the count badge says so).
     */
    protected function collapsedSummary(): ?string
    {
        $items = $this->items();

        if ($items === []) {
            return null;
        }

        $done = count(array_filter($items, static fn (array $item): bool => $item['done']));

        return __('kokpit.dashboard.plan.summary', ['done' => $done, 'count' => count($items)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $items = $this->items();

        return [
            'dateLabel' => SignalCalendar::formatLong(SignalCalendar::today()),
            'visibleItems' => array_slice($items, 0, self::VISIBLE),
            'moreItems' => array_slice($items, self::VISIBLE),
            'count' => count($items),
            'planUrl' => SignalTodayPage::getUrl(),
        ];
    }
}
