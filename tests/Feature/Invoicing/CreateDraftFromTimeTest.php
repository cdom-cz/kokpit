<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Enums\ItemGrouping;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Queries\InvoiceableEntries;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * A draft made from unbilled time (IN-02, IN-07; D-02, D-04, D-07, D-09): the picker query, the grouping into lines
 * and the reservation of the entries, through the one Action that creates drafts. Every name, rate and instant is
 * fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function timeDraftSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A project of the client written through the domain Action, so its billing row exists.
 *
 * @param  array<string, mixed>  $data
 */
function timeDraftProject(Client $client, string $name, array $data = []): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
        ...$data,
    ]);
}

/**
 * One entry of a project, a client alone or a task, from UTC instants.
 *
 * @param  array<string, mixed>  $state
 */
function timeDraftEntry(Client $client, ?Project $project, string $start, ?string $end, array $state = []): TimeEntry
{
    $factory = $project instanceof Project ? TimeEntry::factory()->forProject($project) : TimeEntry::factory()->state(['client_id' => $client->id]);

    return timeDraftSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === null ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$state,
    ])->id));
}

/**
 * The entry as stored now.
 */
function timeDraftFresh(TimeEntry $entry): TimeEntry
{
    return timeDraftSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

/**
 * The payload of a time draft for the client over the period.
 *
 * @param  list<Project>  $projects
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function timeDraftData(Client $client, string $from, string $to, array $projects, array $extra = []): array
{
    return [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => $from,
        'period_to' => $to,
        'project_ids' => array_map(static fn (Project $project): string => $project->id, $projects),
        'without_project' => false,
        'grouping' => 'project',
        ...$extra,
    ];
}

/**
 * Creates the time draft as the signed-in Admin.
 *
 * @param  array<string, mixed>  $data
 */
function timeDraftCreate(array $data): Invoice
{
    return app(CreateDraft::class)->handle(test()->admin, $data);
}

/**
 * The stored lines of the draft in order, with their sources.
 *
 * @return list<InvoiceItem>
 */
function timeDraftLines(Invoice $draft): array
{
    return timeDraftSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $draft->id)->with('source')->orderBy('position')->get()->all());
}

/**
 * A task of the project; the billing keys go through UpdateTask like on the edit page.
 *
 * @param  array<string, mixed>  $billing
 */
function timeDraftTask(Project $project, string $title, array $billing = []): Task
{
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => $title]);

    return $billing === [] ? $task->refresh() : app(UpdateTask::class)->handle(test()->admin, $task, $billing)->refresh();
}

/**
 * An entry of the task on the given hour of 2026-09-10, `$hours` long.
 */
function timeDraftTaskEntry(Task $task, int $startHour, float $hours): TimeEntry
{
    $factory = TimeEntry::factory()->forTask($task);
    $start = CarbonImmutable::parse(sprintf('2026-09-10 %02d:00:00', $startHour), 'UTC');

    return timeDraftSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => $start,
        'ended_at' => $start->addSeconds((int) round($hours * 3600)),
    ])->id));
}

describe('the tracer: a draft from last month, grouped per project', function (): void {
    it('creates one hour line per project with the effective rate and reserves exactly the eligible entries', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $alpha = timeDraftProject($client, 'Alpha project', ['hourly_rate' => '1200']);
        $bravo = timeDraftProject($client, 'Bravo project', ['hourly_rate' => '900']);

        $alphaOne = timeDraftEntry($client, $alpha, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $alphaTwo = timeDraftEntry($client, $alpha, '2026-09-11 08:00:00', '2026-09-11 09:30:00');
        $bravoOne = timeDraftEntry($client, $bravo, '2026-09-12 08:00:00', '2026-09-12 08:45:00');

        $running = timeDraftEntry($client, $alpha, '2026-09-13 08:00:00', null);
        $nonBillable = timeDraftEntry($client, $alpha, '2026-09-14 08:00:00', '2026-09-14 09:00:00', ['billable' => false]);
        $billed = timeDraftEntry($client, $bravo, '2026-09-15 08:00:00', '2026-09-15 09:00:00', ['billing_state' => 'billed', 'billed_at' => CarbonImmutable::parse('2026-09-30 10:00:00', 'UTC')]);
        $october = timeDraftEntry($client, $alpha, '2026-10-02 08:00:00', '2026-10-02 09:00:00');
        $other = InvoiceFixtures::client();
        $foreignProject = timeDraftProject($other, 'Foreign project');
        $foreign = timeDraftEntry($other, $foreignProject, '2026-09-10 08:00:00', '2026-09-10 09:00:00');

        $draft = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$alpha, $bravo]));

        $lines = timeDraftLines($draft);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->description)->toBe('Alpha project')
            ->and($lines[0]->quantity)->toBe(InvoiceQuantity::fromSeconds(9000)->toDecimal())
            ->and($lines[0]->unit)->toBe('h')
            ->and($lines[0]->unit_price_minor)->toBe(120000)
            ->and($lines[0]->line_total_minor)->toBe(300000)
            ->and($lines[0]->price_converted)->toBeFalse()
            ->and($lines[1]->description)->toBe('Bravo project')
            ->and($lines[1]->quantity)->toBe(InvoiceQuantity::fromSeconds(2700)->toDecimal())
            ->and($lines[1]->unit_price_minor)->toBe(90000)
            ->and($lines[1]->line_total_minor)->toBe(67500);

        $source = $lines[0]->source;

        expect($source->origin)->toBe(InvoiceItemOrigin::Time)
            ->and($source->project_id)->toBe($alpha->id)
            ->and($source->task_id)->toBeNull()
            ->and($source->source_rate_minor)->toBe(120000)
            ->and($source->source_rate_currency)->toBe('CZK')
            ->and($source->source_seconds)->toBe(9000)
            ->and($source->price_manual)->toBeFalse();

        $fresh = timeDraftSystem(static fn (): Invoice => Invoice::query()->findOrFail($draft->id));

        expect($fresh->subtotal_minor)->toBe(367500)
            ->and($fresh->total_minor)->toBe(367500);

        foreach ([$alphaOne, $alphaTwo, $bravoOne] as $eligible) {
            $stored = timeDraftFresh($eligible);

            expect($stored->invoice_id)->toBe($draft->id)
                ->and($stored->billing_state)->toBe(BillingState::Unbilled);
        }

        expect(timeDraftFresh($running)->invoice_id)->toBeNull()
            ->and(timeDraftFresh($nonBillable)->invoice_id)->toBeNull()
            ->and(timeDraftFresh($billed)->invoice_id)->toBeNull()
            ->and(timeDraftFresh($billed)->billing_state)->toBe(BillingState::Billed)
            ->and(timeDraftFresh($october)->invoice_id)->toBeNull()
            ->and(timeDraftFresh($foreign)->invoice_id)->toBeNull();
    });

    it('does not let a second draft take the entries a first draft holds', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $alpha = timeDraftProject($client, 'Alpha project', ['hourly_rate' => '1200']);
        $entry = timeDraftEntry($client, $alpha, '2026-09-10 08:00:00', '2026-09-10 09:00:00');

        $first = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$alpha]));
        $second = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$alpha]));

        expect(timeDraftLines($first))->toHaveCount(1)
            ->and(timeDraftLines($second))->toBe([])
            ->and(timeDraftFresh($entry)->invoice_id)->toBe($first->id);
    });
});

it('refuses a Partner', function (): void {
    $client = InvoiceFixtures::client();
    $partner = Canary::partnerFor($client->id);

    $this->actingAs($partner);

    expect(fn () => app(CreateDraft::class)->handle($partner, timeDraftData($client, '2026-09-01', '2026-09-30', [])))
        ->toThrow(AuthorizationException::class);
});

describe('grouping, rates and projects', function (): void {
    it('groups per task with the reference and title and a line for the project time without a task', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project');
        $first = timeDraftTask($project, 'Build the form');
        $second = timeDraftTask($project, 'Review the form');
        timeDraftTaskEntry($first, 8, 1);
        timeDraftTaskEntry($first, 10, 0.5);
        timeDraftTaskEntry($second, 12, 2);
        timeDraftEntry($client, $project, '2026-09-11 08:00:00', '2026-09-11 08:30:00');

        $draft = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project], ['grouping' => 'task']));
        $lines = timeDraftLines($draft);

        expect($lines)->toHaveCount(3)
            ->and($lines[0]->description)->toBe($first->reference.' Build the form')
            ->and($lines[0]->quantity)->toBe('1.5000')
            ->and($lines[0]->source->task_id)->toBe($first->id)
            ->and($lines[1]->description)->toBe($second->reference.' Review the form')
            ->and($lines[1]->quantity)->toBe('2.0000')
            ->and($lines[2]->description)->toBe('Alpha project – ostatní')
            ->and($lines[2]->quantity)->toBe('0.5000')
            ->and($lines[2]->source->task_id)->toBeNull();
    });

    it('describes the generated lines in the language of the draft', function (): void {
        $client = InvoiceFixtures::client(['invoice_language' => 'en', 'hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project');
        timeDraftEntry($client, $project, '2026-09-11 08:00:00', '2026-09-11 08:30:00');
        timeDraftEntry($client, null, '2026-09-12 08:00:00', '2026-09-12 09:00:00');

        $draft = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project], ['grouping' => 'task', 'without_project' => true]));

        expect(array_map(static fn (InvoiceItem $line): string => $line->description, timeDraftLines($draft)))
            ->toBe(['Alpha project – other', 'Other work']);
    });

    it('splits a project whose tasks carry different rates into one line per rate', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project');
        timeDraftTaskEntry(timeDraftTask($project, 'Cheaper task', ['hourly_rate' => '1000']), 8, 1);
        timeDraftTaskEntry(timeDraftTask($project, 'Dearer task', ['hourly_rate' => '1400']), 10, 2);
        timeDraftTaskEntry(timeDraftTask($project, 'Another cheaper task', ['hourly_rate' => '1000']), 13, 1);

        $draft = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project]));
        $lines = timeDraftLines($draft);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->unit_price_minor)->toBe(100000)
            ->and($lines[0]->quantity)->toBe('2.0000')
            ->and($lines[0]->line_total_minor)->toBe(200000)
            ->and($lines[1]->unit_price_minor)->toBe(140000)
            ->and($lines[1]->quantity)->toBe('2.0000')
            ->and($lines[1]->line_total_minor)->toBe(280000);
    });

    it('bills the entries without a project as one line only when asked', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(70000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project');
        timeDraftEntry($client, null, '2026-09-12 08:00:00', '2026-09-12 09:00:00');
        timeDraftEntry($client, null, '2026-09-13 08:00:00', '2026-09-13 08:30:00');

        $without = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project]));
        $with = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project], ['without_project' => true]));

        $lines = timeDraftLines($with);

        expect(timeDraftLines($without))->toBe([])
            ->and($lines)->toHaveCount(1)
            ->and($lines[0]->description)->toBe('Ostatní práce')
            ->and($lines[0]->quantity)->toBe('1.5000')
            ->and($lines[0]->unit_price_minor)->toBe(70000)
            ->and($lines[0]->source->project_id)->toBeNull();
    });

    it('never adds the hours of a fixed-price project or task and leaves them unbilled and unreserved', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $fixed = timeDraftProject($client, 'Fixed project', ['billing_type' => 'fixed_price', 'fixed_price' => '50000', 'hourly_rate' => null]);
        $hourly = timeDraftProject($client, 'Hourly project');
        $fixedTask = timeDraftTask($hourly, 'Fixed task', ['billing_type' => 'fixed_price', 'fixed_price' => '9000']);
        $fixedEntry = timeDraftEntry($client, $fixed, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $taskEntry = timeDraftTaskEntry($fixedTask, 12, 1);
        $hourlyEntry = timeDraftEntry($client, $hourly, '2026-09-11 08:00:00', '2026-09-11 09:00:00');

        $draft = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$fixed, $hourly]));
        $lines = timeDraftLines($draft);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]->description)->toBe('Hourly project')
            ->and($lines[0]->quantity)->toBe('1.0000')
            ->and(timeDraftFresh($fixedEntry)->invoice_id)->toBeNull()
            ->and(timeDraftFresh($taskEntry)->invoice_id)->toBeNull()
            ->and(timeDraftFresh($hourlyEntry)->invoice_id)->toBe($draft->id);
    });

    it('accepts an archived project that holds unbilled time and refuses a project of another client', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $archived = timeDraftProject($client, 'Archived project');
        $entry = timeDraftEntry($client, $archived, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        timeDraftSystem(static fn () => $archived->delete());

        $draft = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$archived]));

        expect(timeDraftLines($draft))->toHaveCount(1)
            ->and(timeDraftFresh($entry)->invoice_id)->toBe($draft->id);

        $foreign = timeDraftProject(InvoiceFixtures::client(), 'Foreign project');

        expect(fn () => timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$foreign])))
            ->toThrow(ValidationException::class);
    });

    it('refuses a bad period, a bad day and an unknown grouping with field errors and creates nothing', function (): void {
        $client = InvoiceFixtures::client();
        $before = timeDraftSystem(static fn (): int => Invoice::query()->count());

        $errors = static function (array $data): array {
            try {
                timeDraftCreate($data);
            } catch (ValidationException $exception) {
                return $exception->errors();
            }

            return [];
        };

        expect($errors(timeDraftData($client, '2026-09-30', '2026-09-01', [])))->toHaveKey('period_to')
            ->and($errors(timeDraftData($client, '2026-02-30', '2026-09-01', [])))->toHaveKey('period_from')
            ->and($errors(timeDraftData($client, '2026-09-01', '2026-09-30', [], ['grouping' => 'month'])))->toHaveKey('grouping')
            ->and($errors(timeDraftData($client, '2026-09-01', '2026-09-30', [], ['project_ids' => ['not-a-uuid']])))->toHaveKey('project_ids')
            ->and(timeDraftSystem(static fn (): int => Invoice::query()->count()))->toBe($before);
    });
});

describe('the foreign-currency preview', function (): void {
    it('previews a line of other-currency time with today\'s CNB rate rounded once and marks it converted', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project', ['hourly_rate' => '1500']);
        timeDraftEntry($client, $project, '2026-09-10 08:00:00', '2026-09-10 18:00:00');

        $draft = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project], ['currency' => 'EUR']));
        $lines = timeDraftLines($draft);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]->quantity)->toBe('10.0000')
            // 1 500 CZK at 25,125 is 59,70 EUR; the amount is rounded once from the exact 597,0149 EUR, not 10 x 59,70.
            ->and($lines[0]->unit_price_minor)->toBe(5970)
            ->and($lines[0]->line_total_minor)->toBe(59701)
            ->and($lines[0]->price_converted)->toBeTrue()
            ->and($lines[0]->source->source_rate_minor)->toBe(150000)
            ->and($lines[0]->source->source_rate_currency)->toBe('CZK')
            ->and($lines[0]->source->price_manual)->toBeFalse();

        $stored = timeDraftSystem(static fn (): Invoice => Invoice::query()->findOrFail($draft->id));

        expect($stored->currency)->toBe('EUR')
            ->and($stored->total_minor)->toBe(59701);
    });

    it('prices time of the same currency exactly without converting it', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project', ['hourly_rate' => '1500']);
        timeDraftEntry($client, $project, '2026-09-10 08:00:00', '2026-09-10 18:00:00');

        $lines = timeDraftLines(timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project])));

        expect($lines[0]->unit_price_minor)->toBe(150000)
            ->and($lines[0]->line_total_minor)->toBe(1500000)
            ->and($lines[0]->price_converted)->toBeFalse();
    });

    it('converts through both quotes when neither the rate nor the draft is in CZK', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        InvoiceFixtures::cnbRate('USD', '2026-10-14', '22.5');
        $client = InvoiceFixtures::client(['currency' => 'EUR', 'hourly_rate' => Money::ofMinor(5000, 'EUR')]);
        $project = timeDraftProject($client, 'Alpha project', ['hourly_rate' => null]);
        timeDraftEntry($client, $project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');

        $lines = timeDraftLines(timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project], ['currency' => 'USD'])));

        // 50,00 EUR x 25,125 / 22,5 = 55,8333 USD for one hour, rounded once.
        expect($lines[0]->unit_price_minor)->toBe(5583)
            ->and($lines[0]->line_total_minor)->toBe(5583)
            ->and($lines[0]->price_converted)->toBeTrue()
            ->and($lines[0]->source->source_rate_currency)->toBe('EUR');
    });

    it('refuses a draft that needs a CNB list that does not exist and reserves nothing', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project', ['hourly_rate' => '1500']);
        $entry = timeDraftEntry($client, $project, '2026-09-10 08:00:00', '2026-09-10 18:00:00');
        $before = timeDraftSystem(static fn (): int => Invoice::query()->count());

        try {
            timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$project], ['currency' => 'EUR']));
            $failed = null;
        } catch (ValidationException $exception) {
            $failed = $exception;
        }

        expect($failed)->toBeInstanceOf(ValidationException::class)
            ->and($failed->errors())->toHaveKey('currency')
            ->and($failed->errors()['currency'][0])->toContain('EUR')
            ->and(timeDraftFresh($entry)->invoice_id)->toBeNull()
            ->and(timeDraftSystem(static fn (): int => Invoice::query()->count()))->toBe($before);
    });
});

describe('the picker summary', function (): void {
    it('returns the count, the seconds and the estimate that the created draft then has', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $alpha = timeDraftProject($client, 'Alpha project', ['hourly_rate' => '1500']);
        $bravo = timeDraftProject($client, 'Bravo project', ['hourly_rate' => '900']);
        timeDraftEntry($client, $alpha, '2026-09-10 08:00:00', '2026-09-10 18:00:00');
        timeDraftEntry($client, $bravo, '2026-09-11 08:00:00', '2026-09-11 08:50:07');

        $filters = [$client->id, '2026-09-01', '2026-09-30', [$alpha->id, $bravo->id], false];
        $summary = app(InvoiceableEntries::class)->summary(...$filters, grouping: ItemGrouping::PerProject, currency: 'EUR', now: TimerClock::now());

        $draft = timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-30', [$alpha, $bravo], ['currency' => 'EUR']));
        $stored = timeDraftSystem(static fn (): Invoice => Invoice::query()->findOrFail($draft->id));

        expect($summary['count'])->toBe(2)
            ->and($summary['seconds'])->toBe(36000 + 3007)
            ->and($summary['estimate'])->toEqual(Money::ofMinor($stored->total_minor, 'EUR'))
            ->and($summary['reserved_elsewhere'])->toBe(0)
            ->and($summary['fixed_price_projects'])->toBe([]);
    });

    it('counts the entries another draft holds and offers none of them', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project');
        timeDraftEntry($client, $project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        timeDraftEntry($client, $project, '2026-09-11 08:00:00', '2026-09-11 09:00:00');
        timeDraftCreate(timeDraftData($client, '2026-09-01', '2026-09-10', [$project]));

        $summary = app(InvoiceableEntries::class)->summary($client->id, '2026-09-01', '2026-09-30', [$project->id], false, ItemGrouping::PerProject, 'CZK', TimerClock::now());

        expect($summary['count'])->toBe(1)
            ->and($summary['seconds'])->toBe(3600)
            ->and($summary['reserved_elsewhere'])->toBe(1)
            ->and($summary['estimate'])->toEqual(Money::ofMinor(90000, 'CZK'));
    });

    it('lists the fixed-price projects among the chosen ones and leaves their time out of the count', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $fixed = timeDraftProject($client, 'Fixed project', ['billing_type' => 'fixed_price', 'fixed_price' => '50000', 'hourly_rate' => null]);
        $hourly = timeDraftProject($client, 'Hourly project');
        timeDraftEntry($client, $fixed, '2026-09-10 08:00:00', '2026-09-10 10:00:00');

        $summary = app(InvoiceableEntries::class)->summary($client->id, '2026-09-01', '2026-09-30', [$fixed->id, $hourly->id], false, ItemGrouping::PerProject, 'CZK', TimerClock::now());

        expect($summary['fixed_price_projects'])->toBe(['Fixed project'])
            ->and($summary['count'])->toBe(0)
            ->and($summary['estimate'])->toEqual(Money::zero('CZK'));
    });

    it('has no estimate when a needed CNB list is missing', function (): void {
        $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(80000, 'CZK')]);
        $project = timeDraftProject($client, 'Alpha project', ['hourly_rate' => '1500']);
        timeDraftEntry($client, $project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');

        $summary = app(InvoiceableEntries::class)->summary($client->id, '2026-09-01', '2026-09-30', [$project->id], false, ItemGrouping::PerProject, 'EUR', TimerClock::now());

        expect($summary['count'])->toBe(1)
            ->and($summary['estimate'])->toBeNull();
    });

    it('refuses a Partner both reader methods', function (): void {
        $client = InvoiceFixtures::client();
        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);
        app()->forgetScopedInstances();

        $reader = app(InvoiceableEntries::class);

        expect(fn () => $reader->candidates($client->id, '2026-09-01', '2026-09-30', [], true, TimerClock::now()))->toThrow(AuthorizationException::class)
            ->and(fn () => $reader->summary($client->id, '2026-09-01', '2026-09-30', [], true, ItemGrouping::PerProject, 'CZK', TimerClock::now()))->toThrow(AuthorizationException::class);
    });
});
