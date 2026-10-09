<?php

declare(strict_types=1);

namespace App\Livewire\TimeTracking;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\Actions\StopTimer;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\EntryContextOptions;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Domain\TimeTracking\TimerRaceLost;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TimeEntryResource;
use App\Providers\LocalisationServiceProvider;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The timer in the top bar (TI-01, D-01, D-02, D-08): a quick start with a client choice while
 * idle, one pill with the ticking elapsed time and a stop button while running.
 *
 * The bar sits in the persisted end region of the top bar, which SPA navigation does not
 * re-render, so it refreshes itself: on the events timer-started, timer-stopped,
 * time-entry-saved and time-entry-deleted and by a 60-second visible-tab poll. The clock itself
 * ticks in the browser from the stored start instant.
 *
 * Public properties hold scalars only (the snapshot is sent to the browser); the running entry
 * is a computed array of scalars without any rate or price. Every request is refused for anybody
 * but the Admin (RequiresAdmin), and the Actions authorize again.
 *
 * @property-read array{recent: array<string, string>, all: array<string, string>, preselected: string|null} $clientGroups
 */
final class TimerBar extends Component
{
    use RequiresAdmin;

    public ?string $clientId = null;

    public string $description = '';

    public function mount(): void
    {
        $this->clientId = $this->clientGroups['preselected'];
    }

    /**
     * The running entry of the signed-in user as scalars, or null when nothing runs.
     *
     * @return array{id: string, started_at: string, now: string, elapsed: int, elapsed_text: string, client: string, task_reference: string|null, task_title: string|null, task_url: string|null, description: string|null, view_url: string, started_text: string}|null
     */
    #[Computed]
    public function running(): ?array
    {
        $entry = $this->runningEntry();

        if (! $entry instanceof TimeEntry) {
            return null;
        }

        $now = TimerClock::now();
        $zone = FilamentTimezone::get();
        $elapsed = max(0, $now->getTimestamp() - $entry->started_at->getTimestamp());
        $started = $entry->started_at->setTimezone($zone);
        $task = $entry->task;

        return [
            'id' => $entry->id,
            'started_at' => $entry->started_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'now' => $now->utc()->format('Y-m-d\TH:i:s\Z'),
            'elapsed' => $elapsed,
            'elapsed_text' => DurationFormat::hoursMinutesSeconds($elapsed),
            'client' => (string) $entry->client?->name,
            'task_reference' => $task?->reference,
            'task_title' => $task?->title,
            'task_url' => $task === null ? null : TaskResource::getUrl('view', ['record' => $task]),
            'description' => $entry->description,
            'view_url' => TimeEntryResource::getUrl('view', ['record' => $entry]),
            'started_text' => $started->isSameDay($now->setTimezone($zone))
                ? $started->format(LocalisationServiceProvider::TIME_FORMAT)
                : $started->format(LocalisationServiceProvider::DATE_TIME_FORMAT),
        ];
    }

    /**
     * The clients of the idle dropdown: the recently used ones, all the others and the
     * preselected one.
     *
     * @return array{recent: array<string, string>, all: array<string, string>, preselected: string|null}
     */
    #[Computed]
    public function clientGroups(): array
    {
        return app(EntryContextOptions::class)->timerClients($this->actor());
    }

    /**
     * Starts a timer for the chosen client; a running one is stopped and kept (D-02).
     */
    public function start(): void
    {
        try {
            $result = app(StartTimer::class)->handle($this->actor(), [
                'client_id' => $this->clientId,
                'description' => $this->description,
            ]);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($this->firstMessage($e))->send();

            return;
        } catch (TimerRaceLost $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->refreshState();

            return;
        }

        $stopped = $result['stopped'];

        Notification::make()
            ->success()
            ->title(__('kokpit.time.timer.started'))
            ->body($stopped instanceof TimeEntry
                ? (string) __('kokpit.time.timer.started_previous', ['duration' => DurationFormat::hoursMinutes((int) $stopped->duration_seconds)])
                : null)
            ->send();

        $this->description = '';
        $this->refreshState();
        $this->dispatch('timer-started');
    }

    /**
     * Stops the running timer. The pill names the entry it shows, so a stale view can never stop
     * a newer timer; with nothing to stop the answer is an info toast.
     */
    public function stop(?string $entryId = null): void
    {
        $stopped = app(StopTimer::class)->handle($this->actor(), $entryId);

        if ($stopped instanceof TimeEntry) {
            Notification::make()
                ->success()
                ->title(__('kokpit.time.timer.stopped'))
                ->body(DurationFormat::hoursMinutes((int) $stopped->duration_seconds))
                ->send();
        } else {
            Notification::make()->info()->title(__('kokpit.time.timer.nothing_running'))->send();
        }

        $this->refreshState();
        $this->dispatch('timer-stopped');
    }

    /**
     * Reads the state again: on the events of the other timer surfaces and by the poll.
     */
    #[On('timer-started')]
    #[On('timer-stopped')]
    #[On('time-entry-saved')]
    #[On('time-entry-deleted')]
    public function refreshState(): void
    {
        unset($this->running, $this->clientGroups);

        $groups = $this->clientGroups;

        // A client archived meanwhile, or none chosen yet, falls back to the preselected one.
        if ($this->clientId === null || ! (array_key_exists($this->clientId, $groups['recent']) || array_key_exists($this->clientId, $groups['all']))) {
            $this->clientId = $groups['preselected'];
        }
    }

    public function render(): View
    {
        return view('livewire.time-tracking.timer-bar');
    }

    private function runningEntry(): ?TimeEntry
    {
        return TimeEntry::query()
            ->with(['client', 'project', 'task'])
            ->where('user_id', $this->actor()->getKey())
            ->whereNull('ended_at')
            ->first();
    }

    private function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }

    private function firstMessage(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return (string) $e->getMessage();
    }
}
