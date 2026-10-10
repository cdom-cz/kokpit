<?php

declare(strict_types=1);

namespace App\Filament\Pages\Signal;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Queries\SignalOverviewReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalStats;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Concerns\InSignalGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * "Přehled": today's progress by colour, the goals of the week, the 7- and 30-day completion
 * trends, the deep-work trend, planned against extra tasks, the streak of days with every main task
 * done and the latest recaps, with the interactive card of today on top.
 *
 * The card of today is the SignalDay component; its `signal-changed` event makes this page read
 * again, so the figures follow every tick without a reload.
 *
 * @property-read array<string, mixed> $overview
 */
#[AccessRule(Audience::AdminOnly, reason: 'The overview aggregates the personal planner of one Admin; a Partner has no part of it.')]
class SignalDashboardPage extends Page
{
    use EnforcesPageAccessRule, InSignalGroup;

    protected static ?string $slug = 'signal/overview';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.signal.dashboard';

    public static function getNavigationLabel(): string
    {
        return __('kokpit.signal.dashboard.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedChartBar;
    }

    public function getTitle(): string
    {
        return __('kokpit.signal.dashboard.title');
    }

    /**
     * Reads the figures again; the event is emitted by the planner components on this page.
     */
    #[On('signal-changed')]
    public function refresh(): void
    {
        unset($this->overview);
    }

    /**
     * @return array{
     *     today: string,
     *     progress: list<array{label: string, color: string, done: int, total: int}>,
     *     goals: list<array{title: string, done: bool}>,
     *     goalsDone: int,
     *     trend7: list<array{date: string, done: int, total: int, ratio: float}>,
     *     trend30: list<array{date: string, done: int, total: int, ratio: float}>,
     *     blocks30: list<array{date: string, done: int, total: int, ratio: float}>,
     *     blocksDone: int,
     *     split: array{planned: int, extra: int, total: int},
     *     streak: int,
     *     recaps: list<array{week: string, label: string, text: string}>
     * }
     */
    #[Computed]
    public function overview(): array
    {
        $overview = app(SignalOverviewReader::class);
        $today = SignalCalendar::today();
        $from30 = SignalCalendar::addDays($today, -29);
        $from7 = SignalCalendar::addDays($today, -6);

        $rows30 = $overview->taskRows($from30, $today);
        $todayRows = array_values(array_filter($rows30, static fn (array $row): bool => $row['for_date'] === $today));
        $byCategory = SignalStats::categoryProgress($todayRows);

        $progress = [];

        foreach (SignalCategory::cases() as $category) {
            $progress[] = ['label' => $category->getLabel(), 'color' => $category->getColor(), 'done' => $byCategory[$category->value]['done'], 'total' => $byCategory[$category->value]['total']];
        }

        $goals = $overview->goals(SignalCalendar::currentWeekStart());
        $settings = app(SignalDayReader::class)->blockSettings();
        $blocks30 = SignalStats::deepWorkTrend($overview->deepWorkRows($from30, $today), $settings['weekday'], $settings['weekend'], $from30, $today);

        return [
            'today' => $today,
            'progress' => $progress,
            'goals' => $goals->map(static fn ($goal): array => ['title' => $goal->title, 'done' => $goal->is_done])->values()->all(),
            'goalsDone' => $goals->where('is_done', true)->count(),
            'trend7' => SignalStats::dailyTrend(array_filter($rows30, static fn (array $row): bool => $row['for_date'] >= $from7), $from7, $today),
            'trend30' => SignalStats::dailyTrend($rows30, $from30, $today),
            'blocks30' => $blocks30,
            'blocksDone' => array_sum(array_column($blocks30, 'done')),
            'split' => SignalStats::plannedVsExtra($rows30),
            'streak' => SignalStats::mainStreak($rows30, $today),
            'recaps' => $overview->recentRecaps(4)->map(static fn (SignalWeeklyRecap $recap): array => [
                'week' => $recap->week_start,
                'label' => SignalCalendar::format($recap->week_start),
                'text' => $recap->what_went_well,
            ])->values()->all(),
        ];
    }
}
