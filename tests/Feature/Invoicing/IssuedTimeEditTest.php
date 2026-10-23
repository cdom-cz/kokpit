<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * Linked time on an open issued invoice or proforma (IN-13, IN-14, IN-15; D-05, D-07, D-09, D-11, D-13; research
 * Pitfall 6): time is added to and released from the document exactly as on a draft, and every such move ends with
 * IssuedEditFinisher::finish(), so the CZK total, the card link, the PDF and the audit follow. Every name, rate and
 * amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 09:30 in Prague: the day of the issue.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function iteSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A project of the client with an hourly rate of the amount, in the currency of the client.
 */
function iteProject(Client $client, string $name = 'Alpha project', string $rate = '1000'): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => $rate,
    ]);
}

/**
 * A finished entry of the project, from UTC instants.
 *
 * @param  array<string, mixed>  $state
 */
function iteEntry(Project $project, string $start, string $end, array $state = []): TimeEntry
{
    $factory = TimeEntry::factory()->forProject($project);

    return iteSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ...$state,
    ])->id));
}

/**
 * An open issued invoice made from the September time of the projects (one entry of two hours for the first).
 *
 * @param  list<Project>  $projects
 */
function iteIssued(Client $client, array $projects, string $currency = 'CZK'): Invoice
{
    $draft = app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => array_map(static fn (Project $project): string => $project->id, $projects),
        'without_project' => false,
        'grouping' => 'project',
        'currency' => $currency,
    ]);

    return app(IssueDocument::class)->handle(test()->admin, $draft);
}

function iteEntryFresh(TimeEntry $entry): TimeEntry
{
    return iteSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

function iteRow(Invoice $document): Invoice
{
    return iteSystem(static fn (): Invoice => Invoice::query()->whereKey($document->id)->firstOrFail());
}

function iteAdmin(Invoice $document): InvoiceAdmin
{
    return iteSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $document->id)->firstOrFail());
}

/**
 * The stored lines of the document in order, with their sources.
 *
 * @return list<InvoiceItem>
 */
function iteLines(Invoice $document): array
{
    return iteSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $document->id)->with('source')->orderBy('position')->get()->all());
}

/**
 * The tab "Navázaný čas" of the document as the view page mounts it.
 */
function iteTab(Invoice $document): Testable
{
    return Livewire::test(InvoiceEntriesRelationManager::class, ['ownerRecord' => $document, 'pageClass' => ViewInvoice::class]);
}

/**
 * The data of the dialog "Přidat nový čas" for the September time of the client's projects.
 *
 * @param  list<string>  $ids
 * @return array<string, mixed>
 */
function iteAddData(array $ids): array
{
    return ['period' => 'custom', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'without_project' => true, 'entry_ids' => $ids];
}

describe('the tracer: time tracked after the issue goes onto the open invoice', function (): void {
    it('bills the entries, appends a line, grows the total and replaces the PDF without sending anything', function (): void {
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $invoice = iteIssued($client, [$project]);
        $pdfBefore = iteAdmin($invoice)->pdf_document_id;

        $one = iteEntry($project, '2026-09-20 08:00:00', '2026-09-20 09:00:00');
        $two = iteEntry($project, '2026-09-21 08:00:00', '2026-09-21 08:30:00');

        expect(iteRow($invoice)->total_minor)->toBe(200000);

        iteTab($invoice)
            ->assertActionVisible(TestAction::make('addTime')->table())
            ->callAction(TestAction::make('addTime')->table(), iteAddData([$one->id, $two->id]))
            ->assertHasNoActionErrors()
            ->assertNotified();

        $lines = iteLines($invoice);

        // Both entries are billed by the invoice at once, with the rate and the amount of the moment.
        expect(iteEntryFresh($one)->invoice_id)->toBe($invoice->id)
            ->and(iteEntryFresh($one)->billing_state)->toBe(BillingState::Billed)
            ->and(iteEntryFresh($one)->billed_rate_minor)->toBe(100000)
            ->and(iteEntryFresh($one)->billed_amount_minor)->toBe(100000)
            ->and(iteEntryFresh($two)->billing_state)->toBe(BillingState::Billed)
            ->and(iteEntryFresh($two)->billed_amount_minor)->toBe(50000)
            ->and($lines)->toHaveCount(2)
            ->and($lines[1]->quantity)->toBe('1.5000')
            ->and($lines[1]->line_total_minor)->toBe(150000)
            ->and(iteRow($invoice)->total_minor)->toBe(350000)
            ->and(iteAdmin($invoice)->pdf_document_id)->not->toBeNull()->not->toBe($pdfBefore)
            ->and(iteSystem(static fn (): int => InvoiceEmail::query()->count()))->toBe(0);
    });
});
