<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\AddEntriesToDraft;
use App\Domain\Invoicing\Actions\AddFixedAmount;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\ReleaseEntries;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\TimeTracking\Actions\CancelEntriesBilling;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use App\Filament\Resources\TimeEntryResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
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

/**
 * An issued invoice that was sent once and unlocked again, so that the audit of an edit applies.
 */
function iteSentAndUnlocked(Invoice $invoice): Invoice
{
    Mail::getSymfonyTransport()->flush();

    $row = app(QueueInvoiceEmail::class)->handle(test()->admin, $invoice, [
        'recipient' => 'jane@example.com',
        'subject' => 'Example invoice',
        'body' => 'Example body',
        'attach_work_report' => false,
    ]);

    SendInvoiceEmail::dispatchSync($row->id);

    return app(UnlockInvoice::class)->handle(test()->admin, $invoice, 'Example reason to add the missing time');
}

/**
 * The activity rows of one event of a document, oldest first.
 *
 * @return list<Activity>
 */
function iteActivity(Invoice $document, string $event): array
{
    return Activity::query()
        ->where('subject_type', 'invoice')
        ->where('subject_id', $document->id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * The errors of a refused Action call, or an empty list when it was accepted.
 *
 * @return array<string, list<string>>
 */
function iteRefused(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

describe('time released from an open issued document', function (): void {
    it('frees the chosen entries, keeps the lines and ignores an entry of another document', function (): void {
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        $one = iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $two = iteEntry($project, '2026-09-11 08:00:00', '2026-09-11 10:00:00');
        $keep = iteEntry($project, '2026-09-12 08:00:00', '2026-09-12 08:30:00');
        $invoice = iteIssued($client, [$project]);

        $otherClient = InvoiceFixtures::client();
        $otherProject = iteProject($otherClient, 'Beta project');
        $foreign = iteEntry($otherProject, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        iteIssued($otherClient, [$otherProject]);

        $linesBefore = array_map(static fn (InvoiceItem $line): array => [$line->id, $line->quantity, $line->line_total_minor], iteLines($invoice));
        $pdfBefore = iteAdmin($invoice)->pdf_document_id;

        iteTab($invoice)
            ->selectTableRecords([$one->id, $two->id])
            ->callAction(TestAction::make('unlink')->table()->bulk())
            ->assertNotified();

        $freed = iteEntryFresh($one);

        expect($freed->billing_state)->toBe(BillingState::Unbilled)
            ->and($freed->invoice_id)->toBeNull()
            ->and($freed->billed_at)->toBeNull()
            ->and($freed->billed_rate_minor)->toBeNull()
            ->and($freed->billed_amount_minor)->toBeNull()
            ->and(iteEntryFresh($two)->invoice_id)->toBeNull()
            ->and(iteEntryFresh($keep)->billing_state)->toBe(BillingState::Billed)
            ->and(iteEntryFresh($keep)->invoice_id)->toBe($invoice->id)
            ->and(array_map(static fn (InvoiceItem $line): array => [$line->id, $line->quantity, $line->line_total_minor], iteLines($invoice)))->toBe($linesBefore)
            ->and(iteAdmin($invoice)->pdf_document_id)->not->toBe($pdfBefore);

        // The id of an entry another document bills is ignored, not an error.
        expect(app(ReleaseEntries::class)->handle($this->admin, $invoice, [$foreign->id]))->toBe(0)
            ->and(iteEntryFresh($foreign)->billing_state)->toBe(BillingState::Billed);
    });

    it('writes one invoice_edited row with the number of linked entries gone down after the first send', function (): void {
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        $one = iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        iteEntry($project, '2026-09-11 08:00:00', '2026-09-11 10:00:00');
        $invoice = iteSentAndUnlocked(iteIssued($client, [$project]));
        $updatedBefore = count(iteActivity($invoice, 'updated'));

        expect(app(ReleaseEntries::class)->handle($this->admin, $invoice, [$one->id]))->toBe(1);

        $edited = iteActivity($invoice, 'invoice_edited');

        expect($edited)->toHaveCount(1)
            ->and($edited[0]->causer_id)->toBe($this->admin->id)
            ->and($edited[0]->attribute_changes?->all())->toBe(['attributes' => ['entries' => 1], 'old' => ['entries' => 2]])
            ->and(count(iteActivity($invoice, 'updated')))->toBe($updatedBefore);
    });
});

describe('time added to an open issued document that was sent once', function (): void {
    it('writes one invoice_edited row with the new total, the line and the number of entries', function (): void {
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $invoice = iteSentAndUnlocked(iteIssued($client, [$project]));
        $new = iteEntry($project, '2026-09-20 08:00:00', '2026-09-20 09:00:00');
        $updatedBefore = count(iteActivity($invoice, 'updated'));

        expect(app(AddEntriesToDraft::class)->handle($this->admin, $invoice, [$new->id]))->toBe(['entries' => 1, 'items' => 1]);

        $edited = iteActivity($invoice, 'invoice_edited');
        $changes = $edited[0]->attribute_changes?->all() ?? [];

        expect($edited)->toHaveCount(1)
            ->and($changes['attributes']['total_minor'] ?? null)->toBe(300000)
            ->and($changes['attributes']['entries'] ?? null)->toBe(2)
            ->and($changes['old']['entries'] ?? null)->toBe(1)
            ->and($changes['attributes'])->toHaveKey('items.2.line_total_minor')
            ->and(count(iteActivity($invoice, 'updated')))->toBe($updatedBefore);
    });
});

describe('the conversion of new lines at the issue date', function (): void {
    it('converts a CZK-rated line of a EUR invoice with the lists of the issue date and freezes its snapshot', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $invoice = iteIssued($client, [$project], 'EUR');
        $totalBefore = iteRow($invoice)->total_minor;

        // Two days later a newer list exists; the new line must not use it.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-16', '30.000');
        $new = iteEntry($project, '2026-09-20 08:00:00', '2026-09-20 09:00:00');

        app(AddEntriesToDraft::class)->handle($this->admin, $invoice, [$new->id]);

        $line = iteLines($invoice)[1];

        // 1 000,00 CZK at 25,000 CZK per EUR is 40,00 EUR (at 30,000 it would be 33,33).
        expect($line->price_converted)->toBeTrue()
            ->and($line->line_total_minor)->toBe(4000)
            ->and($line->source?->conversion_snapshot)->toBeArray()
            ->and((float) ($line->source?->conversion_snapshot['target_rate'] ?? 0))->toBe(25.0)
            ->and(iteRow($invoice)->total_minor)->toBe($totalBefore + 4000)
            ->and(iteAdmin($invoice)->czk_total_minor)->toBe((int) round((($totalBefore + 4000) / 100) * 25 * 100));
    });

    it('refuses cnb_missing naming the issue date and claims nothing', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $invoice = iteIssued($client, [$project], 'EUR');

        // A day with no list at all before it: the lists of the issue date cannot be found any more.
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['issue_date' => '2026-10-13', 'due_date' => '2026-10-27']);
        $new = iteEntry($project, '2026-09-20 08:00:00', '2026-09-20 09:00:00');
        $linesBefore = count(iteLines($invoice));

        $errors = iteRefused(fn () => app(AddEntriesToDraft::class)->handle($this->admin, $invoice, [$new->id]));

        expect($errors['currency'][0] ?? '')->toContain('EUR')->toContain('13. 10. 2026')
            ->and(iteEntryFresh($new)->invoice_id)->toBeNull()
            ->and(iteEntryFresh($new)->billing_state)->toBe(BillingState::Unbilled)
            ->and(count(iteLines($invoice)))->toBe($linesBefore);
    });
});

/**
 * A fixed-price project of 50 000 with its finished entries of September.
 *
 * @return array{project: Project, entries: list<TimeEntry>}
 */
function iteFixedProject(Client $client): array
{
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Fixed project',
        'key' => Canary::projectKey(),
        'billing_type' => 'fixed_price',
        'fixed_price' => '50000',
        'hourly_rate' => null,
    ]);

    return ['project' => $project, 'entries' => [
        iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00'),
        iteEntry($project, '2026-09-11 08:00:00', '2026-09-11 10:00:00'),
    ]];
}

describe('a fixed amount on an open issued document', function (): void {
    it('appends one fixed line and bills the fixed-price time at once without a rate snapshot', function (): void {
        $client = InvoiceFixtures::client();
        $hourly = iteProject($client);
        iteEntry($hourly, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $invoice = iteIssued($client, [$hourly]);
        ['project' => $fixed, 'entries' => $entries] = iteFixedProject($client);
        $pdfBefore = iteAdmin($invoice)->pdf_document_id;

        $item = app(AddFixedAmount::class)->handle($this->admin, $invoice, ['project_id' => $fixed->id, 'amount' => '1000', 'link_time' => true]);

        $lines = iteLines($invoice);

        expect($item->unit)->toBe('paušál')
            ->and($lines)->toHaveCount(2)
            ->and($lines[1]->line_total_minor)->toBe(100000)
            ->and($lines[1]->source?->price_manual)->toBeTrue()
            ->and(iteRow($invoice)->total_minor)->toBe(300000)
            ->and(iteAdmin($invoice)->pdf_document_id)->not->toBe($pdfBefore);

        foreach ($entries as $entry) {
            $fresh = iteEntryFresh($entry);

            expect($fresh->billing_state)->toBe(BillingState::Billed)
                ->and($fresh->invoice_id)->toBe($invoice->id)
                ->and($fresh->billed_rate_minor)->toBeNull()
                ->and($fresh->billed_amount_minor)->toBeNull();
        }
    });

    it('leaves the hours unbilled without link_time', function (): void {
        $client = InvoiceFixtures::client();
        $hourly = iteProject($client);
        iteEntry($hourly, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $invoice = iteIssued($client, [$hourly]);
        ['project' => $fixed, 'entries' => $entries] = iteFixedProject($client);

        app(AddFixedAmount::class)->handle($this->admin, $invoice, ['project_id' => $fixed->id, 'amount' => '500']);

        expect(iteLines($invoice))->toHaveCount(2)
            ->and(iteEntryFresh($entries[0])->billing_state)->toBe(BillingState::Unbilled)
            ->and(iteEntryFresh($entries[0])->invoice_id)->toBeNull();
    });
});

describe('where the three Actions refuse', function (): void {
    /**
     * The three calls on a document, each with entries of the client prepared by the caller.
     *
     * @return array<string, Closure(): void>
     */
    function iteCalls(Invoice $document, TimeEntry $free, TimeEntry $held, Project $fixed): array
    {
        return [
            'add' => static fn () => app(AddEntriesToDraft::class)->handle(test()->admin, $document, [$free->id]),
            'release' => static fn () => app(ReleaseEntries::class)->handle(test()->admin, $document, [$held->id]),
            'fixed' => static fn () => app(AddFixedAmount::class)->handle(test()->admin, $document, ['project_id' => $fixed->id, 'amount' => '100', 'link_time' => true]),
        ];
    }

    /**
     * Asserts that all three refuse with the reason and that no entry and no line moved.
     */
    function iteExpectRefused(Invoice $document, string $reason, TimeEntry $free, TimeEntry $held, Project $fixed, TimeEntry $fixedEntry): void
    {
        $linesBefore = count(iteLines($document));
        $heldBefore = iteEntryFresh($held)->getAttributes();

        foreach (iteCalls($document, $free, $held, $fixed) as $name => $call) {
            expect(iteRefused($call))->toBe(['document' => [__('invoices.errors.'.$reason)]], $name);
        }

        expect(count(iteLines($document)))->toBe($linesBefore)
            ->and(iteEntryFresh($free)->invoice_id)->toBeNull()
            ->and(iteEntryFresh($fixedEntry)->invoice_id)->toBeNull()
            ->and(iteEntryFresh($held)->getAttributes())->toBe($heldBefore);
    }

    it('refuses a locked invoice, a credit note, a cancelled invoice and a converted proforma', function (): void {
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        $held = iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $locked = InvoiceFixtures::lock(iteIssued($client, [$project]));

        // Free time that no refused call may take, created after the issue so the document does not hold it.
        ['project' => $fixed, 'entries' => [$fixedEntry]] = iteFixedProject($client);
        $free = iteEntry($project, '2026-09-20 08:00:00', '2026-09-20 09:00:00');
        iteExpectRefused($locked, 'document_locked', $free, $held, $fixed, $fixedEntry);

        $cancelledClient = InvoiceFixtures::client();
        $cancelledProject = iteProject($cancelledClient);
        $cancelledHeld = iteEntry($cancelledProject, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $cancelled = iteIssued($cancelledClient, [$cancelledProject]);
        $credit = InvoiceFixtures::stornoed($cancelled);

        // The storno released nothing here (the fixture only writes the credit note), so the entry is still billed.
        iteExpectRefused($cancelled, 'cancelled_not_editable', $free, $cancelledHeld, $fixed, $fixedEntry);
        iteExpectRefused($credit, 'credit_note_locked', $free, $cancelledHeld, $fixed, $fixedEntry);

        $proformaClient = InvoiceFixtures::client();
        $proformaProject = iteProject($proformaClient);
        $proformaHeld = iteEntry($proformaProject, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $proforma = app(IssueDocument::class)->handle($this->admin, app(CreateDraft::class)->handle($this->admin, [
            'client_id' => $proformaClient->id,
            'kind' => InvoiceKind::Proforma->value,
            'source' => 'time',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-30',
            'project_ids' => [$proformaProject->id],
            'without_project' => false,
            'grouping' => 'project',
        ]));
        app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '1000.00', 'paid_on' => '2026-10-13']);
        app(ConvertProforma::class)->handle($this->admin, $proforma);

        iteExpectRefused($proforma, 'converted_not_editable', $free, $proformaHeld, $fixed, $fixedEntry);
    });
});

describe('the issued editor', function (): void {
    it('offers the buttons that add time and a fixed amount for an open document', function (): void {
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $invoice = iteIssued($client, [$project]);

        $editor = Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible(TestAction::make('addTime')->schemaComponent('itemActions', schema: 'form'))
            ->assertActionVisible(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'))
            ->assertSee('Přidat nový čas')
            ->assertSee('Přidat pevnou částku');

        // The tab of the linked time is the only one under the editor of an issued document, titled as on the detail.
        $page = $editor->instance();
        assert($page instanceof EditInvoice);

        expect(array_values($page->getRelationManagers()))->toBe([InvoiceEntriesRelationManager::class])
            ->and(InvoiceEntriesRelationManager::getTitle($invoice, EditInvoice::class))->toBe('Navázaný čas');
    });

    it('adds a fixed amount from the editor and says that the PDF was made again', function (): void {
        $client = InvoiceFixtures::client();
        $hourly = iteProject($client);
        iteEntry($hourly, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $invoice = iteIssued($client, [$hourly]);
        ['project' => $fixed] = iteFixedProject($client);

        Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'), ['project_id' => $fixed->id, 'amount' => '250', 'description' => 'Example fixed part', 'link_time' => false])
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()->success()->title('Pevná částka byla přidána')->body('Změny jsou uložené. PDF bylo vytvořeno znovu, doklad nebyl odeslán.'));

        expect(iteLines($invoice))->toHaveCount(2)
            ->and(iteLines($invoice)[1]->description)->toBe('Example fixed part')
            ->and(iteRow($invoice)->total_minor)->toBe(100000 + 25000);
    });
});

describe('the time entry screen and the invoice that bills the time', function (): void {
    it('points the Admin at the invoice, then shows the released entry as unbilled and editable', function (): void {
        $client = InvoiceFixtures::client();
        $project = iteProject($client);
        $entry = iteEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $invoice = iteIssued($client, [$project]);
        $number = (string) iteRow($invoice)->number;
        $expected = 'Záznam patří k vystavené faktuře '.$number.'. Uvolnit ho lze na faktuře, dokud není odeslaná, nebo dobropisem.';

        // The screen of the entry still refuses to unbill invoice-billed time, and says where it can be released.
        expect(fn () => app(CancelEntriesBilling::class)->handle($this->admin, [$entry->id]))->toThrow(DomainException::class, $expected)
            ->and(TimeEntryResource::canEdit(iteEntryFresh($entry)))->toBeFalse();

        // The same text for a locked invoice.
        $locked = InvoiceFixtures::lock($invoice);

        expect(iteRow($locked)->lock_state->value)->toBe('locked')
            ->and(fn () => app(CancelEntriesBilling::class)->handle($this->admin, [$entry->id]))->toThrow(DomainException::class, $expected);

        // Unlocked and released on the invoice, the entry is free and editable on the time entry screen.
        app(UnlockInvoice::class)->handle($this->admin, $locked, 'Example reason to release the time');
        app(ReleaseEntries::class)->handle($this->admin, $invoice, [$entry->id]);

        $fresh = iteEntryFresh($entry);

        expect($fresh->billing_state)->toBe(BillingState::Unbilled)
            ->and($fresh->invoice_id)->toBeNull()
            ->and(TimeEntryResource::canEdit($fresh))->toBeTrue();
    });
});
