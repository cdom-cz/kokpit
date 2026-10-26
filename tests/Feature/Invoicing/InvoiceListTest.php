<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The document list as the overdue overview (IN-01, IN-05, IN-06; D-18, D-20, ruling R-19-3, UI-SPEC Surface A): the
 * tabs with their counts, the derived states and overdue lines, the filters, the default order, the row actions and
 * the empty states. The states themselves are tested in ReceivablesReaderTest; here the screen follows them. All
 * documents are issued on 2026-10-01 and the clock is then moved, so the due date and the days late are exact. Every
 * name and amount is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Prague'));
});

/**
 * An issued document of a new client (or the given one) with the payment terms, issued on the day the clock stands on.
 *
 * @param  array<string, mixed>  $clientOverrides
 */
function listIssued(string $amount = '10000.00', int $terms = 14, InvoiceKind $kind = InvoiceKind::Invoice, array $clientOverrides = [], ?Client $client = null): Invoice
{
    $client ??= InvoiceFixtures::client(['payment_terms_days' => $terms, ...$clientOverrides]);

    return InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', $amount)], $kind);
}

/**
 * A payment of the document as the Admin; the clock must not stand before the payment date.
 */
function listPay(Invoice $invoice, string $amount, string $paidOn = '2026-10-10'): void
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    app(RecordPayment::class)->handle($admin, $invoice, ['amount' => $amount, 'paid_on' => $paidOn]);
}

/**
 * An invoice issued from a paid proforma: a draft of the same client with the link set as system, then issued.
 */
function listInvoiceFromProforma(Invoice $proforma): Invoice
{
    $client = Client::query()->whereKey($proforma->client_id)->firstOrFail();
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '10000.00')]);

    app(PartnerContext::class)->runAsSystem(static function () use ($draft, $proforma): void {
        $draft->forceFill(['proforma_id' => $proforma->id])->save();
    });

    return app(IssueDocument::class)->handle(Canary::admin(), $draft->refresh());
}

/**
 * A mixed portfolio issued on 2026-10-01 and read on 2026-10-20: a draft, an unpaid, a partially paid overdue, a
 * plain overdue, a paid, a cancelled invoice with its credit note, a paid proforma still waiting for its invoice and
 * a paid proforma that has its invoice.
 *
 * @return array<string, Invoice>
 */
function listPortfolio(): array
{
    $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '1000.00')]);
    $unpaid = listIssued(terms: 30);
    $partial = listIssued();
    $overdue = listIssued();
    $paid = listIssued();
    $cancelled = listIssued();
    $creditNote = InvoiceFixtures::stornoed($cancelled);
    $awaiting = listIssued(kind: InvoiceKind::Proforma);
    $converted = listIssued(kind: InvoiceKind::Proforma, terms: 30);

    test()->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Europe/Prague'));

    listPay($partial, '3000');
    listPay($paid, '10000');
    listPay($awaiting, '10000');
    listPay($converted, '10000');

    $fromProforma = listInvoiceFromProforma($converted);
    test()->actingAs(Canary::admin());

    return [
        'draft' => $draft,
        'unpaid' => $unpaid,
        'partial' => $partial,
        'overdue' => $overdue,
        'paid' => $paid,
        'cancelled' => $cancelled,
        'creditNote' => $creditNote,
        'awaiting' => $awaiting,
        'converted' => $converted,
        'fromProforma' => $fromProforma,
    ];
}

/**
 * The records of a tab, as the list shows them.
 *
 * @param  list<string>  $names
 */
function listTabShows(string $tab, array $names, array $portfolio): void
{
    $others = array_values(array_diff(array_keys($portfolio), $names));

    Livewire::test(ListInvoices::class)
        ->set('activeTab', $tab)
        ->assertCanSeeTableRecords(array_map(static fn (string $name): Invoice => $portfolio[$name], $names))
        ->assertCanNotSeeTableRecords(array_map(static fn (string $name): Invoice => $portfolio[$name], $others));
}

describe('the tracer: the tabs of the list', function (): void {
    it('lists exactly its documents under each tab', function (): void {
        $portfolio = listPortfolio();

        listTabShows('all', array_keys($portfolio), $portfolio);
        listTabShows('drafts', ['draft'], $portfolio);
        listTabShows('unpaid', ['unpaid', 'fromProforma'], $portfolio);
        listTabShows('overdue', ['partial', 'overdue'], $portfolio);
        listTabShows('paid', ['paid', 'awaiting', 'converted'], $portfolio);
        listTabShows('cancelled', ['cancelled'], $portfolio);
        listTabShows('awaiting_invoice', ['awaiting'], $portfolio);
    });

    it('shows the count of every tab and colours the attention tabs red', function (): void {
        listPortfolio();

        $tabs = Livewire::test(ListInvoices::class)->instance()->getTabs();

        expect(array_keys($tabs))->toBe(['all', 'drafts', 'unpaid', 'overdue', 'paid', 'cancelled', 'awaiting_invoice'])
            ->and($tabs['all']->getBadge())->toEqual(10)
            ->and($tabs['drafts']->getBadge())->toEqual(1)
            ->and($tabs['unpaid']->getBadge())->toEqual(2)
            ->and($tabs['overdue']->getBadge())->toEqual(2)
            ->and($tabs['paid']->getBadge())->toEqual(3)
            ->and($tabs['cancelled']->getBadge())->toEqual(1)
            ->and($tabs['awaiting_invoice']->getBadge())->toEqual(1)
            ->and($tabs['overdue']->getBadgeColor())->toBe('danger')
            ->and($tabs['awaiting_invoice']->getBadgeColor())->toBe('danger')
            ->and($tabs['unpaid']->getBadgeColor())->toBe('gray');
    });

    it('drops the awaiting tab once the proforma has its invoice', function (): void {
        $portfolio = listPortfolio();
        $second = listIssued(kind: InvoiceKind::Proforma, terms: 30);
        listPay($second, '10000', '2026-10-20');

        expect(array_keys(Livewire::test(ListInvoices::class)->instance()->getTabs()))->toContain('awaiting_invoice');

        listInvoiceFromProforma($portfolio['awaiting']);
        listInvoiceFromProforma($second);

        expect(array_keys(Livewire::test(ListInvoices::class)->instance()->getTabs()))->not->toContain('awaiting_invoice');
    });
});

describe('the derived states in the rows', function (): void {
    it('shows an overdue row with its due date in danger text, the days late and the remaining amount', function (): void {
        $portfolio = listPortfolio();

        $component = Livewire::test(ListInvoices::class)
            ->set('activeTab', 'overdue')
            ->assertSee('Po splatnosti o 5 dní')
            ->assertSee('Částečně zaplaceno')
            ->assertTableColumnStateSet('remaining', Money::ofMinor(700000, 'CZK')->format('cs'), $portfolio['partial'])
            ->assertTableColumnStateSet('remaining', Money::ofMinor(1000000, 'CZK')->format('cs'), $portfolio['overdue'])
            ->assertTableColumnStateSet('status', InvoiceStatus::Overdue, $portfolio['overdue'])
            ->assertTableColumnHasDescription('due_date', 'Po splatnosti o 5 dní', $portfolio['overdue'], 'below')
            ->assertTableColumnHasDescription('status', 'Částečně zaplaceno', $portfolio['partial'], 'below')
            ->assertTableColumnDoesNotHaveDescription('status', 'Částečně zaplaceno', $portfolio['overdue'], 'below');

        $due = $component->instance()->getTable()->getColumn('due_date');

        expect($due->record($portfolio['overdue'])->getColor('2026-10-15'))->toBe('danger');
    });

    it('sums the overdue tab in CZK with the frozen rate and names the CZK of a foreign row', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-01', '25.000');
        $euro = listIssued('100.00', clientOverrides: ['currency' => 'EUR']);
        listIssued('10000.00');
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Europe/Prague'));

        // 100,00 EUR at the frozen rate 25 is 2 500,00 Kč, plus the 10 000,00 Kč invoice.
        Livewire::test(ListInvoices::class)
            ->set('activeTab', 'overdue')
            ->assertTableColumnStateSet('remaining', Money::ofMinor(10000, 'EUR')->format('cs'), $euro)
            ->assertSee('v Kč: '.Money::ofMinor(250000, 'CZK')->format('cs'))
            ->assertSee('Celkem v Kč')
            ->assertSee(Money::ofMinor(1250000, 'CZK')->format('cs'));
    });

    it('marks the e-mail state of a row', function (): void {
        $sent = listIssued();
        $failed = listIssued();
        $never = listIssued();
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client());

        $log = static function (Invoice $invoice, InvoiceEmailState $state, string $created): void {
            app(PartnerContext::class)->runAsSystem(static function () use ($invoice, $state, $created): void {
                (new InvoiceEmail)->forceFill([
                    'invoice_id' => $invoice->id,
                    'recipient' => 'jane@'.implode('.', ['example', 'com']),
                    'subject' => 'Example',
                    'body' => 'Example body',
                    'state' => $state,
                    'sent_at' => $state === InvoiceEmailState::Sent ? CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC') : null,
                    'created_by' => Canary::admin()->getKey(),
                    'created_at' => $created,
                    'updated_at' => $created,
                ])->save();
            });
        };

        $log($failed, InvoiceEmailState::Sent, '2026-10-01 08:00:00+00');
        $log($failed, InvoiceEmailState::Failed, '2026-10-01 09:00:00+00');
        $log($sent, InvoiceEmailState::Sent, '2026-10-01 08:00:00+00');

        Livewire::test(ListInvoices::class)
            ->assertTableColumnStateSet('email', 'sent', $sent)
            ->assertTableColumnStateSet('email', 'failed', $failed)
            ->assertTableColumnStateSet('email', 'none', $never)
            ->assertTableColumnStateSet('email', null, $draft);
    });
});

describe('filters', function (): void {
    it('narrows the rows by kind, client, issue date, currency and e-mail state', function (): void {
        $invoice = listIssued();
        $proforma = listIssued(kind: InvoiceKind::Proforma);
        InvoiceFixtures::cnbRate('EUR', '2026-10-01', '25.000');
        $euro = listIssued('100.00', clientOverrides: ['currency' => 'EUR']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Prague'));
        $later = listIssued();

        app(PartnerContext::class)->runAsSystem(static function () use ($invoice): void {
            (new InvoiceEmail)->forceFill([
                'invoice_id' => $invoice->id,
                'recipient' => 'jane@'.implode('.', ['example', 'com']),
                'subject' => 'Example',
                'body' => 'Example body',
                'state' => InvoiceEmailState::Failed,
                'created_by' => Canary::admin()->getKey(),
            ])->save();
        });

        Livewire::test(ListInvoices::class)
            ->filterTable('kind', 'proforma')
            ->assertCanSeeTableRecords([$proforma])
            ->assertCanNotSeeTableRecords([$invoice, $euro, $later])
            ->resetTableFilters()
            ->filterTable('client_id', $invoice->client_id)
            ->assertCanSeeTableRecords([$invoice])
            ->assertCanNotSeeTableRecords([$proforma, $euro, $later])
            ->resetTableFilters()
            ->filterTable('issued', ['from' => '2026-10-04', 'until' => '2026-10-31'])
            ->assertCanSeeTableRecords([$later])
            ->assertCanNotSeeTableRecords([$invoice, $proforma, $euro])
            ->resetTableFilters()
            ->filterTable('issued', ['from' => null, 'until' => '2026-10-01'])
            ->assertCanSeeTableRecords([$invoice, $proforma, $euro])
            ->assertCanNotSeeTableRecords([$later])
            ->resetTableFilters()
            ->filterTable('currency', 'EUR')
            ->assertCanSeeTableRecords([$euro])
            ->assertCanNotSeeTableRecords([$invoice, $proforma, $later])
            ->resetTableFilters()
            ->filterTable('email', 'failed')
            ->assertCanSeeTableRecords([$invoice])
            ->assertCanNotSeeTableRecords([$proforma, $euro, $later])
            ->resetTableFilters()
            ->filterTable('email', 'none')
            ->assertCanSeeTableRecords([$proforma, $euro, $later])
            ->assertCanNotSeeTableRecords([$invoice]);
    });
});

describe('sorting', function (): void {
    it('lists the oldest overdue document first under the overdue tab', function (): void {
        $later = listIssued(terms: 14);
        $earlier = listIssued(terms: 7);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Europe/Prague'));

        Livewire::test(ListInvoices::class)
            ->set('activeTab', 'overdue')
            ->assertCanSeeTableRecords([$earlier, $later], inOrder: true);
    });
});

describe('row actions', function (): void {
    it('deletes a draft through the Action, releases its time and shows the toast', function (): void {
        $client = InvoiceFixtures::client();
        $project = app(CreateProject::class)->handle($client, [
            'name' => 'Example project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '900',
        ]);
        $entry = app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail(
            TimeEntry::factory()->forProject($project)->create([
                'user_id' => test()->admin->id,
                'started_at' => CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC'),
                'ended_at' => CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'),
            ])->id,
        ));

        $draft = app(CreateDraft::class)->handle($this->admin, [
            'client_id' => $client->id,
            'kind' => 'invoice',
            'source' => 'time',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-30',
            'project_ids' => [$project->id],
            'without_project' => false,
            'grouping' => 'project',
        ]);

        $held = app(PartnerContext::class)->runAsSystem(static fn (): ?string => TimeEntry::query()->findOrFail($entry->id)->invoice_id);

        expect($held)->toBe($draft->id);

        Livewire::test(ListInvoices::class)
            ->assertTableActionVisible('deleteDraft', $draft)
            ->callTableAction('deleteDraft', $draft)
            ->assertNotified('Koncept byl smazán, záznamy času jsou znovu nevyfakturované');

        $after = app(PartnerContext::class)->runAsSystem(static fn (): array => [
            'draft' => Invoice::query()->whereKey($draft->id)->exists(),
            'entry' => TimeEntry::query()->findOrFail($entry->id)->invoice_id,
        ]);

        expect($after)->toBe(['draft' => false, 'entry' => null]);
    });

    it('offers an issued document the PDF download and never a delete', function (): void {
        $issued = listIssued();

        Livewire::test(ListInvoices::class)
            ->assertTableActionHidden('deleteDraft', $issued)
            ->assertTableActionVisible('downloadPdf', $issued)
            ->callTableAction('downloadPdf', $issued)
            ->assertRedirectContains('/admin/files/documents/');
    });

    it('offers a draft no PDF', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client());

        Livewire::test(ListInvoices::class)->assertTableActionHidden('downloadPdf', $draft);
    });

    it('has no bulk action', function (): void {
        $table = Livewire::test(ListInvoices::class)->instance()->getTable();

        expect($table->getFlatBulkActions())->toBe([]);
    });

    it('refuses a Partner the row actions', function (): void {
        $issued = listIssued();
        $client = Client::query()->whereKey($issued->client_id)->firstOrFail();

        $this->actingAs(Canary::partnerFor($client->id));

        $this->get(InvoiceResource::getUrl('index'))->assertForbidden();
    });
});

describe('queries and empty states', function (): void {
    it('does not issue more queries for 25 rows than for 5', function (): void {
        $client = InvoiceFixtures::client();
        $count = static function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListInvoices::class);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        foreach (range(1, 5) as $unused) {
            listIssued(client: $client);
        }

        $count();
        $five = $count();

        foreach (range(1, 20) as $unused) {
            listIssued(client: $client);
        }

        $twentyFive = $count();

        expect($twentyFive)->toBe($five);
    });
});
