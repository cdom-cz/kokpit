<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
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
