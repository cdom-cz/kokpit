<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\Queries\TimeReportReader;
use App\Domain\Reports\ReportEntry;
use App\Domain\Reports\ReportFilters;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Billing\TaskBillingResolver;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Billing\TimeEntryRateResolver;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

/*
 * The report prices every kind of entry exactly as the rest of Kokpit does (T-08-34): the effective billing type
 * and hourly rate that TimeReportReader resolves in one SQL equal what TaskBillingResolver and
 * TimeEntryRateResolver, the specification, return for the same entry. Every name and amount is fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague; every entry below lies on that day.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function parityRun(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A client in the currency with a client rate.
 */
function parityClient(string $rate = '800', string $currency = 'CZK'): Client
{
    return parityRun(static fn (): Client => Client::factory()->create([
        'currency' => $currency,
        'hourly_rate' => Money::fromMajor($rate, $currency),
    ]));
}

/**
 * A project written through the domain Action, so its billing row exists.
 *
 * @param  array<string, mixed>  $data
 */
function parityProject(Client $client, array $data = []): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example parity project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
        ...$data,
    ]);
}

/**
 * A task or subtask; the billing keys go through UpdateTask like on the edit page.
 *
 * @param  array<string, mixed>  $billing
 */
function parityTask(User $admin, Project $project, array $billing = [], ?Task $parent = null): Task
{
    $task = app(CreateTask::class)->handle($admin, $project, ['title' => 'Example parity task'], $parent);

    if ($billing !== []) {
        $task = app(UpdateTask::class)->handle($admin, $task, $billing);
    }

    return $task->refresh();
}

/**
 * A finished entry of the day on the task, the project or the client alone.
 */
function parityEntry(User $admin, Client $client, ?Project $project = null, ?Task $task = null): TimeEntry
{
    $factory = match (true) {
        $task !== null => TimeEntry::factory()->forTask($task),
        $project !== null => TimeEntry::factory()->forProject($project),
        default => TimeEntry::factory()->state(['client_id' => $client->id]),
    };

    return parityRun(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => $admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC'),
    ])->id));
}

/**
 * The effective billing type the resolvers give the entry: the task resolver for a task entry, the type of the
 * project billing row for a project-only entry, hourly for an entry of the client alone.
 */
function parityExpectedType(TimeEntry $entry): string
{
    if ($entry->task_id !== null) {
        $task = parityRun(static fn (): Task => Task::query()->withTrashed()->findOrFail($entry->task_id));

        return app(TaskBillingResolver::class)->resolve($task)->type;
    }

    if ($entry->project_id !== null) {
        return (string) parityRun(static fn (): mixed => DB::table('project_billing')->where('project_id', $entry->project_id)->value('billing_type'));
    }

    return 'hourly';
}

/**
 * The report row of the entry as the reader gives it.
 */
function parityReportRow(TimeEntry $entry): ReportEntry
{
    $filters = ReportFilters::period('2026-10-14', '2026-10-14');
    $row = app(TimeReportReader::class)->entries($filters, TimerClock::now())
        ->first(static fn (ReportEntry $candidate): bool => $candidate->id === $entry->id);

    expect($row)->toBeInstanceOf(ReportEntry::class);
    assert($row instanceof ReportEntry);

    return $row;
}

/**
 * Whether the reader and the resolvers agree on the entry: the type, the minor units and the currency of the rate.
 */
function parityAgrees(TimeEntry $entry, ?int $expectedMinor, string $expectedType): void
{
    app()->forgetScopedInstances();

    $resolved = app(TimeEntryRateResolver::class)->resolve($entry);
    $row = parityReportRow($entry);

    expect($row->billingType)->toBe($expectedType)
        ->and($row->billingType)->toBe(parityExpectedType($entry))
        ->and($row->rate?->jsonSerialize())->toBe($resolved->rate?->jsonSerialize())
        ->and($row->rate?->minor)->toBe($expectedMinor);
}

describe('the rate level', function (): void {
    it('uses the own rate of the task', function (): void {
        $client = parityClient();
        $task = parityTask($this->admin, parityProject($client), ['hourly_rate' => '1000']);

        parityAgrees(parityEntry($this->admin, $client, task: $task), 100000, 'hourly');
    });

    it('uses the rate of the parent task for a subtask without its own', function (): void {
        $client = parityClient();
        $project = parityProject($client);
        $parent = parityTask($this->admin, $project, ['hourly_rate' => '1100']);
        $subtask = parityTask($this->admin, $project, [], $parent);

        parityAgrees(parityEntry($this->admin, $client, task: $subtask), 110000, 'hourly');
    });

    it('prefers the own rate of a subtask to the one of its parent', function (): void {
        $client = parityClient();
        $project = parityProject($client);
        $parent = parityTask($this->admin, $project, ['hourly_rate' => '1100']);
        $subtask = parityTask($this->admin, $project, ['hourly_rate' => '1300'], $parent);

        parityAgrees(parityEntry($this->admin, $client, task: $subtask), 130000, 'hourly');
    });

    it('uses the rate of the project for a task without billing', function (): void {
        $client = parityClient();
        $task = parityTask($this->admin, parityProject($client));

        parityAgrees(parityEntry($this->admin, $client, task: $task), 90000, 'hourly');
    });

    it('uses the rate of the client when the project has none', function (): void {
        $client = parityClient('800');
        $task = parityTask($this->admin, parityProject($client, ['hourly_rate' => null]));

        parityAgrees(parityEntry($this->admin, $client, task: $task), 80000, 'hourly');
    });

    it('treats a rate of zero at the task level as a value', function (): void {
        $client = parityClient();
        $task = parityTask($this->admin, parityProject($client), ['hourly_rate' => '0']);

        parityAgrees(parityEntry($this->admin, $client, task: $task), 0, 'hourly');
    });

    it('takes the rate and its currency from the same level', function (): void {
        $client = parityClient('80', 'EUR');
        $project = parityProject($client, ['hourly_rate' => '95']);
        $task = parityTask($this->admin, $project, ['hourly_rate' => '110']);
        $plain = parityTask($this->admin, $project);

        $own = parityEntry($this->admin, $client, task: $task);
        $inherited = parityEntry($this->admin, $client, task: $plain);

        parityAgrees($own, 11000, 'hourly');
        parityAgrees($inherited, 9500, 'hourly');
        expect(parityReportRow($own)->rate?->currency)->toBe('EUR')
            ->and(parityReportRow($inherited)->rate?->currency)->toBe('EUR');
    });
});

describe('the billing type level', function (): void {
    it('keeps a task of type fixed price fixed inside an hourly project', function (): void {
        $client = parityClient();
        $task = parityTask($this->admin, parityProject($client), ['billing_type' => 'fixed_price', 'fixed_price' => '1200']);

        parityAgrees(parityEntry($this->admin, $client, task: $task), 90000, 'fixed_price');
    });

    it('lets a subtask of type inherit take the fixed price of its parent', function (): void {
        $client = parityClient();
        $project = parityProject($client);
        $parent = parityTask($this->admin, $project, ['billing_type' => 'fixed_price', 'fixed_price' => '1200']);
        $subtask = parityTask($this->admin, $project, ['billing_type' => 'inherit'], $parent);

        parityAgrees(parityEntry($this->admin, $client, task: $subtask), 90000, 'fixed_price');
    });

    it('lets a task of a fixed-price project inherit the fixed price', function (): void {
        $client = parityClient('800');
        $project = parityProject($client, ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $task = parityTask($this->admin, $project);

        parityAgrees(parityEntry($this->admin, $client, task: $task), 80000, 'fixed_price');
    });

    it('reads a task of type non billable as non billable', function (): void {
        $client = parityClient();
        $task = parityTask($this->admin, parityProject($client), ['billing_type' => 'non_billable']);

        parityAgrees(parityEntry($this->admin, $client, task: $task), 90000, 'non_billable');
    });

    it('lets a task of type hourly stay hourly inside a fixed-price project', function (): void {
        $client = parityClient('800');
        $project = parityProject($client, ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $task = parityTask($this->admin, $project, ['billing_type' => 'hourly', 'hourly_rate' => '1000']);

        parityAgrees(parityEntry($this->admin, $client, task: $task), 100000, 'hourly');
    });
});

describe('the shape of the entry', function (): void {
    it('prices a project-only entry with the project rate', function (): void {
        $client = parityClient();
        $project = parityProject($client);

        parityAgrees(parityEntry($this->admin, $client, project: $project), 90000, 'hourly');
    });

    it('reads a project-only entry of a fixed-price project as fixed price', function (): void {
        $client = parityClient('800');
        $project = parityProject($client, ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);

        parityAgrees(parityEntry($this->admin, $client, project: $project), 80000, 'fixed_price');
    });

    it('prices a client-only entry with the client rate and reads it as hourly', function (): void {
        $client = parityClient('800');

        parityAgrees(parityEntry($this->admin, $client), 80000, 'hourly');
    });

    it('reads an archived task and an archived project like live ones', function (): void {
        $client = parityClient();
        $project = parityProject($client, ['hourly_rate' => '950']);
        $task = parityTask($this->admin, $project, ['hourly_rate' => '1050']);
        $inherited = parityTask($this->admin, $project);

        $own = parityEntry($this->admin, $client, task: $task);
        $viaProject = parityEntry($this->admin, $client, task: $inherited);
        $projectOnly = parityEntry($this->admin, $client, project: $project);

        parityRun(static function () use ($task, $inherited, $project): void {
            $task->delete();
            $inherited->delete();
            $project->delete();
        });

        parityAgrees($own, 105000, 'hourly');
        parityAgrees($viaProject, 95000, 'hourly');
        parityAgrees($projectOnly, 95000, 'hourly');
    });

    it('reads the entries of other users and archived clients as well', function (): void {
        $client = parityClient();
        $other = User::factory()->create();
        $entry = parityEntry($other, $client);
        parityRun(static fn () => $client->delete());

        expect(parityReportRow($entry)->userId)->toBe($other->id);
    });
});

describe('who may read the entries', function (): void {
    it('refuses a Partner', function (): void {
        [$clientId] = Canary::twoClients();
        $this->actingAs(Canary::partnerFor($clientId));
        app()->forgetScopedInstances();

        $filters = ReportFilters::period('2026-10-14', '2026-10-14');
        $reader = app(TimeReportReader::class);

        expect(fn () => $reader->entries($filters, TimerClock::now()))->toThrow(AuthorizationException::class)
            ->and(fn () => $reader->rateCurrencies($filters))->toThrow(AuthorizationException::class);
    });

    it('refuses a guest', function (): void {
        $filters = ReportFilters::period('2026-10-14', '2026-10-14');
        $this->app['auth']->forgetGuards();
        app()->forgetScopedInstances();

        expect(fn () => app(TimeReportReader::class)->entries($filters, TimerClock::now()))->toThrow(AuthorizationException::class);
    });

    it('serves a system run', function (): void {
        $client = parityClient();
        parityEntry($this->admin, $client);
        $filters = ReportFilters::period('2026-10-14', '2026-10-14');
        $this->app['auth']->forgetGuards();
        app()->forgetScopedInstances();

        $count = parityRun(static fn (): int => app(TimeReportReader::class)->entries($filters, TimerClock::now())->count());

        expect($count)->toBe(1);
    });
});
