<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
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
