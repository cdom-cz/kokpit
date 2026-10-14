<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Actions\EndOverrunTimers;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

/*
 * The hourly safety net ends a running entry that has run longer than
 * kokpit.time.max_running_hours (48) at its start plus that limit, never at now, so the
 * stored duration cannot overflow the integer column. Every name in this file is fictional.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Creates a running entry as a system run, started the given number of seconds before the frozen now.
 * Every entry gets its own factory user, because one user may have only one running entry.
 *
 * @param  array<string, mixed>  $state
 */
function overrunEntry(int $startedSecondsAgo, array $state = []): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'started_at' => CarbonImmutable::now()->subSeconds($startedSecondsAgo),
        'ended_at' => null,
        ...$state,
    ]));
}

/**
 * The entry as stored, read as a system run.
 */
function overrunFresh(TimeEntry $entry): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

/**
 * The `updated` activity rows of one entry, oldest first.
 *
 * @return list<array{attribute_changes: array{attributes?: array<string, mixed>, old?: array<string, mixed>}, source: string}>
 */
function overrunHistory(string $entryId): array
{
    return DB::table('activity_log')
        ->where('subject_type', 'time_entry')
        ->where('subject_id', $entryId)
        ->where('event', 'updated')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): array => [
            'attribute_changes' => json_decode((string) $row->attribute_changes, true) ?: [],
            'source' => (string) $row->source,
        ])
        ->all();
}

it('ends a running entry of 49 hours at its start plus 48 hours and leaves one console trace', function (): void {
    $word = Canary::canary('description');
    $client = Client::factory()->create(['name' => 'Cihla']);
    $entry = overrunEntry(49 * 3600, ['client_id' => $client->id, 'description' => $word]);

    expect(Artisan::call('kokpit:time:end-overrun'))->toBe(0);

    $stored = overrunFresh($entry);
    $expectedEnd = $entry->started_at->addHours(48);

    expect($stored->isRunning())->toBeFalse()
        ->and($stored->ended_at?->equalTo($expectedEnd))->toBeTrue()
        ->and($stored->duration_seconds)->toBe(172800);

    $history = overrunHistory($entry->id);

    expect($history)->toHaveCount(1)
        ->and($history[0]['source'])->toBe('console')
        ->and($history[0]['attribute_changes']['old'])->toHaveKey('ended_at')
        ->and($history[0]['attribute_changes']['old']['ended_at'])->toBeNull()
        ->and(CarbonImmutable::parse($history[0]['attribute_changes']['attributes']['ended_at'], 'UTC')->equalTo($expectedEnd))->toBeTrue();

    $output = Artisan::output();

    expect($output)->toContain(__('kokpit.overrun_timers.done', ['hours' => 48, 'count' => 1]))
        ->and($output)->not->toContain($word)
        ->and($output)->not->toContain('Cihla');
});

/**
 * The scheduled events that run the overrun command.
 *
 * @return list<Event>
 */
function overrunScheduleEvents(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'kokpit:time:end-overrun'),
    ));
}

it('leaves entries of 47 hours and of exactly 48 hours running', function (): void {
    $under = overrunEntry(47 * 3600);
    $exact = overrunEntry(48 * 3600);

    expect(Artisan::call('kokpit:time:end-overrun'))->toBe(0)
        ->and(Artisan::output())->toContain(__('kokpit.overrun_timers.done', ['hours' => 48, 'count' => 0]));

    foreach ([$under, $exact] as $entry) {
        $stored = overrunFresh($entry);

        expect($stored->isRunning())->toBeTrue()
            ->and($stored->duration_seconds)->toBeNull()
            ->and(overrunHistory($entry->id))->toBe([]);
    }
});

it('ends decades-old entries at their start plus 48 hours without an overflow', function (): void {
    $seventyYears = overrunEntry(0, ['started_at' => CarbonImmutable::now()->subYears(70)]);
    $yearOne = overrunEntry(0, ['started_at' => CarbonImmutable::parse('0001-01-01 00:00:00', 'UTC')]);

    expect(Artisan::call('kokpit:time:end-overrun'))->toBe(0);

    foreach ([$seventyYears, $yearOne] as $entry) {
        $stored = overrunFresh($entry);

        expect($stored->isRunning())->toBeFalse()
            ->and($stored->ended_at?->equalTo($entry->started_at->addHours(48)))->toBeTrue()
            ->and($stored->duration_seconds)->toBe(172800);
    }

    expect(overrunFresh($yearOne)->ended_at?->toIso8601String())->toStartWith('0001-01-03T00:00:00');
});

it('never touches a finished entry, billed ones included', function (): void {
    $finished = overrunEntry(100 * 3600, ['ended_at' => CarbonImmutable::now()->subHours(40)]);
    $billed = app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->billed()->create([
        'started_at' => CarbonImmutable::now()->subHours(200),
        'ended_at' => CarbonImmutable::now()->subHours(190),
    ]));

    expect(Artisan::call('kokpit:time:end-overrun'))->toBe(0);

    foreach ([$finished, $billed] as $entry) {
        $stored = overrunFresh($entry);

        expect($stored->ended_at?->equalTo($entry->ended_at))->toBeTrue()
            ->and(overrunHistory($entry->id))->toBe([]);
    }

    expect(overrunFresh($billed)->duration_seconds)->toBe(10 * 3600)
        ->and(overrunFresh($finished)->duration_seconds)->toBe(60 * 3600);
});

it('does nothing on a second run and writes no new activity row', function (): void {
    $entry = overrunEntry(49 * 3600);

    expect(Artisan::call('kokpit:time:end-overrun'))->toBe(0);

    $first = overrunFresh($entry)->ended_at;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

    expect(Artisan::call('kokpit:time:end-overrun'))->toBe(0)
        ->and(Artisan::output())->toContain(__('kokpit.overrun_timers.done', ['hours' => 48, 'count' => 0]))
        ->and(overrunFresh($entry)->ended_at?->equalTo($first))->toBeTrue()
        ->and(overrunHistory($entry->id))->toHaveCount(1);
});

it('ends the entry of a deactivated owner as well', function (): void {
    $entry = overrunEntry(49 * 3600);

    app(PartnerContext::class)->runAsSystem(static function () use ($entry): void {
        $entry->user->forceFill(['deactivated_at' => CarbonImmutable::now()->subDay()])->save();
    });

    expect(Artisan::call('kokpit:time:end-overrun'))->toBe(0)
        ->and(overrunFresh($entry)->ended_at?->equalTo($entry->started_at->addHours(48)))->toBeTrue();
});

it('takes the limit from the configuration', function (): void {
    config(['kokpit.time.max_running_hours' => 2]);

    $over = overrunEntry(2 * 3600 + 60);
    $under = overrunEntry(2 * 3600 - 60);

    expect(Artisan::call('kokpit:time:end-overrun'))->toBe(0)
        ->and(Artisan::output())->toContain(__('kokpit.overrun_timers.done', ['hours' => 2, 'count' => 1]));

    expect(overrunFresh($over)->duration_seconds)->toBe(7200)
        ->and(overrunFresh($under)->isRunning())->toBeTrue();
});

it('refuses to run outside a system run, so it can never be a silent no-op', function (): void {
    $entry = overrunEntry(49 * 3600);

    expect(fn () => app(EndOverrunTimers::class)->handle())->toThrow(LogicException::class)
        ->and(overrunFresh($entry)->isRunning())->toBeTrue()
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => app(EndOverrunTimers::class)->handle()))->toBe(1);
});

it('restores the system flag after the command', function (): void {
    overrunEntry(49 * 3600);

    Artisan::call('kokpit:time:end-overrun');

    expect(app(PartnerContext::class)->isSystem())->toBeFalse();
});

it('is scheduled hourly on one server', function (): void {
    $events = overrunScheduleEvents();

    expect($events)->toHaveCount(1);

    $event = $events[0];

    expect($event->description)->toBe('kokpit-end-overrun-timers')
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->onOneServer)->toBeTrue();
});
