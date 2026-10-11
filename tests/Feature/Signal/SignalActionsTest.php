<?php

declare(strict_types=1);

use App\Domain\Signal\Actions\AddSignalGoal;
use App\Domain\Signal\Actions\AddSignalTask;
use App\Domain\Signal\Actions\DeleteSignalRecurring;
use App\Domain\Signal\Actions\DeleteSignalTask;
use App\Domain\Signal\Actions\MaterializeSignalRecurring;
use App\Domain\Signal\Actions\ReorderSignalGoals;
use App\Domain\Signal\Actions\ReorderSignalTasks;
use App\Domain\Signal\Actions\SaveSignalRecap;
use App\Domain\Signal\Actions\SaveSignalRecurring;
use App\Domain\Signal\Actions\SaveSignalSettings;
use App\Domain\Signal\Actions\SetSignalDayUnlocked;
use App\Domain\Signal\Actions\SetSignalDeepWork;
use App\Domain\Signal\Actions\ToggleSignalTaskDone;
use App\Domain\Signal\Actions\UpdateSignalTask;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalDeepWorkDay;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Canary;

/*
 * The write side of the planner: every rule of the original Signal, enforced in the Actions.
 * The clock is frozen on Monday 12 Oct 2026 (Prague noon); every title is fictional.
 */

const SIGNAL_TODAY = '2026-10-12';
const SIGNAL_TOMORROW = '2026-10-13';
const SIGNAL_YESTERDAY = '2026-10-11';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * A task straight in the table, bypassing the Actions, for arranging a state.
 */
function signalTask(string $day, SignalCategory $category, string $title = 'Example task', bool $done = false, int $position = 0): SignalTask
{
    return SignalTask::query()->create([
        'title' => $title,
        'for_date' => $day,
        'category' => $category,
        'is_done' => $done,
        'completed_at' => $done ? CarbonImmutable::now() : null,
        'position' => $position,
    ]);
}

it('keeps the chosen colour of a task planned ahead and appends it to its group', function (): void {
    $first = app(AddSignalTask::class)->handle($this->admin, '  Example first  ', SIGNAL_TOMORROW, SignalCategory::Main);
    $second = app(AddSignalTask::class)->handle($this->admin, 'Example second', SIGNAL_TOMORROW, SignalCategory::Main);
    $other = app(AddSignalTask::class)->handle($this->admin, 'Example other', SIGNAL_TOMORROW, SignalCategory::Other);

    expect($first->title)->toBe('Example first')
        ->and($first->category)->toBe(SignalCategory::Main)
        ->and([$first->position, $second->position, $other->position])->toBe([0, 1, 0])
        ->and($first->user_id)->toBe($this->admin->getKey());
});

it('makes a task added during its own day grey whatever colour was asked for', function (): void {
    $task = app(AddSignalTask::class)->handle($this->admin, 'Example sudden', SIGNAL_TODAY, SignalCategory::Main);

    expect($task->category)->toBe(SignalCategory::Extra);

    // Grey tasks are unlimited.
    foreach (range(1, 5) as $i) {
        app(AddSignalTask::class)->handle($this->admin, "Example extra {$i}", SIGNAL_TODAY, null);
    }

    expect(SignalTask::query()->where('for_date', SIGNAL_TODAY)->count())->toBe(6);
});

it('refuses a planned task without a plannable colour, a blank title and a bad date', function (): void {
    $add = app(AddSignalTask::class);

    expect(fn () => $add->handle($this->admin, 'Example', SIGNAL_TOMORROW, null))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.category_required'))
        ->and(fn () => $add->handle($this->admin, 'Example', SIGNAL_TOMORROW, SignalCategory::Extra))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.category_required'))
        ->and(fn () => $add->handle($this->admin, '   ', SIGNAL_TOMORROW, SignalCategory::Main))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.title_required'))
        ->and(fn () => $add->handle($this->admin, str_repeat('x', 256), SIGNAL_TOMORROW, SignalCategory::Main))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.title_too_long'))
        ->and(fn () => $add->handle($this->admin, 'Example', '2026-02-31', SignalCategory::Main))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.invalid_date'))
        ->and(SignalTask::query()->count())->toBe(0);
});

it('stops at three main and three medium tasks a day', function (): void {
    $add = app(AddSignalTask::class);

    foreach (range(1, 3) as $i) {
        $add->handle($this->admin, "Example main {$i}", SIGNAL_TOMORROW, SignalCategory::Main);
        $add->handle($this->admin, "Example medium {$i}", SIGNAL_TOMORROW, SignalCategory::Medium);
    }

    expect(fn () => $add->handle($this->admin, 'Example main 4', SIGNAL_TOMORROW, SignalCategory::Main))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.limit_main', ['limit' => 3]))
        ->and(fn () => $add->handle($this->admin, 'Example medium 4', SIGNAL_TOMORROW, SignalCategory::Medium))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.limit_medium', ['limit' => 3]));

    // Green tasks have no limit.
    $add->handle($this->admin, 'Example other', SIGNAL_TOMORROW, SignalCategory::Other);

    expect(SignalTask::query()->where('for_date', SIGNAL_TOMORROW)->count())->toBe(7);
});

it('lets every recurring template of a colour that fires on the day reserve one slot of its limit', function (): void {
    $save = app(SaveSignalRecurring::class);
    $save->handle($this->admin, null, 'Example daily one', SignalCategory::Main, [0, 1, 2, 3, 4, 5, 6]);
    $save->handle($this->admin, null, 'Example daily two', SignalCategory::Main, [0, 1, 2, 3, 4, 5, 6]);
    // Fires on Mondays only; tomorrow is a Tuesday, so it reserves nothing there.
    $save->handle($this->admin, null, 'Example monday', SignalCategory::Main, [0]);

    $add = app(AddSignalTask::class);
    $add->handle($this->admin, 'Example the one left', SIGNAL_TOMORROW, SignalCategory::Main);

    expect(fn () => $add->handle($this->admin, 'Example too many', SIGNAL_TOMORROW, SignalCategory::Main))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.limit_main', ['limit' => 1]));
});

it('refuses to add to a locked past day until it is unlocked', function (): void {
    expect(fn () => app(AddSignalTask::class)->handle($this->admin, 'Example late', SIGNAL_YESTERDAY, null))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.day_locked'));

    app(SetSignalDayUnlocked::class)->handle($this->admin, SIGNAL_YESTERDAY, true);

    $task = app(AddSignalTask::class)->handle($this->admin, 'Example late', SIGNAL_YESTERDAY, null);

    expect($task->category)->toBe(SignalCategory::Extra);

    app(SetSignalDayUnlocked::class)->handle($this->admin, SIGNAL_YESTERDAY, false);

    expect(fn () => app(AddSignalTask::class)->handle($this->admin, 'Example later', SIGNAL_YESTERDAY, null))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.day_locked'));
});

it('never locks today or the future, and unlocking them stores nothing', function (): void {
    app(SetSignalDayUnlocked::class)->handle($this->admin, SIGNAL_TODAY, true);
    app(SetSignalDayUnlocked::class)->handle($this->admin, SIGNAL_TOMORROW, true);

    expect(\App\Domain\Signal\Models\SignalDayOverride::query()->count())->toBe(0);
});

it('renames a task, moves a planned one to another colour and keeps a grey one grey', function (): void {
    $task = signalTask(SIGNAL_TOMORROW, SignalCategory::Main, 'Example old');
    $mediums = [signalTask(SIGNAL_TOMORROW, SignalCategory::Medium, 'Example m1', position: 0), signalTask(SIGNAL_TOMORROW, SignalCategory::Medium, 'Example m2', position: 1)];

    $updated = app(UpdateSignalTask::class)->handle($this->admin, $task->id, 'Example new', SignalCategory::Medium);

    expect($updated->title)->toBe('Example new')
        ->and($updated->category)->toBe(SignalCategory::Medium)
        ->and($updated->position)->toBe(2)
        ->and($mediums)->toHaveCount(2);

    $grey = signalTask(SIGNAL_TODAY, SignalCategory::Extra, 'Example grey');
    $kept = app(UpdateSignalTask::class)->handle($this->admin, $grey->id, 'Example grey renamed', SignalCategory::Main);

    expect($kept->category)->toBe(SignalCategory::Extra)->and($kept->title)->toBe('Example grey renamed');
});

it('checks the limit again when a task changes to a full colour', function (): void {
    foreach (range(1, 3) as $i) {
        signalTask(SIGNAL_TOMORROW, SignalCategory::Main, "Example main {$i}", position: $i);
    }

    $other = signalTask(SIGNAL_TOMORROW, SignalCategory::Other, 'Example other');

    expect(fn () => app(UpdateSignalTask::class)->handle($this->admin, $other->id, 'Example other', SignalCategory::Main))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.limit_main', ['limit' => 3]))
        ->and($other->fresh()->category)->toBe(SignalCategory::Other);
});

it('ticks a task done today and on a locked past day, but not on a future day', function (): void {
    $today = signalTask(SIGNAL_TODAY, SignalCategory::Extra);
    $past = signalTask(SIGNAL_YESTERDAY, SignalCategory::Main);
    $future = signalTask(SIGNAL_TOMORROW, SignalCategory::Main);

    $done = app(ToggleSignalTaskDone::class)->handle($this->admin, $today->id, true);
    app(ToggleSignalTaskDone::class)->handle($this->admin, $past->id, true);

    expect($done->is_done)->toBeTrue()
        ->and($done->completed_at)->not->toBeNull()
        ->and($past->fresh()->is_done)->toBeTrue()
        ->and(fn () => app(ToggleSignalTaskDone::class)->handle($this->admin, $future->id, true))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.future_day'));

    $undone = app(ToggleSignalTaskDone::class)->handle($this->admin, $today->id, false);

    expect($undone->is_done)->toBeFalse()->and($undone->completed_at)->toBeNull();
});

it('deletes a task only on an editable day', function (): void {
    $past = signalTask(SIGNAL_YESTERDAY, SignalCategory::Main);
    $future = signalTask(SIGNAL_TOMORROW, SignalCategory::Main);

    expect(fn () => app(DeleteSignalTask::class)->handle($this->admin, $past->id))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.day_locked'));

    app(DeleteSignalTask::class)->handle($this->admin, $future->id);

    expect(SignalTask::query()->whereKey($future->id)->exists())->toBeFalse()
        ->and(SignalTask::query()->whereKey($past->id)->exists())->toBeTrue();
});

it('stores a drag-and-drop order inside one colour group', function (): void {
    $a = signalTask(SIGNAL_TOMORROW, SignalCategory::Main, 'Example a', position: 0);
    $b = signalTask(SIGNAL_TOMORROW, SignalCategory::Main, 'Example b', position: 1);
    $c = signalTask(SIGNAL_TOMORROW, SignalCategory::Main, 'Example c', position: 2);

    app(ReorderSignalTasks::class)->handle($this->admin, SIGNAL_TOMORROW, SignalCategory::Main, [$c->id, $a->id, $b->id]);

    expect(SignalTask::query()->where('for_date', SIGNAL_TOMORROW)->orderBy('position')->pluck('title')->all())
        ->toBe(['Example c', 'Example a', 'Example b']);
});

it('refuses a stale, foreign or repeated list as a whole and changes nothing', function (): void {
    $a = signalTask(SIGNAL_TOMORROW, SignalCategory::Main, 'Example a', position: 0);
    $b = signalTask(SIGNAL_TOMORROW, SignalCategory::Main, 'Example b', position: 1);
    $other = signalTask(SIGNAL_TOMORROW, SignalCategory::Other, 'Example other', position: 0);
    $reorder = app(ReorderSignalTasks::class);
    $stale = __('kokpit.signal.errors.order_stale');

    expect(fn () => $reorder->handle($this->admin, SIGNAL_TOMORROW, SignalCategory::Main, [$b->id]))->toThrow(SignalRuleViolation::class, $stale)
        ->and(fn () => $reorder->handle($this->admin, SIGNAL_TOMORROW, SignalCategory::Main, [$b->id, $other->id]))->toThrow(SignalRuleViolation::class, $stale)
        ->and(fn () => $reorder->handle($this->admin, SIGNAL_TOMORROW, SignalCategory::Main, [$b->id, $b->id]))->toThrow(SignalRuleViolation::class, $stale)
        ->and(SignalTask::query()->where('for_date', SIGNAL_TOMORROW)->where('category', 'main')->orderBy('position')->pluck('title')->all())
        ->toBe(['Example a', 'Example b']);
});

it('materializes the recurring templates of today once, appended to their colour group', function (): void {
    // Monday = weekday 0. The Tuesday-only template must not appear today.
    app(SaveSignalRecurring::class)->handle($this->admin, null, 'Example standup', SignalCategory::Main, [0, 2]);
    app(SaveSignalRecurring::class)->handle($this->admin, null, 'Example tuesday', SignalCategory::Other, [1]);
    $off = app(SaveSignalRecurring::class)->handle($this->admin, null, 'Example switched off', SignalCategory::Other, [0]);
    $off->forceFill(['active' => false])->save();
    signalTask(SIGNAL_TODAY, SignalCategory::Main, 'Example existing', position: 0);

    app(MaterializeSignalRecurring::class)->handle($this->admin, SIGNAL_TODAY);
    app(MaterializeSignalRecurring::class)->handle($this->admin, SIGNAL_TODAY);

    $tasks = SignalTask::query()->where('for_date', SIGNAL_TODAY)->orderBy('category')->orderBy('position')->get();

    expect($tasks->pluck('title')->all())->toBe(['Example existing', 'Example standup'])
        ->and($tasks[1]->position)->toBe(1)
        ->and($tasks[1]->recurring_id)->not->toBeNull();
});

it('materializes only today, never a past or a future day', function (): void {
    app(SaveSignalRecurring::class)->handle($this->admin, null, 'Example daily', SignalCategory::Main, [0, 1, 2, 3, 4, 5, 6]);

    app(MaterializeSignalRecurring::class)->handle($this->admin, SIGNAL_TOMORROW);
    app(MaterializeSignalRecurring::class)->handle($this->admin, SIGNAL_YESTERDAY);

    expect(SignalTask::query()->count())->toBe(0);
});

it('keeps the generated tasks when a recurring template is deleted', function (): void {
    $template = app(SaveSignalRecurring::class)->handle($this->admin, null, 'Example daily', SignalCategory::Other, [0]);
    app(MaterializeSignalRecurring::class)->handle($this->admin, SIGNAL_TODAY);

    app(DeleteSignalRecurring::class)->handle($this->admin, $template->id);

    $task = SignalTask::query()->firstOrFail();

    expect(SignalRecurringTask::query()->count())->toBe(0)
        ->and($task->title)->toBe('Example daily')
        ->and($task->recurring_id)->toBeNull();
});

it('needs a plannable colour and at least one weekday for a template', function (): void {
    $save = app(SaveSignalRecurring::class);

    expect(fn () => $save->handle($this->admin, null, 'Example', SignalCategory::Main, []))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.weekdays_required'))
        ->and(fn () => $save->handle($this->admin, null, 'Example', SignalCategory::Main, [9]))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.weekdays_required'))
        ->and(fn () => $save->handle($this->admin, null, 'Example', SignalCategory::Extra, [0]))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.category_required'))
        ->and(fn () => $save->handle($this->admin, null, 'Example', null, [0]))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.category_required'));

    $template = $save->handle($this->admin, null, 'Example', SignalCategory::Main, [0, 4]);
    $updated = $save->handle($this->admin, $template->id, 'Example renamed', SignalCategory::Medium, [1]);

    expect($updated->is($template))->toBeTrue()
        ->and($updated->weekdays)->toBe([1])
        ->and($updated->category)->toBe(SignalCategory::Medium)
        ->and(SignalRecurringTask::query()->count())->toBe(1);
});

it('freezes the planned deep-work blocks of a day at the first write and clamps what is completed', function (): void {
    $deep = app(SetSignalDeepWork::class);

    // Monday: the default plan is three blocks.
    expect($deep->handle($this->admin, SIGNAL_TODAY, 2)->completed)->toBe(2);

    app(SaveSignalSettings::class)->handle($this->admin, 5, 1);

    $row = $deep->handle($this->admin, SIGNAL_TODAY, 9);

    expect($row->planned)->toBe(3)
        ->and($row->completed)->toBe(3)
        ->and($deep->handle($this->admin, SIGNAL_TODAY, -4)->completed)->toBe(0)
        ->and(SignalDeepWorkDay::query()->count())->toBe(1);

    // A day written after the change follows the new settings.
    expect($deep->handle($this->admin, SIGNAL_YESTERDAY, 1)->planned)->toBe(1); // Sunday, weekend
});

it('allows deep-work blocks on a locked past day but not on a future one', function (): void {
    expect(app(SetSignalDeepWork::class)->handle($this->admin, SIGNAL_YESTERDAY, 0)->planned)->toBe(0)
        ->and(fn () => app(SetSignalDeepWork::class)->handle($this->admin, SIGNAL_TOMORROW, 1))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.future_day'));
});

it('accepts 0 to 12 deep-work blocks in the settings and refuses the rest', function (): void {
    $save = app(SaveSignalSettings::class);

    expect($save->handle($this->admin, 12, 0)->deep_work_weekday_blocks)->toBe(12)
        ->and(fn () => $save->handle($this->admin, 13, 0))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.blocks_range', ['max' => 12]))
        ->and(fn () => $save->handle($this->admin, 3, -1))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.blocks_range', ['max' => 12]));

    // One row per user, updated in place.
    $save->handle($this->admin, 4, 2);

    expect(\App\Domain\Signal\Models\SignalSetting::query()->count())->toBe(1);
});

it('holds at most three goals a week and fills the lowest free position', function (): void {
    $add = app(AddSignalGoal::class);
    $week = '2026-10-12';

    $one = $add->handle($this->admin, $week, 'Example one');
    $two = $add->handle($this->admin, $week, 'Example two');
    $add->handle($this->admin, $week, 'Example three');

    expect(fn () => $add->handle($this->admin, $week, 'Example four'))->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.goal_limit', ['max' => 3]));

    $two->delete();

    $again = $add->handle($this->admin, $week, 'Example again');

    expect($one->position)->toBe(1)
        ->and($again->position)->toBe(2)
        ->and(SignalWeeklyGoal::query()->where('week_start', $week)->count())->toBe(3);
});

it('accepts a week only by its Monday', function (): void {
    expect(fn () => app(AddSignalGoal::class)->handle($this->admin, '2026-10-14', 'Example'))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.invalid_week'))
        ->and(fn () => app(SaveSignalRecap::class)->handle($this->admin, '2026-10-14', 'a', 'b'))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.invalid_week'));
});

it('reorders the goals of a week and renumbers them to 1..n', function (): void {
    $add = app(AddSignalGoal::class);
    $week = '2026-10-12';
    $a = $add->handle($this->admin, $week, 'Example a');
    $b = $add->handle($this->admin, $week, 'Example b');
    $c = $add->handle($this->admin, $week, 'Example c');
    $b->delete(); // leaves a gap at position 2

    app(ReorderSignalGoals::class)->handle($this->admin, $week, [$c->id, $a->id]);

    expect(SignalWeeklyGoal::query()->where('week_start', $week)->orderBy('position')->get(['title', 'position'])->map(fn ($g) => [$g->title, $g->position])->all())
        ->toBe([['Example c', 1], ['Example a', 2]]);

    expect(fn () => app(ReorderSignalGoals::class)->handle($this->admin, $week, [$a->id]))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.order_stale'));
});

it('saves one recap per week and overwrites it on the next save', function (): void {
    $save = app(SaveSignalRecap::class);

    $save->handle($this->admin, '2026-10-12', '  Example went well ', 'Example change');
    $save->handle($this->admin, '2026-10-12', 'Example went better', '');
    $save->handle($this->admin, '2026-10-05', 'Example earlier', 'Example earlier change');

    $recap = SignalWeeklyRecap::query()->where('week_start', '2026-10-12')->firstOrFail();

    expect(SignalWeeklyRecap::query()->count())->toBe(2)
        ->and($recap->what_went_well)->toBe('Example went better')
        ->and($recap->what_to_change)->toBe('');
});

it('refuses a Partner every write of the planner', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $this->actingAs($partner);

    expect(fn () => app(AddSignalTask::class)->handle($partner, 'Example', SIGNAL_TOMORROW, SignalCategory::Main))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AddSignalGoal::class)->handle($partner, '2026-10-12', 'Example'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveSignalSettings::class)->handle($partner, 3, 0))->toThrow(AuthorizationException::class);
});

it('refuses an actor who is not the signed-in user', function (): void {
    $other = Canary::admin();

    expect(fn () => app(AddSignalTask::class)->handle($other, 'Example', SIGNAL_TOMORROW, SignalCategory::Main))->toThrow(AuthorizationException::class)
        ->and(SignalTask::query()->count())->toBe(0);
});
