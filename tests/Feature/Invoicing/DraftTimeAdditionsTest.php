<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\AddEntriesToDraft;
use App\Domain\Invoicing\Actions\AddFixedAmount;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * Time and fixed amounts added to a draft after it was made (IN-02, D-06, D-07): new lines are appended, an existing
 * line is never rewritten, and the time that is claimed is exactly the time that is free. Every name and amount is
 * fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * A project of the client written through the domain Action.
 *
 * @param  array<string, mixed>  $data
 */
function additionProject(Client $client, string $name, array $data = []): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1000',
        ...$data,
    ]);
}

/**
 * A finished entry of the project, from UTC instants.
 *
 * @param  array<string, mixed>  $state
 */
function additionEntry(Project $project, string $start, string $end, array $state = []): TimeEntry
{
    $factory = TimeEntry::factory()->forProject($project);

    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ...$state,
    ])->id));
}

/**
 * A draft of the September time of the projects.
 *
 * @param  list<Project>  $projects
 */
function additionDraft(Client $client, array $projects, string $grouping = 'project'): Invoice
{
    return app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => array_map(static fn (Project $project): string => $project->id, $projects),
        'without_project' => false,
        'grouping' => $grouping,
    ]);
}

function additionFresh(TimeEntry $entry): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

function additionDraftFresh(Invoice $draft): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($draft->id));
}

/**
 * The stored lines of the draft in order, with their sources.
 *
 * @return list<InvoiceItem>
 */
function additionLines(Invoice $draft): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $draft->id)->with('source')->orderBy('position')->get()->all());
}

describe('new time on a draft', function (): void {
    it('appends lines for the new entries only and keeps the lines edited by hand', function (): void {
        $client = InvoiceFixtures::client();
        $alpha = additionProject($client, 'Alpha project');
        $bravo = additionProject($client, 'Bravo project', ['hourly_rate' => '800']);
        additionEntry($alpha, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $draft = additionDraft($client, [$alpha]);

        $first = additionLines($draft)[0];
        app(UpdateDraft::class)->handle($this->admin, $draft, ['items' => [
            [...InvoiceFixtures::item('Alpha, agreed text', '2', '900'), 'id' => $first->id],
        ]]);

        $newAlpha = additionEntry($alpha, '2026-09-20 08:00:00', '2026-09-20 09:00:00');
        $newBravo = additionEntry($bravo, '2026-09-21 08:00:00', '2026-09-21 08:30:00');

        $result = app(AddEntriesToDraft::class)->handle($this->admin, $draft, [$newAlpha->id, $newBravo->id]);
        $lines = additionLines($draft);

        expect($result)->toBe(['entries' => 2, 'items' => 2])
            ->and($lines)->toHaveCount(3)
            ->and($lines[0]->id)->toBe($first->id)
            ->and($lines[0]->description)->toBe('Alpha, agreed text')
            ->and($lines[0]->unit_price_minor)->toBe(90000)
            ->and($lines[0]->line_total_minor)->toBe(180000)
            ->and($lines[0]->source->price_manual)->toBeTrue()
            ->and($lines[1]->description)->toBe('Alpha project')
            ->and($lines[1]->position)->toBe(2)
            ->and($lines[1]->unit_price_minor)->toBe(100000)
            ->and($lines[1]->line_total_minor)->toBe(100000)
            ->and($lines[1]->source->project_id)->toBe($alpha->id)
            ->and($lines[2]->description)->toBe('Bravo project')
            ->and($lines[2]->line_total_minor)->toBe(40000)
            ->and(additionDraftFresh($draft)->total_minor)->toBe(320000)
            ->and(additionFresh($newAlpha)->invoice_id)->toBe($draft->id)
            ->and(additionFresh($newAlpha)->billing_state)->toBe(BillingState::Unbilled)
            ->and(additionFresh($newBravo)->invoice_id)->toBe($draft->id);
    });

    it('follows the per-task grouping of the draft', function (): void {
        $client = InvoiceFixtures::client();
        $alpha = additionProject($client, 'Alpha project');
        $task = app(CreateTask::class)->handle($this->admin, $alpha, ['title' => 'Write the thing']);
        $factory = TimeEntry::factory()->forTask($task);
        app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => $factory->create([
            'user_id' => test()->admin->id,
            'started_at' => CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC'),
            'ended_at' => CarbonImmutable::parse('2026-09-10 09:00:00', 'UTC'),
        ]));
        $draft = additionDraft($client, [$alpha], 'task');

        $newOne = additionEntry($alpha, '2026-09-20 08:00:00', '2026-09-20 09:00:00');
        app(AddEntriesToDraft::class)->handle($this->admin, $draft, [$newOne->id]);

        $lines = additionLines($draft);

        expect($lines)->toHaveCount(2)
            ->and($lines[1]->description)->toContain('Alpha project')
            ->and($lines[1]->source->task_id)->toBeNull();
    });

    it('adds nothing for an entry another draft holds', function (): void {
        $client = InvoiceFixtures::client();
        $alpha = additionProject($client, 'Alpha project');
        additionEntry($alpha, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $draft = additionDraft($client, [$alpha]);

        $taken = additionEntry($alpha, '2026-09-20 08:00:00', '2026-09-20 09:00:00');
        $other = additionDraft($client, [$alpha]);

        expect(additionFresh($taken)->invoice_id)->toBe($other->id);

        $result = app(AddEntriesToDraft::class)->handle($this->admin, $draft, [$taken->id]);

        expect($result)->toBe(['entries' => 0, 'items' => 0])
            ->and(additionFresh($taken)->invoice_id)->toBe($other->id)
            ->and(additionLines($draft))->toHaveCount(1);
    });

    it('leaves out a running, a non-billable and a billed entry', function (): void {
        $client = InvoiceFixtures::client();
        $alpha = additionProject($client, 'Alpha project');
        additionEntry($alpha, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $draft = additionDraft($client, [$alpha]);

        $running = app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail(TimeEntry::factory()->forProject($alpha)->create([
            'user_id' => test()->admin->id,
            'started_at' => CarbonImmutable::parse('2026-09-22 08:00:00', 'UTC'),
            'ended_at' => null,
        ])->id));
        $nonBillable = additionEntry($alpha, '2026-09-23 08:00:00', '2026-09-23 09:00:00', ['billable' => false]);
        $billed = additionEntry($alpha, '2026-09-24 08:00:00', '2026-09-24 09:00:00', ['billing_state' => 'billed', 'billed_at' => CarbonImmutable::parse('2026-09-30 10:00:00', 'UTC')]);

        $result = app(AddEntriesToDraft::class)->handle($this->admin, $draft, [$running->id, $nonBillable->id, $billed->id]);

        expect($result)->toBe(['entries' => 0, 'items' => 0])
            ->and(additionFresh($running)->invoice_id)->toBeNull()
            ->and(additionFresh($nonBillable)->invoice_id)->toBeNull()
            ->and(additionFresh($billed)->invoice_id)->toBeNull();
    });

    it('refuses an entry of another client or an unknown one under entry_ids and claims nothing', function (): void {
        $client = InvoiceFixtures::client();
        $alpha = additionProject($client, 'Alpha project');
        additionEntry($alpha, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $draft = additionDraft($client, [$alpha]);

        $mine = additionEntry($alpha, '2026-09-20 08:00:00', '2026-09-20 09:00:00');
        $foreignProject = additionProject(InvoiceFixtures::client(), 'Foreign project');
        $foreign = additionEntry($foreignProject, '2026-09-20 08:00:00', '2026-09-20 09:00:00');

        $attempt = static function (array $ids) use ($draft): array {
            try {
                app(AddEntriesToDraft::class)->handle(test()->admin, $draft, $ids);
            } catch (ValidationException $exception) {
                return $exception->errors();
            }

            return [];
        };

        expect($attempt([$mine->id, $foreign->id]))->toBe(['entry_ids' => [__('invoices.errors.entries_foreign')]])
            ->and($attempt([$mine->id, '0198f2a0-0000-7000-8000-000000000000']))->toBe(['entry_ids' => [__('invoices.errors.entries_foreign')]])
            ->and(additionFresh($mine)->invoice_id)->toBeNull()
            ->and(additionFresh($foreign)->invoice_id)->toBeNull();
    });

    it('refuses a locked issued document under document and a Partner outright', function (): void {
        $client = InvoiceFixtures::client();
        $alpha = additionProject($client, 'Alpha project');
        additionEntry($alpha, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $issued = InvoiceFixtures::lock(app(IssueDocument::class)->handle($this->admin, additionDraft($client, [$alpha])));
        $new = additionEntry($alpha, '2026-09-20 08:00:00', '2026-09-20 09:00:00');

        expect(fn () => app(AddEntriesToDraft::class)->handle($this->admin, $issued, [$new->id]))
            ->toThrow(ValidationException::class, __('invoices.errors.document_locked'))
            ->and(additionFresh($new)->invoice_id)->toBeNull();

        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(fn () => app(AddEntriesToDraft::class)->handle($partner, $issued, [$new->id]))->toThrow(AuthorizationException::class);
    });
});

/**
 * A fixed-price project of 50 000 with its three finished entries of September.
 *
 * @return array{project: Project, entries: list<TimeEntry>}
 */
function additionFixedProject(Client $client, string $name = 'Fixed project'): array
{
    $project = additionProject($client, $name, ['billing_type' => 'fixed_price', 'fixed_price' => '50000', 'hourly_rate' => null]);

    return ['project' => $project, 'entries' => [
        additionEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00'),
        additionEntry($project, '2026-09-11 08:00:00', '2026-09-11 10:00:00'),
        additionEntry($project, '2026-09-12 08:00:00', '2026-09-12 08:30:00'),
    ]];
}

/**
 * The errors of a refused Action call, or an empty list when it was accepted.
 *
 * @return array<string, list<string>>
 */
function additionRefused(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

describe('a fixed amount on a draft', function (): void {
    it('adds one paušál line with the typed amount and leaves the time of the project alone', function (): void {
        $client = InvoiceFixtures::client();
        ['project' => $project, 'entries' => $entries] = additionFixedProject($client);
        $draft = InvoiceFixtures::draft($client);

        $item = app(AddFixedAmount::class)->handle($this->admin, $draft, ['project_id' => $project->id, 'amount' => '30 000,00', 'link_time' => false]);
        $lines = additionLines($draft);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]->id)->toBe($item->id)
            ->and($lines[0]->description)->toBe('Fixed project')
            ->and($lines[0]->quantity)->toBe(InvoiceQuantity::fromDecimal('1')->toDecimal())
            ->and($lines[0]->unit)->toBe('paušál')
            ->and($lines[0]->unit_price_minor)->toBe(3000000)
            ->and($lines[0]->line_total_minor)->toBe(3000000)
            ->and($lines[0]->price_converted)->toBeFalse()
            ->and($lines[0]->source->origin)->toBe(InvoiceItemOrigin::FixedAmount)
            ->and($lines[0]->source->project_id)->toBe($project->id)
            ->and($lines[0]->source->price_manual)->toBeTrue()
            ->and(additionDraftFresh($draft)->total_minor)->toBe(3000000);

        foreach ($entries as $entry) {
            expect(additionFresh($entry)->invoice_id)->toBeNull()
                ->and(additionFresh($entry)->billing_state)->toBe(BillingState::Unbilled);
        }
    });

    it('keeps a typed description and appends after the lines that are already there', function (): void {
        $client = InvoiceFixtures::client();
        ['project' => $project] = additionFixedProject($client);
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Manual work', '1', '100')]);

        app(AddFixedAmount::class)->handle($this->admin, $draft, ['project_id' => $project->id, 'description' => 'First instalment', 'amount' => '10000']);
        $lines = additionLines($draft);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->description)->toBe('Manual work')
            ->and($lines[1]->description)->toBe('First instalment')
            ->and($lines[1]->position)->toBe(2)
            ->and(additionDraftFresh($draft)->total_minor)->toBe(1010000);
    });

    it('links the unbilled time of the project and bills it without a rate snapshot at issue', function (): void {
        $client = InvoiceFixtures::client();
        ['project' => $project, 'entries' => $entries] = additionFixedProject($client);
        $hourlyTask = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Paid by the hour']);
        app(UpdateTask::class)->handle($this->admin, $hourlyTask, ['billing_type' => 'hourly', 'hourly_rate' => '500']);
        $factory = TimeEntry::factory()->forTask($hourlyTask);
        $hourlyEntry = app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => test()->admin->id,
            'started_at' => CarbonImmutable::parse('2026-09-13 08:00:00', 'UTC'),
            'ended_at' => CarbonImmutable::parse('2026-09-13 09:00:00', 'UTC'),
        ])->id));
        $draft = InvoiceFixtures::draft($client);

        app(AddFixedAmount::class)->handle($this->admin, $draft, ['project_id' => $project->id, 'amount' => '30000', 'link_time' => true]);

        foreach ($entries as $entry) {
            expect(additionFresh($entry)->invoice_id)->toBe($draft->id)
                ->and(additionFresh($entry)->billing_state)->toBe(BillingState::Unbilled);
        }

        expect(additionFresh($hourlyEntry)->invoice_id)->toBeNull();

        $issued = app(IssueDocument::class)->handle($this->admin, $draft);

        foreach ($entries as $entry) {
            $stored = additionFresh($entry);

            expect($stored->billing_state)->toBe(BillingState::Billed)
                ->and($stored->invoice_id)->toBe($issued->id)
                ->and($stored->billed_rate_minor)->toBeNull()
                ->and($stored->billed_amount_minor)->toBeNull();
        }
    });

    it('shows the fixed price, what issued fixed-amount lines billed, and the time that would be taken along', function (): void {
        $client = InvoiceFixtures::client();
        ['project' => $project] = additionFixedProject($client);
        $first = InvoiceFixtures::draft($client);
        app(AddFixedAmount::class)->handle($this->admin, $first, ['project_id' => $project->id, 'amount' => '20000']);
        app(IssueDocument::class)->handle($this->admin, $first);

        $second = InvoiceFixtures::draft($client);
        app(AddFixedAmount::class)->handle($this->admin, $second, ['project_id' => $project->id, 'amount' => '5000']);

        $context = app(AddFixedAmount::class)->context($second, Project::query()->with('billing')->findOrFail($project->id));

        expect($context['price']?->minor)->toBe(5000000)
            ->and($context['price']?->currency)->toBe('CZK')
            ->and(array_map(static fn (Money $money): array => [$money->minor, $money->currency], $context['billed']))->toBe([[2000000, 'CZK']])
            ->and($context['unbilled_count'])->toBe(3)
            ->and($context['unbilled_seconds'])->toBe(3600 + 7200 + 1800);
    });

    it('counts a fully cancelled fixed amount as nothing billed', function (): void {
        $client = InvoiceFixtures::client();
        ['project' => $project] = additionFixedProject($client);
        $draft = InvoiceFixtures::draft($client);
        app(AddFixedAmount::class)->handle($this->admin, $draft, ['project_id' => $project->id, 'amount' => '20000']);
        InvoiceFixtures::stornoed(app(IssueDocument::class)->handle($this->admin, $draft));

        $context = app(AddFixedAmount::class)->context(InvoiceFixtures::draft($client), Project::query()->with('billing')->findOrFail($project->id));

        expect(array_map(static fn (Money $money): int => $money->minor, $context['billed']))->toBe([0]);
    });

    it('refuses an hourly project, a project of another client and a bad amount under their keys', function (): void {
        $client = InvoiceFixtures::client();
        ['project' => $fixed] = additionFixedProject($client);
        $hourly = additionProject($client, 'Hourly project');
        ['project' => $foreign] = additionFixedProject(InvoiceFixtures::client(), 'Foreign fixed project');
        $draft = InvoiceFixtures::draft($client);
        $add = static fn (array $data): array => additionRefused(static fn () => app(AddFixedAmount::class)->handle(test()->admin, $draft, $data));

        expect($add(['project_id' => $hourly->id, 'amount' => '100']))->toBe(['project_id' => [__('invoices.errors.fixed_project')]])
            ->and($add(['project_id' => $foreign->id, 'amount' => '100']))->toBe(['project_id' => [__('invoices.errors.projects_unknown')]])
            ->and($add(['project_id' => 'nope', 'amount' => '100']))->toBe(['project_id' => [__('invoices.errors.projects_unknown')]])
            ->and($add(['project_id' => $fixed->id, 'amount' => '0']))->toBe(['amount' => [__('invoices.errors.fixed_amount')]])
            ->and($add(['project_id' => $fixed->id, 'amount' => '-5']))->toBe(['amount' => [__('invoices.errors.fixed_amount')]])
            ->and($add(['project_id' => $fixed->id, 'amount' => 'abc']))->toBe(['amount' => [__('invoices.errors.fixed_amount')]])
            ->and($add(['project_id' => $fixed->id]))->toBe(['amount' => [__('invoices.errors.fixed_amount')]])
            ->and($add(['project_id' => $fixed->id, 'amount' => '100', 'description' => str_repeat('x', 501)]))->toHaveKey('description')
            ->and(additionLines($draft))->toBe([]);
    });

    it('refuses an issued document with not_draft and a Partner outright', function (): void {
        $client = InvoiceFixtures::client();
        ['project' => $project] = additionFixedProject($client);
        $issued = InvoiceFixtures::issued($client);

        expect(fn () => app(AddFixedAmount::class)->handle($this->admin, $issued, ['project_id' => $project->id, 'amount' => '100']))
            ->toThrow(DomainException::class, __('invoices.errors.not_draft'));

        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(fn () => app(AddFixedAmount::class)->handle($partner, InvoiceFixtures::draft($client, actor: $this->admin), ['project_id' => $project->id, 'amount' => '100']))
            ->toThrow(AuthorizationException::class);
    });
});
