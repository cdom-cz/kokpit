<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Actions\CreateTimeEntry;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * Manual time entries: creation, edit and delete through the domain Actions
 * (TI-02, TI-03, TI-07, TI-08). Every value is fictional.
 */

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function entrySystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Creates a manual entry as the signed-in actor.
 *
 * @param  array<string, mixed>  $data
 */
function entryCreate(User $actor, array $data): TimeEntry
{
    test()->actingAs($actor);

    return app(CreateTimeEntry::class)->handle($actor, $data);
}

/**
 * The field errors of a call the Action must refuse with a ValidationException.
 *
 * @param  Closure(): mixed  $call
 * @return array<string, list<string>>
 */
function entryRefused(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    test()->fail('The call was accepted although it must be refused with a field error.');
}

/**
 * The number of time entries, read as a system run.
 */
function entryCount(): int
{
    return entrySystem(static fn (): int => TimeEntry::query()->count());
}

/*
 * Tracer: a finished client-only entry in exact seconds (TI-02, TI-03, TI-08).
 */

it('records a finished client-only entry in exact seconds', function (): void {
    $admin = Canary::admin();
    $client = Client::factory()->create();

    $entry = entryCreate($admin, [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->client_id)->toBe($client->id)
        ->and($entry->project_id)->toBeNull()
        ->and($entry->task_id)->toBeNull()
        ->and($entry->duration_seconds)->toBe(5400)
        ->and($entry->billable)->toBeTrue()
        ->and($entry->billing_state->value)->toBe('unbilled')
        ->and($entry->isRunning())->toBeFalse()
        ->and($entry->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00');
});

it('stores an ISO 8601 instant with an offset as the UTC instant', function (): void {
    $client = Client::factory()->create();

    $entry = entryCreate(Canary::admin(), [
        'client_id' => $client->id,
        'started_at' => '2026-10-12T10:00:00+02:00',
        'ended_at' => '2026-10-12T08:30:00Z',
    ]);

    expect($entry->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00')
        ->and($entry->ended_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:30:00')
        ->and($entry->duration_seconds)->toBe(1800);
});

it('truncates a fractional second instead of rounding it', function (string $start, string $end): void {
    $client = Client::factory()->create();

    $entry = entryCreate(Canary::admin(), ['client_id' => $client->id, 'started_at' => $start, 'ended_at' => $end]);

    expect($entry->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00')
        ->and($entry->ended_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:59')
        ->and($entry->duration_seconds)->toBe(59);
})->with([
    'wall-clock fraction' => ['2026-10-12 08:00:00.700', '2026-10-12 08:00:59.999'],
    'ISO fraction' => ['2026-10-12T08:00:00.700+00:00', '2026-10-12T08:00:59.999999Z'],
    'fraction beyond microseconds' => ['2026-10-12 08:00:00.7000009', '2026-10-12 08:00:59.9999999'],
]);

it('refuses a missing client with the field error client_id', function (): void {
    $errors = entryRefused(fn () => entryCreate(Canary::admin(), [
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]));

    expect($errors)->toHaveKey('client_id')
        ->and($errors['client_id'][0])->toBe('Vyberte klienta.')
        ->and(entryCount())->toBe(0);
});

it('refuses a missing end with the field error ended_at', function (): void {
    $client = Client::factory()->create();

    $errors = entryRefused(fn () => entryCreate(Canary::admin(), [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
    ]));

    expect($errors)->toHaveKey('ended_at')
        ->and($errors['ended_at'][0])->toBe('Zadejte konec.')
        ->and(entryCount())->toBe(0);
});
