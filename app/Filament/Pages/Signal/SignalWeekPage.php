<?php

declare(strict_types=1);

namespace App\Filament\Pages\Signal;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Signal\Actions\AddSignalGoal;
use App\Domain\Signal\Actions\DeleteSignalGoal;
use App\Domain\Signal\Actions\ReorderSignalGoals;
use App\Domain\Signal\Actions\SaveSignalRecap;
use App\Domain\Signal\Actions\ToggleSignalGoal;
use App\Domain\Signal\Actions\UpdateSignalGoal;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Queries\SignalOverviewReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Concerns\InSignalGroup;
use App\Livewire\Signal\RunsSignalActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * "Týden": the (at most three) goals of an ISO week and the Friday recap. Moving between weeks,
 * adding, editing, ticking, deleting and dragging goals and saving the recap are Livewire requests.
 *
 * @property-read list<array{id: string, title: string, done: bool}> $goals
 */
#[AccessRule(Audience::AdminOnly, reason: 'The weekly goals and the recap are the personal planning notes of one Admin; a Partner has no part of them.')]
class SignalWeekPage extends Page
{
    use EnforcesPageAccessRule, InSignalGroup, RunsSignalActions;

    protected static ?string $slug = 'signal/week';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.signal.week';

    /** The Monday of the shown week (`YYYY-MM-DD`). */
    #[Url(as: 'week', except: '')]
    public string $week = '';

    public string $newGoal = '';

    public ?string $editingGoalId = null;

    public string $editGoalTitle = '';

    public string $wentWell = '';

    public string $toChange = '';

    public static function getNavigationLabel(): string
    {
        return __('kokpit.signal.week.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedFlag;
    }

    public function getTitle(): string
    {
        return __('kokpit.signal.week.title');
    }

    public function mount(): void
    {
        $this->goToWeek($this->week);
    }

    /**
     * Shows the week that contains the given day; anything that is no day shows the current week.
     */
    public function goToWeek(string $day): void
    {
        $this->week = SignalCalendar::isValidDay($day) ? SignalCalendar::weekStart($day) : SignalCalendar::currentWeekStart();
        $this->cancelGoalEdit();
        $this->newGoal = '';

        $recap = app(SignalOverviewReader::class)->recap($this->week);
        $this->wentWell = $recap->what_went_well ?? '';
        $this->toChange = $recap->what_to_change ?? '';

        unset($this->goals);
    }

    /**
     * @return list<array{id: string, title: string, done: bool}>
     */
    #[Computed]
    public function goals(): array
    {
        return app(SignalOverviewReader::class)->goals($this->week)
            ->map(static fn (SignalWeeklyGoal $goal): array => ['id' => $goal->id, 'title' => $goal->title, 'done' => $goal->is_done])
            ->values()
            ->all();
    }

    public function addGoal(): void
    {
        if ($this->attempt(fn ($actor) => app(AddSignalGoal::class)->handle($actor, $this->week, $this->newGoal))) {
            $this->newGoal = '';
            $this->changed();
        }
    }

    public function toggleGoal(string $id, bool $done): void
    {
        if ($this->attempt(fn ($actor) => app(ToggleSignalGoal::class)->handle($actor, $id, $done))) {
            $this->changed();
        }
    }

    public function startGoalEdit(string $id): void
    {
        foreach ($this->goals as $goal) {
            if ($goal['id'] === $id) {
                $this->editingGoalId = $id;
                $this->editGoalTitle = $goal['title'];
            }
        }
    }

    public function cancelGoalEdit(): void
    {
        $this->editingGoalId = null;
        $this->editGoalTitle = '';
    }

    public function saveGoalEdit(): void
    {
        if ($this->editingGoalId === null) {
            return;
        }

        if ($this->attempt(fn ($actor) => app(UpdateSignalGoal::class)->handle($actor, (string) $this->editingGoalId, $this->editGoalTitle))) {
            $this->cancelGoalEdit();
            $this->changed();
        }
    }

    public function deleteGoal(string $id): void
    {
        if ($this->attempt(fn ($actor) => app(DeleteSignalGoal::class)->handle($actor, $id))) {
            $this->changed();
        }
    }

    /**
     * The drop handler of `wire:sort`: the dragged goal and its new zero-based index.
     */
    public function reorderGoals(string $item, int $position): void
    {
        $ids = array_values(array_filter(array_column($this->goals, 'id'), static fn (string $id): bool => $id !== $item));
        array_splice($ids, max(0, min($position, count($ids))), 0, [$item]);

        $this->attempt(fn ($actor) => app(ReorderSignalGoals::class)->handle($actor, $this->week, $ids));
        $this->changed();
    }

    public function saveRecap(): void
    {
        if ($this->attempt(fn ($actor) => app(SaveSignalRecap::class)->handle($actor, $this->week, $this->wentWell, $this->toChange))) {
            Notification::make()->title(__('kokpit.signal.week.recap_saved'))->success()->send();
        }
    }

    /**
     * @return array{start: string, end: string, label: string, isCurrent: bool, previous: string, next: string, current: string}
     */
    protected function getViewData(): array
    {
        $current = SignalCalendar::currentWeekStart();

        return [
            'start' => $this->week,
            'end' => SignalCalendar::addDays($this->week, 6),
            'label' => SignalCalendar::format($this->week).' – '.SignalCalendar::format(SignalCalendar::addDays($this->week, 6)),
            'isCurrent' => $this->week === $current,
            'previous' => SignalCalendar::addDays($this->week, -7),
            'next' => SignalCalendar::addDays($this->week, 7),
            'current' => $current,
        ];
    }

    private function changed(): void
    {
        unset($this->goals);

        $this->dispatch('signal-changed');
    }
}
