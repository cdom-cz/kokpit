<?php

declare(strict_types=1);

namespace App\Filament\Pages\Signal;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use App\Domain\Signal\Queries\SignalOverviewReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Concerns\InSignalGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * "Reflexe": the weekly recaps over a chosen range of weeks. The range is two Livewire properties,
 * so changing it re-reads the list without a page load. By default it shows the last twelve weeks.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The recaps are the personal notes of one Admin; a Partner has no part of them.')]
class SignalReflectionPage extends Page
{
    use EnforcesPageAccessRule, InSignalGroup;

    protected static ?string $slug = 'signal/reflection';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.signal.reflection';

    /** First day of the range (`YYYY-MM-DD`), empty for the default. */
    #[Url(as: 'from', except: '')]
    public string $from = '';

    /** Last day of the range (`YYYY-MM-DD`), empty for the default. */
    #[Url(as: 'to', except: '')]
    public string $to = '';

    public static function getNavigationLabel(): string
    {
        return __('kokpit.signal.reflection.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedLightBulb;
    }

    public function getTitle(): string
    {
        return __('kokpit.signal.reflection.title');
    }

    public function resetRange(): void
    {
        $this->from = '';
        $this->to = '';
    }

    /**
     * @return array{from: string, to: string, label: string, recaps: list<array{id: string, week: string, label: string, url: string, well: string, change: string}>}
     */
    protected function getViewData(): array
    {
        $defaultTo = SignalCalendar::currentWeekStart();
        $from = SignalCalendar::isValidDay($this->from) ? $this->from : SignalCalendar::addDays($defaultTo, -7 * 12);
        $to = SignalCalendar::isValidDay($this->to) ? $this->to : $defaultTo;

        // A reversed range is turned around instead of showing nothing.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $recaps = app(SignalOverviewReader::class)->recapsBetween($from, $to)
            ->map(static fn (SignalWeeklyRecap $recap): array => [
                'id' => $recap->id,
                'week' => $recap->week_start,
                'label' => SignalCalendar::format($recap->week_start).' – '.SignalCalendar::format(SignalCalendar::addDays($recap->week_start, 6)),
                'url' => SignalWeekPage::getUrl(['week' => $recap->week_start]),
                'well' => $recap->what_went_well,
                'change' => $recap->what_to_change,
            ])
            ->values()
            ->all();

        return [
            'from' => $from,
            'to' => $to,
            'label' => SignalCalendar::format($from).' – '.SignalCalendar::format($to),
            'recaps' => $recaps,
        ];
    }
}
