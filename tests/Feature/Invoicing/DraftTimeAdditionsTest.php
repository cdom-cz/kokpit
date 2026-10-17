<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\AddEntriesToDraft;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
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

    it('refuses an issued document with not_draft and a Partner outright', function (): void {
        $client = InvoiceFixtures::client();
        $alpha = additionProject($client, 'Alpha project');
        additionEntry($alpha, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $issued = app(IssueDocument::class)->handle($this->admin, additionDraft($client, [$alpha]));
        $new = additionEntry($alpha, '2026-09-20 08:00:00', '2026-09-20 09:00:00');

        expect(fn () => app(AddEntriesToDraft::class)->handle($this->admin, $issued, [$new->id]))
            ->toThrow(DomainException::class, __('invoices.errors.not_draft'))
            ->and(additionFresh($new)->invoice_id)->toBeNull();

        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(fn () => app(AddEntriesToDraft::class)->handle($partner, $issued, [$new->id]))->toThrow(AuthorizationException::class);
    });
});
