<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Billing\EntryRate;
use App\Domain\TimeTracking\Billing\RateSource;
use App\Domain\TimeTracking\Billing\TimeEntryRateResolver;
use App\Domain\TimeTracking\Models\TimeEntry;
use Database\Factories\ProjectFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Canary;

/*
 * The effective hourly rate of a time entry with its source (TI-08): task,
 * parent task, project, client, global default. Every name and amount is fictional.
 */

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function rateSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A client with a fixed rate in Czech crowns.
 */
function rateClient(string $rate = '800'): Client
{
    return rateSystem(static fn (): Client => Client::factory()->create([
        'currency' => 'CZK',
        'hourly_rate' => Money::fromMajor($rate, 'CZK'),
    ]));
}

/**
 * A project written through the domain Action, so its billing row exists.
 *
 * @param  array<string, mixed>  $data
 */
function rateProject(Client $client, array $data = []): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example rate project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
        ...$data,
    ]);
}

/**
 * A task or subtask of the project; the billing keys go through UpdateTask like on the edit page.
 *
 * @param  array<string, mixed>  $billing
 */
function rateTask(User $admin, Project $project, array $billing = [], ?Task $parent = null): Task
{
    $task = app(CreateTask::class)->handle($admin, $project, ['title' => 'Example rate task'], $parent);

    if ($billing !== []) {
        $task = app(UpdateTask::class)->handle($admin, $task, $billing);
    }

    return $task->refresh();
}

/**
 * A finished entry of the context, read back fresh so no relation cache leaks in.
 */
function rateEntry(User $admin, Client $client, ?Project $project = null, ?Task $task = null): TimeEntry
{
    $factory = TimeEntry::factory();

    $factory = match (true) {
        $task !== null => $factory->forTask($task),
        $project !== null => $factory->forProject($project),
        default => $factory->state(['client_id' => $client->id]),
    };

    return rateSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create(['user_id' => $admin->id])->id));
}

/**
 * Resolves as the signed-in Admin, through a resolver built after the test set its arrangement.
 */
function rateResolve(TimeEntry $entry): EntryRate
{
    app()->forgetScopedInstances();

    return app(TimeEntryRateResolver::class)->resolve($entry);
}

/**
 * Sets the global default rate and its currency.
 */
function rateDefault(string $currency, string $major): void
{
    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = $currency;
    $defaults->default_hourly_rate = Money::fromMajor($major, $currency);
    $defaults->save();
}

/**
 * Whether the rate is exactly the given minor amount in crowns, with the source.
 */
function rateIs(EntryRate $rate, int $minor, RateSource $source): bool
{
    return $rate->rate?->equals(Money::ofMinor($minor, 'CZK')) === true && $rate->source === $source;
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

describe('task entries', function (): void {
    it('uses the own rate of the task', function (): void {
        $client = rateClient();
        $task = rateTask($this->admin, rateProject($client), ['hourly_rate' => '1000']);

        $rate = rateResolve(rateEntry($this->admin, $client, task: $task));

        expect(rateIs($rate, 100000, RateSource::Task))->toBeTrue();
    });

    it('uses the rate of the parent task for a subtask without its own', function (): void {
        $client = rateClient();
        $project = rateProject($client);
        $parent = rateTask($this->admin, $project, ['hourly_rate' => '950']);
        $subtask = rateTask($this->admin, $project, [], $parent);

        $rate = rateResolve(rateEntry($this->admin, $client, task: $subtask));

        expect(rateIs($rate, 95000, RateSource::ParentTask))->toBeTrue();
    });

    it('uses the rate of the project for a task without overrides', function (): void {
        $client = rateClient();
        $task = rateTask($this->admin, rateProject($client));

        $rate = rateResolve(rateEntry($this->admin, $client, task: $task));

        expect(rateIs($rate, 90000, RateSource::Project))->toBeTrue();
    });

    it('uses the rate of the client when the project holds none', function (): void {
        $client = rateClient();
        $project = rateProject($client, ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $task = rateTask($this->admin, $project);

        $rate = rateResolve(rateEntry($this->admin, $client, task: $task));

        expect(rateIs($rate, 80000, RateSource::Client))->toBeTrue();
    });
});

describe('project and client entries', function (): void {
    it('uses the rate of the project for a project-only entry', function (): void {
        $client = rateClient();
        $project = rateProject($client);

        $rate = rateResolve(rateEntry($this->admin, $client, project: $project));

        expect(rateIs($rate, 90000, RateSource::Project))->toBeTrue();
    });

    it('uses the rate of the client for a project-only entry whose project holds none', function (): void {
        $client = rateClient();
        $project = rateProject($client, ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);

        $rate = rateResolve(rateEntry($this->admin, $client, project: $project));

        expect(rateIs($rate, 80000, RateSource::Client))->toBeTrue();
    });

    it('keeps a project rate of zero as a value instead of deferring to the client', function (): void {
        $client = rateClient();
        $project = rateProject($client, ['hourly_rate' => '0']);

        $rate = rateResolve(rateEntry($this->admin, $client, project: $project));

        expect($rate->rate?->isZero())->toBeTrue()
            ->and($rate->source)->toBe(RateSource::Project);
    });

    it('uses the rate of the client for a client-only entry', function (): void {
        $client = rateClient();

        $rate = rateResolve(rateEntry($this->admin, $client));

        expect(rateIs($rate, 80000, RateSource::Client))->toBeTrue();
    });
});

describe('global default', function (): void {
    /**
     * An entry whose client is built in memory and holds no rate.
     */
    function rateRateless(string $currency): TimeEntry
    {
        $entry = new TimeEntry;
        $entry->setRelation('client', new Client(['currency' => $currency]));
        $entry->setRelation('project', null);
        $entry->setRelation('task', null);

        return $entry;
    }

    it('applies when no level holds a rate and the currency is the one of the client', function (): void {
        rateDefault('CZK', '650');

        $rate = rateResolve(rateRateless('CZK'));

        expect(rateIs($rate, 65000, RateSource::Default))->toBeTrue();
    });

    it('stays empty when the default is in another currency than the client', function (): void {
        rateDefault('EUR', '40');

        $rate = rateResolve(rateRateless('CZK'));

        expect($rate->rate)->toBeNull()
            ->and($rate->source)->toBeNull();
    });

    it('never overrides a rate that a level holds', function (): void {
        rateDefault('CZK', '650');
        $client = rateClient();

        $rate = rateResolve(rateEntry($this->admin, $client));

        expect(rateIs($rate, 80000, RateSource::Client))->toBeTrue();
    });
});

it('still resolves when the task, the project and the client were archived', function (): void {
    $client = rateClient();
    $project = rateProject($client);
    $parent = rateTask($this->admin, $project, ['hourly_rate' => '950']);
    $subtask = rateTask($this->admin, $project, [], $parent);
    $entry = rateEntry($this->admin, $client, task: $subtask);

    rateSystem(static function () use ($subtask, $parent, $project, $client): void {
        $subtask->delete();
        $parent->delete();
        $project->delete();
        $client->delete();
    });

    $fresh = rateSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));

    expect(rateIs(rateResolve($fresh), 95000, RateSource::ParentTask))->toBeTrue();

    $clientOnly = rateEntry($this->admin, rateClient());
    rateSystem(static fn () => $clientOnly->client()->first()?->delete());
    $freshClientOnly = rateSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($clientOnly->id));

    expect(rateIs(rateResolve($freshClientOnly), 80000, RateSource::Client))->toBeTrue();
});

describe('who may ask', function (): void {
    it('refuses a Partner', function (): void {
        $client = rateClient();
        $entry = rateEntry($this->admin, $client);
        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(fn () => rateResolve($entry))->toThrow(AuthorizationException::class);
    });

    it('refuses a guest', function (): void {
        $entry = rateEntry($this->admin, rateClient());
        auth()->logout();

        expect(fn () => rateResolve($entry))->toThrow(AuthorizationException::class);
    });

    it('refuses a user without a role', function (): void {
        $entry = rateEntry($this->admin, rateClient());
        $this->actingAs(Canary::userWithoutRole(null));

        expect(fn () => rateResolve($entry))->toThrow(AuthorizationException::class);
    });

    it('allows a system run', function (): void {
        $entry = rateEntry($this->admin, rateClient());
        auth()->logout();

        // No scoped-instance reset here: it would drop the system flag the run just set.
        $rate = rateSystem(static fn (): EntryRate => app(TimeEntryRateResolver::class)->resolve($entry));

        expect(rateIs($rate, 80000, RateSource::Client))->toBeTrue();
    });
});

it('labels every rate source in Czech', function (): void {
    expect(array_map(static fn (RateSource $source): string => $source->getLabel(), RateSource::cases()))
        ->toBe(['úkol', 'nadřazený úkol', 'projekt', 'klient', 'výchozí nastavení']);
});
