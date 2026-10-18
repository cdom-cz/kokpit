<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Actions\CancelEntriesBilling;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

/*
 * The rate and the amount an entry was billed at (TI-08, research Open Question 9). Every name and amount is
 * fictional; the descriptions are canaries assembled at run time.
 */

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function snapSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A finished billable entry of the exact seconds, for the client and optionally a project.
 */
function snapEntry(User $user, Client $client, int $seconds, ?Project $project = null): TimeEntry
{
    $start = CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC');

    return snapSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'project_id' => $project?->id,
        'started_at' => $start,
        'ended_at' => $start->addSeconds($seconds),
    ])->refresh());
}

/**
 * The stored row, read again as a system run.
 */
function snapReload(TimeEntry $entry): TimeEntry
{
    return snapSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

it('keeps the rate and the amount an entry was billed at, even after the client rate changes', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = snapSystem(static fn (): Client => Client::factory()->create([
        'currency' => 'CZK',
        'hourly_rate' => Money::fromMajor('1500', 'CZK'),
    ]));
    $entry = snapEntry($admin, $client, 45305);

    app(MarkEntriesBilled::class)->handle($admin, [$entry->id]);

    $stored = snapReload($entry);

    expect($stored->billed_rate_minor)->toBe(150000)
        ->and($stored->billed_rate_currency)->toBe('CZK')
        ->and($stored->billed_amount_minor)->toBe(1887708)
        ->and($stored->billedRate()?->equals(Money::ofMinor(150000, 'CZK')))->toBeTrue()
        ->and($stored->billedAmount()?->equals(Money::ofMinor(1887708, 'CZK')))->toBeTrue()
        ->and($stored->invoice_id)->toBeNull()
        ->and($stored->isReserved())->toBeFalse();

    // The client rate changes afterwards; the snapshot of the billed entry stays.
    snapSystem(static fn (): bool => $client->forceFill(['hourly_rate' => Money::fromMajor('2000', 'CZK')])->save());

    $again = snapReload($entry);

    expect($again->billed_rate_minor)->toBe(150000)
        ->and($again->billed_amount_minor)->toBe(1887708);

    // Cancelling the billing clears the snapshot.
    app(CancelEntriesBilling::class)->handle($admin, [$entry->id]);

    $released = snapReload($entry);

    expect($released->billing_state->value)->toBe('unbilled')
        ->and($released->billed_rate_minor)->toBeNull()
        ->and($released->billed_rate_currency)->toBeNull()
        ->and($released->billed_amount_minor)->toBeNull()
        ->and($released->billedRate())->toBeNull()
        ->and($released->billedAmount())->toBeNull();
});

it('snapshots nothing for an entry of a fixed-price project', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = snapSystem(static fn (): Client => Client::factory()->create([
        'currency' => 'CZK',
        'hourly_rate' => Money::fromMajor('1500', 'CZK'),
    ]));
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Example fixed project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'fixed_price',
        'hourly_rate' => null,
        'fixed_price' => '5000',
    ]);
    $entry = snapEntry($admin, $client, 3600, $project);

    $result = app(MarkEntriesBilled::class)->handle($admin, [$entry->id]);
    $stored = snapReload($entry);

    expect($result['billed'])->toBe(1)
        ->and($stored->billing_state->value)->toBe('billed')
        ->and($stored->billed_rate_minor)->toBeNull()
        ->and($stored->billed_rate_currency)->toBeNull()
        ->and($stored->billed_amount_minor)->toBeNull();
});

it('writes one allowlisted history row per billing and never the description or the snapshot', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = snapSystem(static fn (): Client => Client::factory()->create([
        'currency' => 'CZK',
        'hourly_rate' => Money::fromMajor('1500', 'CZK'),
    ]));
    $canary = Canary::canary('description');
    $entry = snapSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => $admin->id,
        'client_id' => $client->id,
        'description' => $canary,
        'started_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'),
    ])->refresh());
    $count = static fn (): int => DB::table('activity_log')->where('subject_type', 'time_entry')->where('subject_id', $entry->id)->count();
    $before = $count();

    app(MarkEntriesBilled::class)->handle($admin, [$entry->id]);

    expect($count())->toBe($before + 1);

    $row = DB::table('activity_log')->where('subject_type', 'time_entry')->where('subject_id', $entry->id)->orderByDesc('created_at')->orderByDesc('id')->first();

    expect(json_encode($row))->not->toContain($canary)
        ->and((string) $row?->attribute_changes)->not->toContain('billed_rate')
        ->and((string) $row?->attribute_changes)->not->toContain('billed_amount');
});
