<?php

declare(strict_types=1);

namespace App\Livewire\TimeTracking;

use App\Domain\TimeTracking\Queries\RecentEntries;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The side panel "Poslední záznamy" (D-08, UI-SPEC Surface B): the detailed view of the single
 * running timer on top, below it the finished entries of the signed-in user grouped by Prague day.
 *
 * It sits at LAYOUT_END of every panel page, for the Admin only. The timer block is a second view
 * of the same one running entry as the top bar (ControlsTimer, CompletesRunningEntry), so it
 * refreshes on the same four events and by a 60-second visible-tab poll; the clock ticks in the
 * browser. The days come from RecentEntries in pages of seven days that contain entries; the view
 * keeps the number of days shown (a scalar), not the rows, so the snapshot stays small and every
 * refresh reads the shown days again. Every request is refused for anybody but the Admin
 * (RequiresAdmin), and the Actions authorize again.
 *
 * @property-read array{days: list<array{date: string, label: string, total_seconds: int, entries: list<array<string, scalar|null>>}>, has_more: bool} $recent
 */
final class RecentEntriesPanel extends Component implements HasActions, HasSchemas
{
    use CompletesRunningEntry, ControlsTimer, InteractsWithActions, InteractsWithSchemas, RequiresAdmin;

    private const int PAGE_DAYS = 7;

    /** The number of days that contain entries and are shown, a multiple of the page size. */
    public int $visibleDays = self::PAGE_DAYS;

    /**
     * The days shown and whether older ones exist.
     *
     * @return array{days: list<array{date: string, label: string, total_seconds: int, entries: list<array<string, scalar|null>>}>, has_more: bool}
     */
    #[Computed]
    public function recent(): array
    {
        return app(RecentEntries::class)->days($this->actor(), null, $this->visibleDays);
    }

    /**
     * Appends the next seven days that contain entries.
     */
    public function loadOlder(): void
    {
        $this->visibleDays += self::PAGE_DAYS;

        unset($this->recent);
    }

    public function render(): View
    {
        return view('livewire.time-tracking.recent-entries-panel');
    }

    protected function forgetComputedState(): void
    {
        unset($this->running, $this->clientGroups, $this->recent);
    }
}
