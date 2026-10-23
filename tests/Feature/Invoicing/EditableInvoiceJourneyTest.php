<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\AddEntriesToDraft;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\ReleaseEntries;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;

/*
 * The whole rule of Phase 10.1 in one readable test (IN-03, IN-11 to IN-15; D-01, D-03, D-05, D-07, D-10, D-13): an
 * invoice is issued from tracked time and stays open, so it can be corrected without any trace in the audit log; the
 * first successful send locks it and an edit is refused; the Admin unlocks it with a reason, corrects the lines, the
 * header and the linked time, each save leaving one row that names only what changed; the next successful send locks it
 * again. After every save the stored PDF prints the current values and the card link offers the current amount. The
 * gateway is the in-memory fake and the e-mail goes to the array transport; every name, amount and date is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 09:30 in Prague: the day of the issue.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    Mail::getSymfonyTransport()->flush();
});

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function editJourneySystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A project of the client with an hourly rate of 1 000 in the currency of the client.
 */
function editJourneyProject(Client $client): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Alpha project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1000',
    ]);
}

/**
 * A finished entry of the project, from UTC instants.
 */
function editJourneyEntry(Project $project, string $start, string $end): TimeEntry
{
    $factory = TimeEntry::factory()->forProject($project);

    return editJourneySystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
    ])->id));
}

function editJourneyRow(Invoice $invoice): Invoice
{
    return editJourneySystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

function editJourneyEntryFresh(TimeEntry $entry): TimeEntry
{
    return editJourneySystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

/**
 * The stored lines of the document in order.
 *
 * @return list<InvoiceItem>
 */
function editJourneyLines(Invoice $invoice): array
{
    return array_values(editJourneySystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get()->all()));
}

/**
 * The rows of one audit event of the document, oldest first.
 *
 * @return list<Activity>
 */
function editJourneyActivity(Invoice $invoice, string $event): array
{
    return Activity::query()
        ->where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * The bytes of the one stored PDF of the document.
 */
function editJourneyPdf(Invoice $invoice): string
{
    return editJourneySystem(static function () use ($invoice): string {
        $documentId = DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id');
        $media = Document::query()->whereKey($documentId)->firstOrFail()->getFirstMedia(Document::MEDIA_COLLECTION);

        return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    });
}

/**
 * The text the stored PDF prints, white space reduced to one space, or null when Ghostscript is not installed.
 */
function editJourneyPdfText(Invoice $invoice): ?string
{
    return PdfText::flat(editJourneyPdf($invoice));
}

/**
 * A printed amount as Ghostscript reads it back: white space (the no-break spaces of the format) reduced to one space.
 */
function editJourneyPrinted(Invoice $invoice): string
{
    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', editJourneyRow($invoice)->total()->format('cs')));
}

/**
 * The one live card link of the document, or null.
 */
function editJourneyLiveLink(Invoice $invoice): ?PaymentLink
{
    return editJourneySystem(static fn (): ?PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->get()->first(static fn (PaymentLink $link): bool => $link->isLive()));
}

/**
 * Queues the e-mail of the document with its default subject and body; the queue is synchronous in tests, so the job
 * runs at once and a successful send locks the document.
 */
function editJourneySend(Invoice $invoice): InvoiceEmail
{
    $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);

    return app(QueueInvoiceEmail::class)->handle(test()->admin, $invoice, [
        'recipient' => 'invoice@example.com',
        'subject' => $defaults['subject'],
        'body' => $defaults['body'],
        'attach_work_report' => false,
    ]);
}

it('walks an invoice from issue through a send, a refused edit and an unlock to a corrected and resent document', function (): void {
    // --- Issue: an invoice from three hours of September time, card payment on ---------------------------------------
    $client = CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']);
    $project = editJourneyProject($client);
    $first = editJourneyEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
    $second = editJourneyEntry($project, '2026-09-11 08:00:00', '2026-09-11 09:00:00');

    $draft = app(CreateDraft::class)->handle($this->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
        'currency' => 'CZK',
    ]);
    $invoice = app(IssueDocument::class)->handle($this->admin, $draft);

    expect($invoice->isIssued())->toBeTrue()
        ->and(editJourneyRow($invoice)->isLocked())->toBeFalse()
        ->and(editJourneyRow($invoice)->total_minor)->toBe(300000)
        ->and(editJourneyLiveLink($invoice)?->amount_minor)->toBe(300000);

    // --- 1. While it is open an edit leaves no audit row: the model history is that of a draft ---------------------
    $editor = Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()]);
    [$key] = array_map('strval', array_keys($editor->get('data.items')));

    $editor->set("data.items.{$key}.description", 'Example work, corrected text')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $invoice]));

    expect(editJourneyLines($invoice)[0]->description)->toBe('Example work, corrected text')
        ->and(editJourneyActivity($invoice, 'invoice_edited'))->toBe([])
        ->and(editJourneyActivity($invoice, 'invoice_locked'))->toBe([])
        ->and(editJourneyRow($invoice)->isLocked())->toBeFalse();

    $text = editJourneyPdfText($invoice);

    if ($text !== null) {
        expect($text)->toContain('Example work, corrected text');
    }

    // --- 2. The first successful send locks the document ----------------------------------------------------------------
    $sent = editJourneySend($invoice);

    expect(editJourneySystem(static fn (): InvoiceEmailState => InvoiceEmail::query()->findOrFail($sent->id)->state))->toBe(InvoiceEmailState::Sent)
        ->and(iterator_to_array(Mail::getSymfonyTransport()->messages()))->toHaveCount(1)
        ->and(editJourneyRow($invoice)->isLocked())->toBeTrue()
        ->and(editJourneyActivity($invoice, 'invoice_locked'))->toHaveCount(1);

    // --- 3. An edit is refused: the address redirects with the reason, the Action throws and nothing changes -------------
    $before = editJourneyRow($invoice);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $invoice]))
        ->assertNotified(__('invoices.errors.document_locked'));

    $refused = [];

    try {
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['due_date' => '2026-12-31']);
    } catch (ValidationException $exception) {
        $refused = $exception->errors();
    }

    expect($refused)->toBe(['document' => [__('invoices.errors.document_locked')]])
        ->and(editJourneyRow($invoice)->due_date?->format('Y-m-d'))->toBe($before->due_date?->format('Y-m-d'))
        ->and(editJourneyActivity($invoice, 'invoice_edited'))->toBe([]);

    // --- 4. The Admin unlocks it with a reason (the unlock action of the detail runs UnlockInvoice), which the history keeps ---
    $reason = 'Example reason, the quantity must change';

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction(TestAction::make('unlock')->schemaComponent('locked', schema: 'infolist'), ['reason' => $reason])
        ->assertHasNoActionErrors();

    $unlocked = editJourneyActivity($invoice, 'invoice_unlocked');

    expect(editJourneyRow($invoice)->isLocked())->toBeFalse()
        ->and($unlocked)->toHaveCount(1)
        ->and($unlocked[0]->causer_id)->toBe($this->admin->id)
        ->and($unlocked[0]->attribute_changes?->all()['attributes']['unlock_reason'] ?? null)->toBe($reason);

    // --- 5. Items and header in one save: one row with the changed keys, the PDF and the card link follow ---------------
    $line = editJourneyLines($invoice)[0];
    $callsBefore = $this->stripe->createCalls();

    app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
        'items' => [[
            'id' => $line->id,
            'description' => $line->description,
            'quantity' => '3.5',
            'unit' => $line->unit,
            'unit_price' => $line->unitPrice()->toMajor(),
        ]],
        'due_date' => '2026-11-30',
    ]);

    $edited = editJourneyActivity($invoice, 'invoice_edited');
    $changes = $edited[0]->attribute_changes?->all() ?? [];

    expect($edited)->toHaveCount(1)
        ->and($edited[0]->causer_id)->toBe($this->admin->id)
        ->and($changes['attributes']['due_date'] ?? null)->toBe('2026-11-30')
        ->and($changes['old']['due_date'] ?? null)->toBe($before->due_date?->format('Y-m-d'))
        ->and($changes['attributes']['total_minor'] ?? null)->toBe(350000)
        ->and($changes['old']['total_minor'] ?? null)->toBe(300000)
        ->and(array_keys($changes['attributes']))->not->toContain('currency', 'language', 'issue_date')
        ->and(editJourneyRow($invoice)->isLocked())->toBeFalse()
        ->and(editJourneyLiveLink($invoice)?->state)->toBe(PaymentLinkState::Active)
        ->and(editJourneyLiveLink($invoice)->amount_minor)->toBe(350000)
        ->and($this->stripe->createCalls())->toBe($callsBefore + 1);

    $text = editJourneyPdfText($invoice);

    if ($text !== null) {
        expect($text)->toContain(editJourneyPrinted($invoice))
            ->and($text)->toContain('30. 11. 2026');
    }

    // --- 6. Time tracked after the issue is added, one row more -----------------------------------------------------------
    $late = editJourneyEntry($project, '2026-09-20 08:00:00', '2026-09-20 08:30:00');

    expect(app(AddEntriesToDraft::class)->handle($this->admin, $invoice, [$late->id]))->toBe(['entries' => 1, 'items' => 1])
        ->and(editJourneyEntryFresh($late)->billing_state)->toBe(BillingState::Billed)
        ->and(editJourneyEntryFresh($late)->invoice_id)->toBe($invoice->id)
        ->and(editJourneyRow($invoice)->total_minor)->toBe(400000)
        ->and(editJourneyActivity($invoice, 'invoice_edited'))->toHaveCount(2)
        ->and(editJourneyLiveLink($invoice)?->amount_minor)->toBe(400000);

    $text = editJourneyPdfText($invoice);

    if ($text !== null) {
        expect($text)->toContain(editJourneyPrinted($invoice));
    }

    // --- 7. Time that does not belong on the invoice is released, one row more --------------------------------------------
    expect(app(ReleaseEntries::class)->handle($this->admin, $invoice, [$second->id]))->toBe(1)
        ->and(editJourneyEntryFresh($second)->billing_state)->toBe(BillingState::Unbilled)
        ->and(editJourneyEntryFresh($second)->invoice_id)->toBeNull()
        ->and(editJourneyEntryFresh($first)->invoice_id)->toBe($invoice->id)
        ->and(editJourneyActivity($invoice, 'invoice_edited'))->toHaveCount(3)
        ->and(editJourneyRow($invoice)->isLocked())->toBeFalse()
        ->and(editJourneyActivity($invoice, 'invoice_locked'))->toHaveCount(1)
        ->and(iterator_to_array(Mail::getSymfonyTransport()->messages()))->toHaveCount(1);

    // --- 8. The resend locks it again; the history holds both locks, the unlock and the three edits --------------------
    $again = editJourneySend($invoice);

    expect(editJourneySystem(static fn (): InvoiceEmailState => InvoiceEmail::query()->findOrFail($again->id)->state))->toBe(InvoiceEmailState::Sent)
        ->and(iterator_to_array(Mail::getSymfonyTransport()->messages()))->toHaveCount(2)
        ->and(editJourneyRow($invoice)->isLocked())->toBeTrue()
        ->and(editJourneyActivity($invoice, 'invoice_locked'))->toHaveCount(2)
        ->and(editJourneyActivity($invoice, 'invoice_unlocked'))->toHaveCount(1)
        ->and(editJourneyActivity($invoice, 'invoice_edited'))->toHaveCount(3);

    $refused = [];

    try {
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['note' => 'Example note after the resend']);
    } catch (ValidationException $exception) {
        $refused = $exception->errors();
    }

    expect($refused)->toBe(['document' => [__('invoices.errors.document_locked')]])
        ->and(editJourneyRow($invoice)->note)->toBeNull();
});
