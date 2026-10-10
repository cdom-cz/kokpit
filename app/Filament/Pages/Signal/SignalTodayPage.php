<?php

declare(strict_types=1);

namespace App\Filament\Pages\Signal;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Signal\Support\SignalCalendar;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Concerns\InSignalGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * "Dnes & zítra" of the planner Signal: today and tomorrow, or any other day when browsing.
 *
 * Browsing is one Livewire property (`?date=`), so moving between days never reloads the page. The
 * days themselves are the SignalDay component. Admin only: the planner is personal.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The planner is the personal working tool of one Admin; a Partner has no part of it.')]
class SignalTodayPage extends Page
{
    use EnforcesPageAccessRule, InSignalGroup;

    protected static ?string $slug = 'signal';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.signal.today';

    /** The browsed day (`YYYY-MM-DD`), empty for today and tomorrow. */
    #[Url(as: 'date', except: '')]
    public string $date = '';

    public static function getNavigationLabel(): string
    {
        return __('kokpit.signal.today.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedCalendarDays;
    }

    public function getTitle(): string
    {
        return __('kokpit.signal.today.title');
    }

    public function mount(): void
    {
        $this->goTo($this->date);
    }

    /**
     * Shows the given day; today (or anything that is no day) goes back to today and tomorrow.
     */
    public function goTo(string $day): void
    {
        $this->date = SignalCalendar::isValidDay($day) && $day !== SignalCalendar::today() ? $day : '';
    }

    /**
     * @return array{today: string, tomorrow: string, browsed: string|null, previous: string|null, next: string|null, previousLabel: string|null, nextLabel: string|null}
     */
    protected function getViewData(): array
    {
        $today = SignalCalendar::today();
        $browsed = $this->date !== '' ? $this->date : null;
        $previous = $browsed === null ? SignalCalendar::yesterday() : SignalCalendar::addDays($browsed, -1);
        $next = $browsed === null ? null : SignalCalendar::addDays($browsed, 1);

        // Going forward stops at today: the future is planned from the page of today and tomorrow.
        if ($next !== null && $next > $today) {
            $next = null;
        }

        return [
            'today' => $today,
            'tomorrow' => SignalCalendar::tomorrow(),
            'browsed' => $browsed,
            'previous' => $previous,
            'next' => $next,
            'previousLabel' => SignalCalendar::format($previous),
            'nextLabel' => $next === null ? null : SignalCalendar::format($next),
        ];
    }
}
