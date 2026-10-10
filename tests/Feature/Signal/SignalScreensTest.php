<?php

declare(strict_types=1);

use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use App\Filament\Pages\Signal\SignalDashboardPage;
use App\Filament\Pages\Signal\SignalReflectionPage;
use App\Filament\Pages\Signal\SignalSettingsPage;
use App\Filament\Pages\Signal\SignalTodayPage;
use App\Filament\Pages\Signal\SignalWeekPage;
use App\Livewire\Signal\SignalDay;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The planner screens as Livewire components: every change is one request, no page load. The clock
 * is frozen on Monday 12 Oct 2026 (Prague noon); every title is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('renders the five pages for the Admin in their own menu group', function (): void {
    foreach (['/admin/signal', '/admin/signal/week', '/admin/signal/overview', '/admin/signal/reflection', '/admin/signal/settings'] as $url) {
        $this->get($url)->assertOk();
    }

    expect(SignalTodayPage::getNavigationGroup())->toBe(__('kokpit.signal.navigation_group'))
        ->and(SignalWeekPage::getNavigationGroup())->toBe(SignalSettingsPage::getNavigationGroup())
        ->and(SignalDashboardPage::getNavigationGroup())->toBe(SignalReflectionPage::getNavigationGroup());
});

it('refuses every page and the day component to a Partner, as a request and as a component', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $this->actingAs($partner);

    foreach (['/admin/signal', '/admin/signal/week', '/admin/signal/overview', '/admin/signal/reflection', '/admin/signal/settings'] as $url) {
        $this->get($url)->assertForbidden();
    }

    foreach ([SignalTodayPage::class, SignalWeekPage::class, SignalDashboardPage::class, SignalReflectionPage::class, SignalSettingsPage::class] as $page) {
        Livewire::actingAs($partner)->test($page)->assertForbidden();
    }

    Livewire::actingAs($partner)->test(SignalDay::class, ['forDate' => '2026-10-12'])->assertForbidden();
});

it('adds a task without a page load: a grey one today, a coloured one tomorrow', function (): void {
    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->set('newTitle', 'Example sudden')
        ->call('addTask')
        ->assertSet('newTitle', '')
        ->assertDispatched('signal-changed')
        ->assertSee('Example sudden');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-13'])
        ->set('newTitle', 'Example planned')
        ->set('newCategory', 'medium')
        ->call('addTask')
        ->assertSee('Example planned');

    expect(SignalTask::query()->where('title', 'Example sudden')->value('category'))->toBe(SignalCategory::Extra)
        ->and(SignalTask::query()->where('title', 'Example planned')->value('category'))->toBe(SignalCategory::Medium);
});

it('shows the rule that refused an add as a notification and keeps the typed title', function (): void {
    foreach (range(1, 3) as $i) {
        SignalTask::query()->create(['title' => "Example main {$i}", 'for_date' => '2026-10-13', 'category' => SignalCategory::Main, 'position' => $i]);
    }

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-13'])
        ->set('newTitle', 'Example fourth')
        ->set('newCategory', 'main')
        ->call('addTask')
        ->assertNotified(__('kokpit.signal.errors.limit_main', ['limit' => 3]))
        ->assertSet('newTitle', 'Example fourth');

    expect(SignalTask::query()->count())->toBe(3);
});

it('ticks, edits and deletes a task of today', function (): void {
    $task = SignalTask::query()->create(['title' => 'Example task', 'for_date' => '2026-10-12', 'category' => SignalCategory::Extra, 'position' => 0]);

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('toggleDone', $task->id, true)
        ->assertDispatched('signal-changed')
        ->call('startEdit', $task->id)
        ->assertSet('editingId', $task->id)
        ->assertSet('editTitle', 'Example task')
        ->set('editTitle', 'Example renamed')
        ->call('saveEdit')
        ->assertSet('editingId', null)
        ->assertSee('Example renamed')
        ->call('deleteTask', $task->id)
        ->assertDontSee('Example renamed');

    expect(SignalTask::query()->count())->toBe(0);
});

it('materializes the recurring templates of today when the day is shown', function (): void {
    SignalRecurringTask::query()->create(['title' => 'Example standup', 'category' => SignalCategory::Main, 'weekday_mask' => 0b1111111, 'active' => true]);

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->assertSee('Example standup');

    expect(SignalTask::query()->where('for_date', '2026-10-12')->pluck('title')->all())->toBe(['Example standup']);
});

it('shows the templates of a later day as read-only shadows without storing them', function (): void {
    SignalRecurringTask::query()->create(['title' => 'Example standup', 'category' => SignalCategory::Main, 'weekday_mask' => 0b1111111, 'active' => true]);

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-13'])
        ->assertSee('Example standup')
        ->assertSee(__('kokpit.signal.day.shadows'));

    expect(SignalTask::query()->count())->toBe(0);
});

it('moves a dragged task inside its colour group', function (): void {
    $a = SignalTask::query()->create(['title' => 'Example a', 'for_date' => '2026-10-13', 'category' => SignalCategory::Main, 'position' => 0]);
    $b = SignalTask::query()->create(['title' => 'Example b', 'for_date' => '2026-10-13', 'category' => SignalCategory::Main, 'position' => 1]);
    $c = SignalTask::query()->create(['title' => 'Example c', 'for_date' => '2026-10-13', 'category' => SignalCategory::Main, 'position' => 2]);

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-13'])
        ->call('reorder', $c->id, 0)
        ->assertSeeInOrder(['Example c', 'Example a', 'Example b']);

    expect(SignalTask::query()->orderBy('position')->pluck('title')->all())->toBe(['Example c', 'Example a', 'Example b']);

    // A task that is gone (deleted in another tab) is ignored instead of failing.
    $a->delete();
    Livewire::test(SignalDay::class, ['forDate' => '2026-10-13'])->call('reorder', $a->id, 0)->assertOk();
    expect($b->fresh())->not->toBeNull();
});

it('locks a past day behind a confirmation and unlocks it for editing', function (): void {
    $task = SignalTask::query()->create(['title' => 'Example past', 'for_date' => '2026-10-11', 'category' => SignalCategory::Main, 'position' => 0]);

    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-11'])
        ->assertSee(__('kokpit.signal.day.locked'))
        ->assertDontSee(__('kokpit.signal.day.new_placeholder'))
        ->set('newTitle', 'Example blocked')
        ->call('addTask')
        ->assertNotified(__('kokpit.signal.errors.day_locked'))
        // Ticking stays possible on a locked day.
        ->call('toggleDone', $task->id, true);

    expect($task->fresh()->is_done)->toBeTrue();

    $component->call('unlock')->assertSee(__('kokpit.signal.day.unlocked'))->assertSee(__('kokpit.signal.day.new_placeholder'));
});

it('sets the deep-work blocks and takes the last one back on a second click', function (): void {
    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('setBlocks', 2)
        ->call('setBlocks', 2);

    expect(\App\Domain\Signal\Models\SignalDeepWorkDay::query()->value('completed'))->toBe(1);
});

it('browses days on the Today page through one property', function (): void {
    Livewire::test(SignalTodayPage::class)
        ->assertSet('date', '')
        ->call('goTo', '2026-10-09')
        ->assertSet('date', '2026-10-09')
        ->assertSee(__('kokpit.signal.today.back_to_today'))
        ->call('goTo', '2026-10-12')
        ->assertSet('date', '')
        ->call('goTo', 'nonsense')
        ->assertSet('date', '');

    Livewire::withQueryParams(['date' => '2026-10-09'])->test(SignalTodayPage::class)->assertSet('date', '2026-10-09');
    Livewire::withQueryParams(['date' => '2026-02-31'])->test(SignalTodayPage::class)->assertSet('date', '');
});

it('adds, edits, ticks, reorders and deletes goals of a week and saves its recap', function (): void {
    $week = Livewire::test(SignalWeekPage::class)->assertSet('week', '2026-10-12');

    foreach (['Example one', 'Example two', 'Example three'] as $title) {
        $week->set('newGoal', $title)->call('addGoal');
    }

    $week->set('newGoal', 'Example four')->call('addGoal')->assertNotified(__('kokpit.signal.errors.goal_limit', ['max' => 3]));

    $goals = SignalWeeklyGoal::query()->orderBy('position')->get();
    $week->call('toggleGoal', $goals[0]->id, true)
        ->call('startGoalEdit', $goals[1]->id)
        ->set('editGoalTitle', 'Example two renamed')
        ->call('saveGoalEdit')
        ->call('reorderGoals', $goals[2]->id, 0)
        ->assertSeeInOrder(['Example three', 'Example one', 'Example two renamed'])
        ->call('deleteGoal', $goals[0]->id);

    $week->set('wentWell', 'Example went well')->set('toChange', 'Example change')->call('saveRecap')->assertNotified(__('kokpit.signal.week.recap_saved'));

    expect(SignalWeeklyGoal::query()->count())->toBe(2)
        ->and(SignalWeeklyRecap::query()->value('what_went_well'))->toBe('Example went well');
});

it('moves between weeks and loads the recap of the week shown', function (): void {
    SignalWeeklyRecap::query()->create(['week_start' => '2026-10-05', 'what_went_well' => 'Example earlier', 'what_to_change' => '']);

    Livewire::test(SignalWeekPage::class)
        ->call('goToWeek', '2026-10-08')
        ->assertSet('week', '2026-10-05')
        ->assertSet('wentWell', 'Example earlier')
        ->call('goToWeek', '2026-10-14')
        ->assertSet('week', '2026-10-12')
        ->assertSet('wentWell', '')
        ->call('goToWeek', 'nonsense')
        ->assertSet('week', '2026-10-12');
});

it('shows the recaps of a range and turns a reversed range around', function (): void {
    SignalWeeklyRecap::query()->create(['week_start' => '2026-09-28', 'what_went_well' => 'Example in range', 'what_to_change' => 'Example change']);
    SignalWeeklyRecap::query()->create(['week_start' => '2025-01-06', 'what_went_well' => 'Example too old', 'what_to_change' => '']);

    Livewire::test(SignalReflectionPage::class)->assertSee('Example in range')->assertDontSee('Example too old');

    Livewire::test(SignalReflectionPage::class)
        ->set('from', '2026-12-31')
        ->set('to', '2025-01-01')
        ->assertSee('Example in range')
        ->assertSee('Example too old');
});

it('saves the settings and manages the recurring templates', function (): void {
    $page = Livewire::test(SignalSettingsPage::class)
        ->assertSet('weekdayBlocks', 3)
        ->set('weekdayBlocks', 4)
        ->set('weekendBlocks', 1)
        ->call('saveBlocks')
        ->assertNotified(__('kokpit.signal.settings.saved'));

    $page->set('templateTitle', 'Example standup')->set('templateCategory', 'main')->set('templateWeekdays', ['0', '2'])->call('saveTemplate')
        ->assertSet('templateTitle', '');

    $template = SignalRecurringTask::query()->firstOrFail();

    expect($template->weekdays())->toBe([0, 2]);

    $page->call('editTemplate', $template->id)
        ->assertSet('templateTitle', 'Example standup')
        ->set('templateTitle', 'Example standup renamed')
        ->call('saveTemplate')
        ->call('toggleTemplate', $template->id, false);

    expect($template->fresh()->title)->toBe('Example standup renamed')
        ->and($template->fresh()->active)->toBeFalse();

    $page->set('templateTitle', 'Example without days')->call('saveTemplate')->assertNotified(__('kokpit.signal.errors.weekdays_required'));
    $page->call('deleteTemplate', $template->id);

    expect(SignalRecurringTask::query()->count())->toBe(0);
});

it('shows the overview figures and refreshes them when the planner changes', function (): void {
    SignalTask::query()->create(['title' => 'Example main', 'for_date' => '2026-10-12', 'category' => SignalCategory::Main, 'is_done' => true, 'completed_at' => CarbonImmutable::now(), 'position' => 0]);
    SignalWeeklyGoal::query()->create(['week_start' => '2026-10-12', 'title' => 'Example goal', 'position' => 1]);

    Livewire::test(SignalDashboardPage::class)
        ->assertSee('Example goal')
        ->assertSee(trans_choice('kokpit.signal.dashboard.streak_days', 1, ['count' => 1]))
        ->dispatch('signal-changed')
        ->assertOk();
});
