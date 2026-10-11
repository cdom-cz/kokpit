<?php

declare(strict_types=1);

use App\Domain\Signal\Actions\AddSignalGoal;
use App\Domain\Signal\Actions\AddSignalTask;
use App\Domain\Signal\Actions\SaveSignalRecap;
use App\Domain\Signal\Actions\SetSignalDayUnlocked;
use App\Domain\Signal\Actions\SetSignalDeepWork;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalTask;
use App\Filament\Pages\Signal\SignalReflectionPage;
use App\Filament\Pages\Signal\SignalTodayPage;
use App\Filament\Pages\Signal\SignalWeekPage;
use App\Livewire\Signal\SignalDay;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * What a hand-edited URL, a forged Livewire update or a stale client can send: dates PostgreSQL
 * refuses (year 0000), text where a date or an id belongs, numbers that are no numbers. None of it may
 * reach the database malformed or end in an error page; it is refused with a notification or
 * normalised to today or the current week. The clock is frozen on Monday 12 Oct 2026 (Prague noon);
 * every title is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('refuses a date or a week of year 0000 in the Actions with the translated message', function (): void {
    expect(fn () => app(AddSignalTask::class)->handle($this->admin, 'Example', '0000-01-01', SignalCategory::Main))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.invalid_date'))
        ->and(fn () => app(SetSignalDayUnlocked::class)->handle($this->admin, '0000-01-01', true))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.invalid_date'))
        ->and(fn () => app(SetSignalDeepWork::class)->handle($this->admin, '0000-01-01', 1))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.invalid_date'))
        ->and(fn () => app(SaveSignalRecap::class)->handle($this->admin, '0000-01-03', 'a', 'b'))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.invalid_week'))
        ->and(fn () => app(AddSignalGoal::class)->handle($this->admin, '0000-01-03', 'Example'))
        ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.invalid_week'))
        ->and(SignalTask::query()->count())->toBe(0);
});

it('answers a day component for the year 0000 with a 404', function (): void {
    Livewire::test(SignalDay::class, ['forDate' => '0000-01-01'])->assertNotFound();
    Livewire::test(SignalDay::class, ['forDate' => '2026-02-31'])->assertNotFound();
});

it('does not let the client rewrite the day or the heading of a mounted day component', function (): void {
    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-13', 'heading' => 'Example heading']);

    expect(fn () => $component->set('forDate', 'not-a-day'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('heading', 'Example other'))->toThrow(CannotUpdateLockedPropertyException::class);

    $component->assertSet('forDate', '2026-10-13')->assertSet('heading', 'Example heading');
});

it('normalises a nonsense or year-0000 day typed into the page of today and keeps a valid one', function (): void {
    Livewire::test(SignalTodayPage::class)
        ->set('date', 'nonsense')->assertSet('date', '')
        ->set('date', '0000-01-01')->assertSet('date', '')
        ->set('date', '2026-10-09')->assertSet('date', '2026-10-09')
        ->set('date', '2026-02-31')->assertSet('date', '');
});

it('normalises a nonsense week to the current week and a day of another week to its Monday', function (): void {
    Livewire::test(SignalWeekPage::class)
        ->set('week', 'nonsense')->assertSet('week', '2026-10-12')
        ->set('week', '0000-01-01')->assertSet('week', '2026-10-12')
        ->set('week', '2026-10-14')->assertSet('week', '2026-10-12')
        ->set('week', '2026-10-07')->assertSet('week', '2026-10-05');
});

it('blanks a nonsense or year-0000 value of the range of the reflection and keeps a valid one', function (): void {
    Livewire::test(SignalReflectionPage::class)
        ->set('from', 'nonsense')->assertSet('from', '')
        ->set('from', '0000-01-01')->assertSet('from', '')
        ->set('to', '0000-12-31')->assertSet('to', '')
        ->set('to', 'nonsense')->assertSet('to', '')
        ->set('from', '2026-09-07')->assertSet('from', '2026-09-07')
        ->set('to', '2026-10-05')->assertSet('to', '2026-10-05');

    Livewire::withQueryParams(['from' => '0000-01-01', 'to' => 'nonsense'])->test(SignalReflectionPage::class)->assertOk();
});

it('ignores an id that is no UUID when starting an edit or dropping a task', function (): void {
    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-13']);

    $component->call('startEdit', 'not-a-uuid')
        ->assertSet('editingId', null)
        ->assertNotDispatched('signal-changed');

    $component->call('reorder', 'not-a-uuid', 0)->assertDispatched('signal-changed');

    expect(SignalTask::query()->count())->toBe(0);
});
