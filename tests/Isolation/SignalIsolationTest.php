<?php

declare(strict_types=1);

use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Signal\Actions\AddSignalTask;
use App\Domain\Signal\Actions\DeleteSignalRecurring;
use App\Domain\Signal\Actions\ToggleSignalGoal;
use App\Domain\Signal\Actions\ToggleSignalTaskDone;
use App\Domain\Signal\Actions\UpdateSignalTask;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Models\SignalDayOverride;
use App\Domain\Signal\Models\SignalDeepWorkDay;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalSetting;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use App\Livewire\Signal\SignalDay;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Every Admin has the own planner (D: "each admin gets their own Signal records"), and a Partner has
 * none. The proof runs at the data layer (the owner scope), in the Actions and in the component.
 * Every title is fictional.
 */

/**
 * One row of every planner table for the given user, written the way a system run would.
 *
 * @return array{task: SignalTask, template: SignalRecurringTask, goal: SignalWeeklyGoal}
 */
function signalIsolationRows(string $userId, string $label): array
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($userId, $label): array {
        $own = static fn (object $model): object => $model->forceFill(['user_id' => $userId]);

        $own(new SignalSetting(['deep_work_weekday_blocks' => 4, 'deep_work_weekend_blocks' => 1]))->save();
        $own(new SignalDeepWorkDay(['for_date' => '2026-10-12', 'planned' => 3, 'completed' => 1]))->save();
        $own(new SignalDayOverride(['for_date' => '2026-10-11']))->save();
        $own(new SignalWeeklyRecap(['week_start' => '2026-10-12', 'what_went_well' => $label, 'what_to_change' => $label]))->save();

        $task = new SignalTask(['title' => $label, 'for_date' => '2026-10-13', 'category' => SignalCategory::Main, 'position' => 0]);
        $template = new SignalRecurringTask(['title' => $label, 'category' => SignalCategory::Main, 'weekdays' => [0], 'active' => true]);
        $goal = new SignalWeeklyGoal(['week_start' => '2026-10-12', 'title' => $label, 'position' => 1]);

        foreach ([$task, $template, $goal] as $model) {
            $own($model)->save();
        }

        return ['task' => $task, 'template' => $template, 'goal' => $goal];
    });
}

/**
 * The models of every planner table.
 *
 * @return list<class-string<Illuminate\Database\Eloquent\Model>>
 */
function signalIsolationModels(): array
{
    return [SignalTask::class, SignalRecurringTask::class, SignalSetting::class, SignalDeepWorkDay::class, SignalDayOverride::class, SignalWeeklyGoal::class, SignalWeeklyRecap::class];
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));

    $this->adminA = Canary::admin();
    $this->adminB = Canary::admin();

    $this->rowsA = signalIsolationRows($this->adminA->getKey(), Canary::canary('admin_a'));
});

it('shows every Admin only the own rows of every planner table', function (): void {
    $this->actingAs($this->adminA);

    foreach (signalIsolationModels() as $model) {
        expect($model::query()->count())->toBe(1, $model);
    }

    $this->actingAs($this->adminB);

    foreach (signalIsolationModels() as $model) {
        expect($model::query()->count())->toBe(0, $model);
    }
});

it('shows a guest nothing and a system run everything', function (): void {
    foreach (signalIsolationModels() as $model) {
        expect($model::query()->count())->toBe(0, $model);
        expect(app(PartnerContext::class)->runAsSystem(static fn (): int => $model::query()->count()))->toBe(1, $model);
    }
});

it('shows a Partner nothing of any Admin planner', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    foreach (signalIsolationModels() as $model) {
        expect($model::query()->count())->toBe(0, $model);
    }
});

it('does not find the records of another Admin in the Actions', function (): void {
    $this->actingAs($this->adminB);

    $task = $this->rowsA['task'];

    expect(fn () => app(ToggleSignalTaskDone::class)->handle($this->adminB, $task->id, true))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(UpdateSignalTask::class)->handle($this->adminB, $task->id, 'Example hijacked', SignalCategory::Main))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeleteSignalRecurring::class)->handle($this->adminB, $this->rowsA['template']->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ToggleSignalGoal::class)->handle($this->adminB, $this->rowsA['goal']->id, true))->toThrow(ModelNotFoundException::class);

    $fresh = app(PartnerContext::class)->runAsSystem(static fn (): SignalTask => SignalTask::query()->findOrFail($task->id));

    expect($fresh->is_done)->toBeFalse()->and($fresh->title)->not->toBe('Example hijacked');
});

it('keeps what an Admin adds out of the planner of the other Admin', function (): void {
    $this->actingAs($this->adminB);

    $added = app(AddSignalTask::class)->handle($this->adminB, 'Example of B', '2026-10-13', SignalCategory::Main);

    expect($added->user_id)->toBe($this->adminB->getKey());

    $this->actingAs($this->adminA);

    expect(SignalTask::query()->where('title', 'Example of B')->count())->toBe(0)
        // The three planned main slots of the day are counted per user, not across users.
        ->and(SignalTask::query()->where('for_date', '2026-10-13')->count())->toBe(1);
});

it('does not render the data of another Admin in the day component', function (): void {
    $this->actingAs($this->adminB);

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-13'])->assertDontSee($this->rowsA['task']->title);
});

it('refuses to create a row for nobody', function (): void {
    expect(fn () => (new SignalTask(['title' => 'Example orphan', 'for_date' => '2026-10-13', 'category' => SignalCategory::Main]))->save())
        ->toThrow(LogicException::class);
});
