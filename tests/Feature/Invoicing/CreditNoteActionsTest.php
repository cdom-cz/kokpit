<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The storno and the partial credit note from the panel (IN-10, IN-01; D-14, D-16; UI-SPEC E2, E6, E7, N8): the actions
 * of Další akce, their confirmations and field errors, and what they leave behind. Every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

function creditPage(Invoice $invoice): Testable
{
    return Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * An issued invoice of 5 hours of September time at 1 200 an hour, one line, in two entries of 2 h and 3 h.
 *
 * @return array{invoice: Invoice, entries: list<TimeEntry>}
 */
function creditActionInvoice(): array
{
    $client = InvoiceFixtures::client();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Credit action project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1200',
    ]);

    $entries = [];

    foreach ([[1, 7200], [2, 10800]] as [$day, $seconds]) {
        $start = CarbonImmutable::parse('2026-09-0'.$day.' 08:00:00', 'UTC');
        $factory = TimeEntry::factory()->forProject($project);
        $entries[] = app(PartnerContext::class)->runAsSystem(fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => test()->admin->id,
            'started_at' => $start,
            'ended_at' => $start->addSeconds($seconds),
        ])->id));
    }

    $draft = app(CreateDraft::class)->handle(test()->admin, [
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

    return ['invoice' => app(IssueDocument::class)->handle(test()->admin, $draft), 'entries' => $entries];
}

function creditActionItemId(Invoice $invoice): string
{
    return app(PartnerContext::class)->runAsSystem(static fn (): string => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->firstOrFail()->id);
}

/**
 * The key the dialog gave the row of the only line of the invoice (the repeater keys its rows itself).
 */
function creditActionRowKey(Testable $modal): string
{
    $key = array_key_first($modal->get('mountedActions.0.data.lines'));
    assert(is_string($key));

    return $key;
}

/**
 * @return list<TimeEntry>
 */
function creditActionFreshEntries(array $entries): array
{
    return array_map(
        static fn (TimeEntry $entry): TimeEntry => app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id)),
        $entries,
    );
}

describe('the tracer: the storno from Další akce', function (): void {
    it('stornoes a paid invoice, opens the credit note with the e-mail dialog requested and returns the time', function (): void {
        ['invoice' => $invoice, 'entries' => $entries] = creditActionInvoice();
        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '1000.00', 'paid_on' => '2026-10-13']);

        $page = creditPage($invoice)
            ->assertSee('Další akce')
            ->assertActionVisible('creditItems')
            ->assertActionHasLabel('creditItems', 'Dobropis na položky')
            ->assertActionVisible('storno')
            ->assertActionHasLabel('storno', 'Stornovat')
            ->assertActionHasColor('storno', 'danger')
            ->mountAction('storno')
            ->assertMountedActionModalSee([
                'Stornovat fakturu '.$invoice->number.'?',
                'Vystavíme dobropis na celou fakturu (vlastní číselná řada) a fakturu označíme jako stornovanou. Záznamy času se vrátí mezi nevyfakturované.',
                'Faktura je zaplacená ('.Money::ofMinor(100000, 'CZK')->format('cs').'). Platba a příjem zůstanou beze změny. Vrácení peněz a výdaj zaznamenejte ručně.',
                'Vystavit dobropis a stornovat',
                'Nestornovat',
            ])
            ->callMountedAction();

        $credit = Invoice::query()->where('kind', InvoiceKind::CreditNote->value)->sole();

        $page->assertNotified('Dobropis '.$credit->number.' byl vystaven')
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $credit, 'send' => 1]));

        expect($credit->cancels_invoice)->toBeTrue()
            ->and($credit->credited_invoice_id)->toBe($invoice->id)
            ->and($credit->total_minor)->toBe(-$invoice->total_minor);

        creditPage($invoice)
            ->assertSee('Stornováno')
            ->assertSee('K dokladu existuje dobropis')
            ->assertSee($credit->number)
            ->assertSee('Faktura byla stornována, ale platba zůstává zaevidovaná.')
            ->assertActionHidden('storno')
            ->assertActionHidden('creditItems')
            ->assertDontSee('Další akce');

        foreach (creditActionFreshEntries($entries) as $entry) {
            expect($entry->billing_state)->toBe(BillingState::Unbilled)
                ->and($entry->invoice_id)->toBeNull();
        }
    });

    it('shows no paid warning on an unpaid invoice', function (): void {
        ['invoice' => $invoice] = creditActionInvoice();

        creditPage($invoice)
            ->mountAction('storno')
            ->assertMountedActionModalSee('Stornovat fakturu '.$invoice->number.'?')
            ->assertMountedActionModalDontSee('Faktura je zaplacená');
    });
});

describe('the partial credit note', function (): void {
    it('lists the lines, shows the live negative total and issues a credit note while the time stays billed', function (): void {
        ['invoice' => $invoice, 'entries' => $entries] = creditActionInvoice();

        $modal = creditPage($invoice)
            ->mountAction('creditItems')
            ->assertMountedActionModalSee([
                'Dobropis k faktuře '.$invoice->number,
                'Původní množství',
                'Množství k dobropisu',
                'Záznamy času zůstanou vyfakturované.',
                'Vystavit dobropis',
                'Nevystavovat',
                'Dobropis celkem',
            ]);

        // The default is the full remaining quantity, 5 h at 1 200: the whole invoice, negative.
        $modal->assertMountedActionModalSee('Dobropis celkem: '.Money::ofMinor(-600000, 'CZK')->format('cs'));

        $key = creditActionRowKey($modal);

        $modal->fillForm(['lines' => [$key => ['quantity' => '2']]], 'mountedActionSchema0')
            ->assertMountedActionModalSee('Dobropis celkem: '.Money::ofMinor(-240000, 'CZK')->format('cs'));

        $page = $modal->callMountedAction();

        $credit = Invoice::query()->where('kind', InvoiceKind::CreditNote->value)->sole();

        $page->assertNotified('Dobropis '.$credit->number.' byl vystaven')
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $credit, 'send' => 1]));

        expect($credit->cancels_invoice)->toBeFalse()
            ->and($credit->total_minor)->toBe(-240000);

        // The invoice is still alive and its time still billed (D-14).
        creditPage($invoice)
            ->assertDontSee('Stornováno')
            ->assertSee('K dokladu existuje dobropis')
            ->assertActionVisible('storno');

        foreach (creditActionFreshEntries($entries) as $entry) {
            expect($entry->billing_state)->toBe(BillingState::Billed);
        }
    });

    it('shows the amount of a deselected row as a dash and the total as zero', function (): void {
        ['invoice' => $invoice] = creditActionInvoice();

        $modal = creditPage($invoice)->mountAction('creditItems');
        $key = creditActionRowKey($modal);

        $modal->fillForm(['lines' => [$key => ['selected' => false]]], 'mountedActionSchema0')
            ->assertMountedActionModalSee('Dobropis celkem: '.Money::zero('CZK')->format('cs'));
    });

    it('refuses a quantity above the original under the quantity field and issues nothing', function (): void {
        ['invoice' => $invoice] = creditActionInvoice();

        $modal = creditPage($invoice)->mountAction('creditItems');
        $key = creditActionRowKey($modal);

        $modal->fillForm(['lines' => [$key => ['quantity' => '6']]], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasActionErrors(['lines.'.$key.'.quantity' => 'Množství k dobropisu nesmí být vyšší než původní množství.'])
            ->assertNoRedirect();

        expect(Invoice::query()->where('kind', InvoiceKind::CreditNote->value)->exists())->toBeFalse();
    });

    it('refuses a quantity that credit notes issued meanwhile left too little for', function (): void {
        ['invoice' => $invoice] = creditActionInvoice();
        $itemId = creditActionItemId($invoice);

        // The dialog opens with 5 h creditable; elsewhere 4 h are credited before it is submitted.
        $modal = creditPage($invoice)->mountAction('creditItems');
        $key = creditActionRowKey($modal);

        app(IssueCreditNote::class)->partial($this->admin, $invoice, [$itemId => '4']);

        $modal->callMountedAction()
            ->assertHasActionErrors(['lines.'.$key.'.quantity'])
            ->assertNoRedirect();

        expect(Invoice::query()->where('kind', InvoiceKind::CreditNote->value)->count())->toBe(1);
    });

    it('shows the domain refusal of a quantity under its row when the form rule did not see it', function (): void {
        ['invoice' => $invoice] = creditActionInvoice();
        $itemId = creditActionItemId($invoice);

        $modal = creditPage($invoice)->mountAction('creditItems');
        $key = creditActionRowKey($modal);

        // A fake that refuses like the Action does, so the mapping under the dialog is proven on its own.
        app()->bind(IssueCreditNote::class, static fn (): object => new class($itemId)
        {
            public function __construct(private readonly string $itemId) {}

            /** @return array<string, mixed> */
            public function creditable(): array
            {
                return [];
            }

            /** @return array<string, mixed> */
            public function preview(): array
            {
                return [];
            }

            public function partial(): never
            {
                throw ValidationException::withMessages(['lines.'.$this->itemId => __('invoices.errors.credit_quantity')]);
            }
        });

        $modal->callMountedAction()
            ->assertHasActionErrors(['lines.'.$key.'.quantity' => 'Množství k dobropisu nesmí být vyšší než původní množství.'])
            ->assertNoRedirect();
    });

    it('refuses an empty selection under the table', function (): void {
        ['invoice' => $invoice] = creditActionInvoice();

        $modal = creditPage($invoice)->mountAction('creditItems');
        $key = creditActionRowKey($modal);

        $modal->fillForm(['lines' => [$key => ['selected' => false]]], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasActionErrors(['lines' => 'Vyberte alespoň jednu položku.'])
            ->assertNoRedirect();

        expect(Invoice::query()->where('kind', InvoiceKind::CreditNote->value)->exists())->toBeFalse();
    });

    it('credits nothing for a line of another invoice that a forged state names', function (): void {
        ['invoice' => $invoice] = creditActionInvoice();
        $other = InvoiceFixtures::issued(InvoiceFixtures::client());
        $foreignId = creditActionItemId($other);

        $modal = creditPage($invoice)->mountAction('creditItems');
        $key = creditActionRowKey($modal);

        $modal->fillForm(['lines' => [$key => ['id' => $foreignId, 'selected' => true, 'quantity' => '1']]], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasActionErrors()
            ->assertNoRedirect();

        expect(Invoice::query()->where('kind', InvoiceKind::CreditNote->value)->exists())->toBeFalse();
    });
});

describe('where the actions are not offered', function (): void {
    it('shows neither on a credit note nor on a proforma, and answers a Partner with 403', function (): void {
        ['invoice' => $invoice] = creditActionInvoice();
        $credit = app(IssueCreditNote::class)->partial($this->admin, $invoice, [creditActionItemId($invoice) => '1']);
        $proforma = InvoiceFixtures::issued($invoice->client, [], InvoiceKind::Proforma);

        creditPage($credit)
            ->assertDontSee('Další akce')
            ->assertActionHidden('storno')
            ->assertActionHidden('creditItems');

        creditPage($proforma)
            ->assertDontSee('Další akce')
            ->assertActionHidden('storno')
            ->assertActionHidden('creditItems');

        $this->actingAs(Canary::partnerFor($invoice->client_id));

        creditPage($invoice)->assertForbidden();
    });
});
