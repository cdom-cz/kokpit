<?php

declare(strict_types=1);

namespace App\Livewire\Signal;

use App\Domain\Signal\Actions\AddSignalTask;
use App\Domain\Signal\Actions\DeleteSignalTask;
use App\Domain\Signal\Actions\MaterializeSignalRecurring;
use App\Domain\Signal\Actions\ReorderSignalTasks;
use App\Domain\Signal\Actions\SetSignalDayUnlocked;
use App\Domain\Signal\Actions\SetSignalDeepWork;
use App\Domain\Signal\Actions\ToggleSignalTaskDone;
use App\Domain\Signal\Actions\UpdateSignalTask;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalRules;
use App\Livewire\TimeTracking\RequiresAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * One day of the planner: the tasks by colour with the add form, the deep-work blocks, the recurring
 * shadows of a later day and the lock of a past day. Everything is a Livewire request, so ticking,
 * adding, editing, deleting and dragging never reload the page.
 *
 * It is embedded by the Today page (today, tomorrow, a browsed day) and by the overview. After every
 * change it dispatches `signal-changed`, so the cards of the page around it read again.
 *
 * The component only reads and dispatches; the rules live in the domain Actions and run again there
 * (the authority), the same pure rules decide here which controls are disabled. The public properties
 * are scalars, because they travel to the browser. Admin only (RequiresAdmin).
 *
 * @property-read array<string, mixed> $day
 */
final class SignalDay extends Component
{
    use RequiresAdmin, RunsSignalActions;

    /** The day shown, `YYYY-MM-DD`. */
    public string $forDate = '';

    /** The heading of the card ("Dnes", "Zítra" ...). */
    public string $heading = '';

    public string $newTitle = '';

    public string $newCategory = 'main';

    public ?string $editingId = null;

    public string $editTitle = '';

    public string $editCategory = 'main';

    public function mount(string $forDate, string $heading = ''): void
    {
        abort_unless(SignalCalendar::isValidDay($forDate), 404);

        $this->forDate = $forDate;
        $this->heading = $heading;
    }

    public function addTask(): void
    {
        $category = SignalCategory::tryFrom($this->newCategory);

        if ($this->attempt(fn ($actor) => app(AddSignalTask::class)->handle($actor, $this->newTitle, $this->forDate, $category))) {
            $this->newTitle = '';
            $this->changed();
        }
    }

    public function toggleDone(string $id, bool $done): void
    {
        if ($this->attempt(fn ($actor) => app(ToggleSignalTaskDone::class)->handle($actor, $id, $done))) {
            $this->changed();
        }
    }

    public function startEdit(string $id): void
    {
        $task = SignalTask::query()->whereKey($id)->where('for_date', $this->forDate)->first();

        if ($task === null) {
            return;
        }

        $this->editingId = $task->id;
        $this->editTitle = $task->title;
        $this->editCategory = $task->category->isPlannable() ? $task->category->value : 'main';
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editTitle = '';
    }

    public function saveEdit(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $category = SignalCategory::tryFrom($this->editCategory);

        if ($this->attempt(fn ($actor) => app(UpdateSignalTask::class)->handle($actor, (string) $this->editingId, $this->editTitle, $category))) {
            $this->cancelEdit();
            $this->changed();
        }
    }

    public function deleteTask(string $id): void
    {
        if ($this->attempt(fn ($actor) => app(DeleteSignalTask::class)->handle($actor, $id))) {
            if ($this->editingId === $id) {
                $this->cancelEdit();
            }

            $this->changed();
        }
    }

    /**
     * The drop handler of `wire:sort`: the id of the dragged task and its new zero-based index inside
     * its colour group. A drag never leaves its group; the group is the one the task belongs to.
     */
    public function reorder(string $item, int $position): void
    {
        $task = SignalTask::query()->whereKey($item)->where('for_date', $this->forDate)->first();

        if ($task === null) {
            $this->changed();

            return;
        }

        $ids = app(SignalDayReader::class)->tasks($this->forDate)
            ->filter(static fn (SignalTask $other): bool => $other->category === $task->category)
            ->pluck('id')
            ->map(strval(...))
            ->reject(static fn (string $id): bool => $id === $item)
            ->values()
            ->all();

        array_splice($ids, max(0, min($position, count($ids))), 0, [$item]);

        $this->attempt(fn ($actor) => app(ReorderSignalTasks::class)->handle($actor, $this->forDate, $task->category, $ids));
        $this->changed();
    }

    /**
     * Sets the completed deep-work blocks. Clicking the last completed block takes it back.
     */
    public function setBlocks(int $completed): void
    {
        $current = app(SignalDayReader::class)->deepWork($this->forDate)['completed'];

        if ($this->attempt(fn ($actor) => app(SetSignalDeepWork::class)->handle($actor, $this->forDate, $completed === $current ? $completed - 1 : $completed))) {
            $this->changed();
        }
    }

    public function unlock(): void
    {
        if ($this->attempt(fn ($actor) => app(SetSignalDayUnlocked::class)->handle($actor, $this->forDate, true))) {
            $this->changed();
        }
    }

    public function lock(): void
    {
        if ($this->attempt(fn ($actor) => app(SetSignalDayUnlocked::class)->handle($actor, $this->forDate, false))) {
            $this->cancelEdit();
            $this->changed();
        }
    }

    /**
     * Everything the view shows, as scalars and arrays.
     *
     * @return array{
     *     date_label: string,
     *     is_today: bool,
     *     is_past: bool,
     *     locked: bool,
     *     unlocked: bool,
     *     can_edit: bool,
     *     can_toggle: bool,
     *     adds_extra: bool,
     *     groups: list<array{category: string, label: string, color: string, limit: int|null, tasks: list<array{id: string, title: string, done: bool, recurring: bool}>}>,
     *     shadows: list<array{id: string, title: string, category: string, label: string, color: string}>,
     *     options: array<string, string>,
     *     blocks: array{planned: int, completed: int},
     *     total: int,
     *     done: int
     * }
     */
    #[Computed]
    public function day(): array
    {
        $reader = app(SignalDayReader::class);
        $today = SignalCalendar::today();

        if ($this->forDate === $today) {
            app(MaterializeSignalRecurring::class)->handle($this->actor(), $today);
        }

        $tasks = $reader->tasks($this->forDate);
        $unlocked = $reader->isUnlocked($this->forDate);
        $reserved = $reader->recurringCounts($this->forDate);
        $addsExtra = SignalRules::isExtra($this->forDate, $today);

        $groups = [];
        $options = [];

        foreach (SignalCategory::cases() as $category) {
            $inGroup = $tasks->filter(static fn (SignalTask $task): bool => $task->category === $category);

            if ($category->isPlannable()) {
                $left = SignalRules::effectiveLimit($category, $reserved[$category->value]);
                $left = $left === null ? null : max(0, $left - $inGroup->count());
                $options[$category->value] = $category->getLabel().($left === null ? '' : ' ('.__('kokpit.signal.day.left', ['count' => $left]).')');
            }

            if ($inGroup->isEmpty()) {
                continue;
            }

            $groups[] = [
                'category' => $category->value,
                'label' => $category->getLabel(),
                'color' => $category->getColor(),
                'limit' => $category->dailyLimit(),
                'tasks' => $inGroup->map(static fn (SignalTask $task): array => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'done' => $task->is_done,
                    'recurring' => $task->recurring_id !== null,
                ])->values()->all(),
            ];
        }

        $shadows = $this->forDate > $today
            ? $reader->shadows($this->forDate, $tasks)->map(static fn ($template): array => [
                'id' => $template->id,
                'title' => $template->title,
                'category' => $template->category->value,
                'label' => $template->category->getLabel(),
                'color' => $template->category->getColor(),
            ])->all()
            : [];

        return [
            'date_label' => SignalCalendar::formatLong($this->forDate),
            'is_today' => $this->forDate === $today,
            'is_past' => $this->forDate < $today,
            'locked' => SignalRules::isDayLocked($this->forDate, $unlocked, $today),
            'unlocked' => $unlocked && $this->forDate < $today,
            'can_edit' => SignalRules::canEditDay($this->forDate, $unlocked, $today),
            'can_toggle' => SignalRules::canToggleDone($this->forDate, $today),
            'adds_extra' => $addsExtra,
            'groups' => $groups,
            'shadows' => $shadows,
            'options' => $options,
            'blocks' => $reader->deepWork($this->forDate),
            'total' => $tasks->count(),
            'done' => $tasks->where('is_done', true)->count(),
        ];
    }

    public function render(): View
    {
        return view('livewire.signal.day');
    }

    private function changed(): void
    {
        unset($this->day);

        $this->dispatch('signal-changed');
    }
}
