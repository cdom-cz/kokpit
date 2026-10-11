<?php

declare(strict_types=1);

use App\Domain\Signal\Actions\DeleteSignalTask;
use App\Domain\Signal\Actions\MaterializeSignalRecurring;
use App\Domain\Signal\Actions\SaveSignalRecurring;
use App\Domain\Signal\Actions\SetSignalDayUnlocked;
use App\Domain\Signal\Actions\UpdateSignalTask;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Livewire\Signal\SignalDay;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Recurring templates and their materialization into the tasks of today: the slot of a template is
 * reserved once, a task that its template would produce again cannot be deleted, and the day never
 * holds more coloured tasks than its limit. The clock is frozen on Monday 12 Oct 2026 (Prague noon);
 * every title is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * A template that fires every day of the week.
 *
 * @param  list<int>  $weekdays
 */
function recurringTemplate(object $test, string $title, SignalCategory $category, array $weekdays = [0, 1, 2, 3, 4, 5, 6]): SignalRecurringTask
{
    return app(SaveSignalRecurring::class)->handle($test->admin, null, $title, $category, $weekdays);
}

/**
 * A task straight in the table, bypassing the Actions, for arranging a state.
 */
function recurringTask(string $day, SignalCategory $category, string $title = 'Example task', ?string $recurringId = null, int $position = 0): SignalTask
{
    return SignalTask::query()->create([
        'title' => $title,
        'for_date' => $day,
        'category' => $category,
        'is_done' => false,
        'recurring_id' => $recurringId,
        'position' => $position,
    ]);
}

it('reserves the slot of a template only until it has a task on the day', function (): void {
    recurringTemplate($this, 'Example standup', SignalCategory::Main);
    recurringTemplate($this, 'Example review', SignalCategory::Main);
    $reader = app(SignalDayReader::class);

    expect($reader->recurringCounts('2026-10-12')['main'])->toBe(2)
        ->and($reader->categoryCounts('2026-10-12')['main'])->toBe(0);

    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');

    expect($reader->recurringCounts('2026-10-12')['main'])->toBe(0)
        ->and($reader->categoryCounts('2026-10-12')['main'])->toBe(2);
});

it('counts a materialized template once, so one of three main slots is still free for a move', function (): void {
    recurringTemplate($this, 'Example standup', SignalCategory::Main);
    recurringTemplate($this, 'Example review', SignalCategory::Main);
    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');
    $first = recurringTask('2026-10-12', SignalCategory::Other, 'Example green one');
    $second = recurringTask('2026-10-12', SignalCategory::Other, 'Example green two', position: 1);
    $update = app(UpdateSignalTask::class);

    expect($update->handle($this->admin, $first->id, 'Example green one', SignalCategory::Main)->category)->toBe(SignalCategory::Main)
        ->and(fn () => $update->handle($this->admin, $second->id, 'Example green two', SignalCategory::Main))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.limit_main', ['limit' => 3]))
        ->and($second->fresh()->category)->toBe(SignalCategory::Other);
});

it('says in the select that one main slot is left when two materialized templates hold the other two', function (): void {
    recurringTemplate($this, 'Example standup', SignalCategory::Main);
    recurringTemplate($this, 'Example review', SignalCategory::Main);

    $day = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->instance()->day;

    expect($day['options']['main'])->toContain(__('kokpit.signal.day.left', ['count' => 1]))
        ->and(SignalTask::query()->where('category', 'main')->count())->toBe(2);
});

it('still reserves the slot of a template that was created after the materialization of the day', function (): void {
    recurringTemplate($this, 'Example standup', SignalCategory::Main);
    recurringTemplate($this, 'Example review', SignalCategory::Main);
    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');
    recurringTemplate($this, 'Example late template', SignalCategory::Main);
    $green = recurringTask('2026-10-12', SignalCategory::Other, 'Example green');

    expect(fn () => app(UpdateSignalTask::class)->handle($this->admin, $green->id, 'Example green', SignalCategory::Main))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.limit_main', ['limit' => 2]));
});

it('refuses to delete a task of today that its template would generate again', function (): void {
    recurringTemplate($this, 'Example standup', SignalCategory::Other);
    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');
    $task = SignalTask::query()->firstOrFail();

    expect(fn () => app(DeleteSignalTask::class)->handle($this->admin, $task->id))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.recurring_pinned'))
        ->and(SignalTask::query()->whereKey($task->id)->exists())->toBeTrue();

    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');

    expect(SignalTask::query()->pluck('id')->all())->toBe([$task->id]);
});

it('deletes a recurring task once its template is switched off, no longer fires today or is gone', function (): void {
    $off = recurringTemplate($this, 'Example off', SignalCategory::Other);
    $tuesday = recurringTemplate($this, 'Example weekdays', SignalCategory::Other);
    $gone = recurringTemplate($this, 'Example gone', SignalCategory::Other);
    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');

    $off->forceFill(['active' => false])->save();
    app(SaveSignalRecurring::class)->handle($this->admin, $tuesday->id, 'Example weekdays', SignalCategory::Other, [1]);
    $gone->delete();

    foreach (SignalTask::query()->get() as $task) {
        app(DeleteSignalTask::class)->handle($this->admin, $task->id);
    }

    expect(SignalTask::query()->count())->toBe(0);
});

it('deletes the instance of a template on an unlocked past day', function (): void {
    $template = recurringTemplate($this, 'Example standup', SignalCategory::Other);
    $past = recurringTask('2026-10-11', SignalCategory::Other, 'Example standup', $template->id);
    app(SetSignalDayUnlocked::class)->handle($this->admin, '2026-10-11', true);

    app(DeleteSignalTask::class)->handle($this->admin, $past->id);

    expect(SignalTask::query()->count())->toBe(0);
});

it('hides the delete button of a pinned task, refuses the call and never writes while rendering', function (): void {
    $pinned = recurringTemplate($this, 'Example pinned', SignalCategory::Other);
    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12']);
    $pinnedTask = SignalTask::query()->where('recurring_id', $pinned->id)->firstOrFail();
    $ordinary = recurringTask('2026-10-12', SignalCategory::Extra, 'Example ordinary');

    $component->call('$refresh');
    $tasks = collect($component->instance()->day['groups'])->flatMap(static fn (array $group): array => $group['tasks'])->keyBy('id');

    expect($tasks[$pinnedTask->id]['deletable'])->toBeFalse()
        ->and($tasks[$ordinary->id]['deletable'])->toBeTrue();

    $component->assertDontSeeHtml("deleteTask('{$pinnedTask->id}')")
        ->assertSeeHtml("deleteTask('{$ordinary->id}')")
        ->call('deleteTask', $pinnedTask->id);

    expect(SignalTask::query()->whereKey($pinnedTask->id)->exists())->toBeTrue();

    $component->call('deleteTask', $ordinary->id);

    expect(SignalTask::query()->whereKey($ordinary->id)->exists())->toBeFalse();
});

it('does not bring back a task of a switched-off template that was deleted', function (): void {
    $template = recurringTemplate($this, 'Example standup', SignalCategory::Other);
    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12']);
    $task = SignalTask::query()->where('recurring_id', $template->id)->firstOrFail();

    $template->forceFill(['active' => false])->save();
    $component->call('deleteTask', $task->id)->call('$refresh');

    expect(SignalTask::query()->count())->toBe(0);
});

it('survives a template that is deleted or switched off right after the day lock was taken', function (string $change): void {
    $template = recurringTemplate($this, 'Example vanishing', SignalCategory::Other);
    $done = false;

    DB::listen(function (QueryExecuted $query) use (&$done, $template, $change): void {
        if ($done || ! str_contains($query->sql, 'pg_advisory_xact_lock')) {
            return;
        }

        $done = true;
        $change === 'delete'
            ? DB::table('signal_recurring_tasks')->where('id', $template->id)->delete()
            : DB::table('signal_recurring_tasks')->where('id', $template->id)->update(['active' => false]);
    });

    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');

    expect($done)->toBeTrue()
        ->and(SignalTask::query()->count())->toBe(0);
})->with(['the template is deleted' => ['delete'], 'the template is switched off' => ['off']]);

it('turns the task of a template into a grey one when three main tasks are planned already, and stores it once', function (): void {
    foreach (range(0, 2) as $position) {
        recurringTask('2026-10-12', SignalCategory::Main, "Example planned {$position}", position: $position);
    }

    $template = recurringTemplate($this, 'Example overflow', SignalCategory::Main);

    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');
    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');

    $generated = SignalTask::query()->where('recurring_id', $template->id)->get();

    expect($generated)->toHaveCount(1)
        ->and($generated[0]->category)->toBe(SignalCategory::Extra)
        ->and(SignalTask::query()->where('category', 'main')->count())->toBe(3);
});

it('keeps the colour of a template task while the day has a free slot of that colour', function (): void {
    recurringTask('2026-10-12', SignalCategory::Main, 'Example planned');
    $template = recurringTemplate($this, 'Example fits', SignalCategory::Main);

    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');

    expect(SignalTask::query()->where('recurring_id', $template->id)->firstOrFail()->category)->toBe(SignalCategory::Main);
});

it('fills the slots in the order of the templates and makes the rest grey', function (): void {
    recurringTask('2026-10-12', SignalCategory::Main, 'Example planned one');
    recurringTask('2026-10-12', SignalCategory::Main, 'Example planned two', position: 1);
    $first = recurringTemplate($this, 'Example first', SignalCategory::Main);
    $second = recurringTemplate($this, 'Example second', SignalCategory::Main);

    app(MaterializeSignalRecurring::class)->handle($this->admin, '2026-10-12');

    expect(SignalTask::query()->where('recurring_id', $first->id)->firstOrFail()->category)->toBe(SignalCategory::Main)
        ->and(SignalTask::query()->where('recurring_id', $second->id)->firstOrFail()->category)->toBe(SignalCategory::Extra)
        ->and(SignalTask::query()->where('category', 'main')->count())->toBe(3);
});

it('shows the shadow of a template on a full day in the colour its task will get', function (): void {
    foreach (range(0, 2) as $position) {
        recurringTask('2026-10-13', SignalCategory::Main, "Example planned {$position}", position: $position);
    }

    // Tuesday (index 1).
    recurringTemplate($this, 'Example overflow', SignalCategory::Main, [1]);
    recurringTemplate($this, 'Example fits', SignalCategory::Other, [1]);

    $shadows = Livewire::test(SignalDay::class, ['forDate' => '2026-10-13'])->instance()->day['shadows'];

    expect(array_column($shadows, 'category'))->toBe(['extra', 'other'])
        ->and($shadows[0]['label'])->toBe(SignalCategory::Extra->getLabel());
});

it('materializes when the day component is mounted for today, and never again while it renders', function (): void {
    $template = recurringTemplate($this, 'Example standup', SignalCategory::Other);

    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12']);

    expect(SignalTask::query()->where('recurring_id', $template->id)->count())->toBe(1);

    // The row disappears behind the back of the component: reading again must not write.
    DB::table('signal_tasks')->delete();
    $component->call('$refresh')->call('setBlocks', 1);

    expect(SignalTask::query()->count())->toBe(0);
});

it('materializes nothing when the component shows a day that is not today', function (): void {
    recurringTemplate($this, 'Example standup', SignalCategory::Other);

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-11']);
    Livewire::test(SignalDay::class, ['forDate' => '2026-10-13']);

    expect(SignalTask::query()->count())->toBe(0);
});

it('opens no transaction and takes no lock when no template is pending', function (): void {
    $lockQueries = 0;
    $transactions = 0;
    DB::listen(function (QueryExecuted $query) use (&$lockQueries): void {
        $lockQueries += str_contains($query->sql, 'pg_advisory_xact_lock') ? 1 : 0;
    });
    Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });
    $materialize = app(MaterializeSignalRecurring::class);

    // No template at all, then a template that fires on Tuesdays only.
    $materialize->handle($this->admin, '2026-10-12');
    recurringTemplate($this, 'Example tuesday', SignalCategory::Other, [1]);
    $materialize->handle($this->admin, '2026-10-12');

    expect([$lockQueries, $transactions])->toBe([0, 0]);

    // One that is pending takes the lock once; afterwards it is done and nothing is opened again.
    recurringTemplate($this, 'Example monday', SignalCategory::Other, [0]);
    $materialize->handle($this->admin, '2026-10-12');

    expect([$lockQueries, $transactions])->toBe([1, 1]);

    $materialize->handle($this->admin, '2026-10-12');

    expect([$lockQueries, $transactions])->toBe([1, 1]);
});
