<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * The billing step of issuing (IN-02, IN-10; D-04, D-10, D-15): the time a draft holds becomes billed and locked in
 * the same transaction that numbers the document, or the issue fails and nothing is consumed. Every name and amount
 * is fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * A client with a project at 1 200 an hour and three finished entries of September, as plain rows.
 *
 * @return array{client: Client, project: Project, entries: list<TimeEntry>}
 */
function issueBillingSetup(): array
{
    $client = InvoiceFixtures::client();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Billing project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1200',
    ]);

    $entries = [];

    foreach ([['2026-09-10 08:00:00', '2026-09-10 09:00:00'], ['2026-09-11 08:00:00', '2026-09-11 09:30:00'], ['2026-09-12 08:00:00', '2026-09-12 08:45:00']] as [$start, $end]) {
        $factory = TimeEntry::factory()->forProject($project);
        $entries[] = app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => test()->admin->id,
            'started_at' => CarbonImmutable::parse($start, 'UTC'),
            'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ])->id));
    }

    return ['client' => $client, 'project' => $project, 'entries' => $entries];
}

/**
 * A draft or proforma of September time of the project.
 */
function issueBillingDraft(Client $client, Project $project, string $kind = 'invoice'): Invoice
{
    return app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => $kind,
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
    ]);
}

function issueBillingFresh(TimeEntry $entry): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

describe('the tracer: issuing a draft created from time', function (): void {
    it('bills every linked entry with its rate snapshot and keeps the link', function (): void {
        ['client' => $client, 'project' => $project, 'entries' => $entries] = issueBillingSetup();
        $draft = issueBillingDraft($client, $project);

        $issued = app(IssueDocument::class)->handle($this->admin, $draft);

        expect($issued->state->value)->toBe('issued');

        $snapshots = [];

        foreach ($entries as $entry) {
            $stored = issueBillingFresh($entry);
            $snapshots[] = $stored->billed_amount_minor;

            expect($stored->billing_state)->toBe(BillingState::Billed)
                ->and($stored->invoice_id)->toBe($issued->id)
                ->and($stored->billed_at)->not->toBeNull()
                ->and($stored->billed_rate_minor)->toBe(120000)
                ->and($stored->billed_rate_currency)->toBe('CZK');
        }

        expect($snapshots)->toBe([120000, 180000, 90000]);
    });

    it('refuses a change to a billed entry after the issue', function (): void {
        ['client' => $client, 'project' => $project, 'entries' => $entries] = issueBillingSetup();
        app(IssueDocument::class)->handle($this->admin, issueBillingDraft($client, $project));

        expect(fn () => app(UpdateTimeEntry::class)->handle($this->admin, $entries[0], ['description' => 'Changed after billing']))
            ->toThrow(DomainException::class, __('kokpit.time.errors.locked'));
    });

    it('bills the entries of a proforma the same way', function (): void {
        ['client' => $client, 'project' => $project, 'entries' => $entries] = issueBillingSetup();
        $proforma = issueBillingDraft($client, $project, 'proforma');

        $issued = app(IssueDocument::class)->handle($this->admin, $proforma);

        foreach ($entries as $entry) {
            $stored = issueBillingFresh($entry);

            expect($stored->billing_state)->toBe(BillingState::Billed)
                ->and($stored->invoice_id)->toBe($issued->id)
                ->and($stored->billed_rate_minor)->toBe(120000);
        }
    });

    it('fails with entries_changed and consumes nothing when a linked entry changed underneath', function (): void {
        ['client' => $client, 'project' => $project, 'entries' => $entries] = issueBillingSetup();
        $draft = issueBillingDraft($client, $project);
        $sequences = DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all();

        // Bypasses the Action lock on purpose: the row changes after the draft reserved it.
        DB::update('UPDATE time_entries SET billable = false WHERE id = ?', [$entries[1]->id]);

        try {
            app(IssueDocument::class)->handle($this->admin, $draft);
            $this->fail('The issue had to be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('entries_changed');
        }

        $stored = app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($draft->id));

        expect($stored->state->value)->toBe('draft')
            ->and($stored->number)->toBeNull()
            ->and(DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($sequences);

        foreach ($entries as $entry) {
            $row = issueBillingFresh($entry);

            expect($row->billing_state)->toBe(BillingState::Unbilled)
                ->and($row->invoice_id)->toBe($draft->id)
                ->and($row->billed_at)->toBeNull();
        }
    });
});
