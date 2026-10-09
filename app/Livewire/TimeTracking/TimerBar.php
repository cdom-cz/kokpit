<?php

declare(strict_types=1);

namespace App\Livewire\TimeTracking;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The timer in the top bar (TI-01, D-01, D-02, D-08): a quick start with a client choice while
 * idle, one pill with the ticking elapsed time and a stop button while running, and the toggle of
 * the side panel "Poslední záznamy".
 *
 * The bar sits in the persisted end region of the top bar, which SPA navigation does not
 * re-render, so it refreshes itself: on the events timer-started, timer-stopped,
 * time-entry-saved and time-entry-deleted and by a 60-second visible-tab poll. The clock itself
 * ticks in the browser from the stored start instant. The state and the actions are shared with
 * the side panel (ControlsTimer).
 *
 * "Doplnit záznam" opens the modal that fills in the project, task and description of the running
 * entry (CompletesRunningEntry). Every request is refused for anybody but the Admin
 * (RequiresAdmin), and the Actions authorize again.
 */
final class TimerBar extends Component implements HasActions, HasSchemas
{
    use CompletesRunningEntry, ControlsTimer, InteractsWithActions, InteractsWithSchemas, RequiresAdmin;

    public function render(): View
    {
        return view('livewire.time-tracking.timer-bar');
    }
}
