<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Pages\Signal\SignalTodayPage;
use Filament\Widgets\Widget;

/**
 * "Plán na zítřek" on the Admin dashboard: the signed-in Admin's own Signal plan for tomorrow, read-only, the first
 * three items always visible and the rest behind a toggle that is collapsed on every load (D-11; UI-SPEC E4, U-9).
 *
 * The items are what the planner page "Dnes a zítra" shows for tomorrow: the real tasks of the day, then the
 * recurring templates that fire tomorrow and are not yet materialised (they become tasks only on the day itself).
 * Both come from {@see SignalDayReader}, whose queries run through the owner scope of the Signal models, so another
 * Admin's rows can never appear. Nothing here calls a Signal action: no tick, no drag, no add, no edit, no lock.
 *
 * Admin only: a Partner is refused at boot, before anything is read. The widget does not poll; the plan changes
 * when the Admin edits it in the planner, not by the clock.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The planner is the personal working tool of one Admin; a Partner is refused at boot and never sees the plan.')]
final class TomorrowPlan extends Widget
{
    use EnforcesWidgetAccessRule;

    /** The items that stay visible when the rest is collapsed. */
    public const int VISIBLE = 3;

    protected static ?int $sort = 50;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** @var view-string */
    protected string $view = 'filament.widgets.tomorrow-plan';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /**
     * Tomorrow's items in the order of the planner page: the tasks by colour and position, then the templates.
     *
     * @return list<array{title: string, category_label: string, category_colour: string, recurring: bool}>
     */
    public function items(): array
    {
        $reader = app(SignalDayReader::class);
        $day = SignalCalendar::tomorrow();
        $tasks = $reader->tasks($day);

        $items = [];

        foreach ($tasks as $task) {
            $items[] = [
                'title' => $task->title,
                'category_label' => $task->category->getLabel(),
                'category_colour' => $task->category->getColor(),
                'recurring' => $task->recurring_id !== null,
            ];
        }

        foreach ($reader->shadows($day, $tasks) as $template) {
            $items[] = [
                'title' => $template->title,
                'category_label' => $template->category->getLabel(),
                'category_colour' => $template->category->getColor(),
                'recurring' => true,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $items = $this->items();

        return [
            'dateLabel' => SignalCalendar::formatLong(SignalCalendar::tomorrow()),
            'visibleItems' => array_slice($items, 0, self::VISIBLE),
            'moreItems' => array_slice($items, self::VISIBLE),
            'count' => count($items),
            'planUrl' => SignalTodayPage::getUrl(),
        ];
    }
}
