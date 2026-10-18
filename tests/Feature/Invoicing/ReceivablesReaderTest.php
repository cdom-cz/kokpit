<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceListTab;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The receivables reader (IN-05, IN-06; D-17, D-18, D-20, ruling R-19-3): every display status, the list tab filters
 * with their counts and the CZK summaries come from one place, bound to the Prague day by the caller. Every name and
 * amount is fictional. All documents are issued on 2026-10-01 and the payment terms of the client decide the due date,
 * so the tests move "today" by handing the reader another day, never by touching the database clock.
 */

/**
 * An issued document of a new client with the given payment terms, issued on the day the clock stands on.
 *
 * @param  array<string, mixed>  $clientOverrides
 */
function recvIssued(string $amount = '10000.00', int $terms = 14, InvoiceKind $kind = InvoiceKind::Invoice, array $clientOverrides = []): Invoice
{
    $client = InvoiceFixtures::client(['payment_terms_days' => $terms, ...$clientOverrides]);

    return InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', $amount)], $kind);
}

/**
 * A payment of the document as the Admin; the clock must not stand before the payment date.
 */
function recvPay(Invoice $invoice, string $amount, string $paidOn = '2026-10-10'): void
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    app(RecordPayment::class)->handle($admin, $invoice, ['amount' => $amount, 'paid_on' => $paidOn]);
}

/**
 * The ids a tab selects, sorted.
 *
 * @return list<string>
 */
function recvTabIds(InvoiceListTab $tab, string $today): array
{
    $ids = app(ReceivablesReader::class)->constrain(Invoice::query(), $tab, $today)->pluck('invoices.id')->all();
    sort($ids);

    return array_values($ids);
}

/**
 * @param  list<Invoice>  $documents
 * @return list<string>
 */
function recvIds(array $documents): array
{
    $ids = array_map(static fn (Invoice $document): string => $document->id, $documents);
    sort($ids);

    return $ids;
}

/**
 * An invoice issued from a paid proforma: a draft of the same client with the link set as system, then issued.
 */
function recvInvoiceFromProforma(Invoice $proforma, User $admin): Invoice
{
    $client = Client::query()->whereKey($proforma->client_id)->firstOrFail();
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '10000.00')], InvoiceKind::Invoice, $admin);

    app(PartnerContext::class)->runAsSystem(static function () use ($draft, $proforma): void {
        $draft->forceFill(['proforma_id' => $proforma->id])->save();
    });

    return app(IssueDocument::class)->handle($admin, $draft->refresh());
}

/**
 * A mixed portfolio issued on 2026-10-01 and paid on 2026-10-10, to be read on 2026-10-20: every status once.
 *
 * @return array<string, Invoice>
 */
function recvPortfolio(): array
{
    $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '1000.00')]);
    $unpaid = recvIssued(terms: 30);
    $partial = recvIssued(terms: 30);
    $overdue = recvIssued();
    $paid = recvIssued();
    $cancelledInvoice = recvIssued();
    $creditNote = InvoiceFixtures::stornoed($cancelledInvoice);
    $awaiting = recvIssued(kind: InvoiceKind::Proforma);
    $converted = recvIssued(kind: InvoiceKind::Proforma, clientOverrides: ['payment_terms_days' => 30]);
    $overdueProforma = recvIssued(kind: InvoiceKind::Proforma);

    test()->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Europe/Prague'));

    recvPay($partial, '3000');
    recvPay($paid, '10000');
    recvPay($awaiting, '10000');
    recvPay($converted, '10000');

    $fromProforma = recvInvoiceFromProforma($converted, Canary::admin());
    test()->actingAs(Canary::admin());

    return [
        'draft' => $draft,
        'unpaid' => $unpaid,
        'partial' => $partial,
        'overdue' => $overdue,
        'paid' => $paid,
        'cancelled' => $cancelledInvoice,
        'creditNote' => $creditNote,
        'awaiting' => $awaiting,
        'converted' => $converted,
        'fromProforma' => $fromProforma,
        'overdueProforma' => $overdueProforma,
    ];
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Prague'));
});

describe('the tracer: an invoice past its due date', function (): void {
    it('reads as overdue by its remaining amount with the days late, and the overdue tab, counts and summary find it', function (): void {
        $overdue = recvIssued();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Europe/Prague'));
        recvPay($overdue, '4 000,00');

        $reader = app(ReceivablesReader::class);
        $today = '2026-10-20';
        $status = $reader->statuses([$overdue], $today)[$overdue->id];

        expect($overdue->due_date?->format('Y-m-d'))->toBe('2026-10-15')
            ->and($status['status'])->toBe(InvoiceStatus::Overdue)
            ->and($status['remaining']?->minor)->toBe(600000)
            ->and($status['overdue_days'])->toBe(5)
            ->and(recvTabIds(InvoiceListTab::Overdue, $today))->toBe([$overdue->id])
            ->and(recvTabIds(InvoiceListTab::Unpaid, $today))->toBe([])
            ->and($reader->tabCounts($today)['overdue'])->toBe(1)
            ->and($reader->tabCounts($today)['unpaid'])->toBe(0);

        $summary = $reader->summary($today);

        expect($summary['overdue']['count'])->toBe(1)
            ->and($summary['overdue']['czk']->minor)->toBe(600000)
            ->and($summary['overdue']['czk']->currency)->toBe('CZK')
            ->and($summary['unpaid']['count'])->toBe(0);
    });

    it('reads the same invoice as partly paid and counts it under unpaid before its due date', function (): void {
        $invoice = recvIssued();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'Europe/Prague'));
        recvPay($invoice, '4000', '2026-10-12');

        $reader = app(ReceivablesReader::class);
        $today = '2026-10-12';
        $status = $reader->statuses([$invoice], $today)[$invoice->id];

        expect($status['status'])->toBe(InvoiceStatus::PartiallyPaid)
            ->and($status['remaining']?->minor)->toBe(600000)
            ->and($status['overdue_days'])->toBeNull()
            ->and(recvTabIds(InvoiceListTab::Unpaid, $today))->toBe([$invoice->id])
            ->and(recvTabIds(InvoiceListTab::Overdue, $today))->toBe([])
            ->and($reader->tabCounts($today)['unpaid'])->toBe(1)
            ->and($reader->tabCounts($today)['overdue'])->toBe(0)
            ->and($reader->summary($today)['unpaid'])->toMatchArray(['count' => 1])
            ->and($reader->summary($today)['unpaid']['czk']->minor)->toBe(600000);
    });

    it('refuses everybody but the Admin and a system run', function (): void {
        $invoice = recvIssued();
        $client = Client::query()->whereKey($invoice->client_id)->firstOrFail();
        $reader = app(ReceivablesReader::class);

        $this->actingAs(Canary::partnerFor($client->id));

        expect(fn () => $reader->statuses([$invoice], '2026-10-20'))->toThrow(AuthorizationException::class)
            ->and(fn () => $reader->constrain(Invoice::query(), InvoiceListTab::Overdue, '2026-10-20'))->toThrow(AuthorizationException::class)
            ->and(fn () => $reader->tabCounts('2026-10-20'))->toThrow(AuthorizationException::class)
            ->and(fn () => $reader->summary('2026-10-20'))->toThrow(AuthorizationException::class);

        $counts = app(PartnerContext::class)->runAsSystem(static fn (): array => app(ReceivablesReader::class)->tabCounts('2026-10-20'));

        expect($counts['all'])->toBe(1);
    });

    it('binds today as a Prague calendar day from the clock, not the database', function (): void {
        // 23:30 UTC on 17 October is half past one on 18 October in Prague (summer time).
        $this->travelTo(CarbonImmutable::parse('2026-10-17 23:30:00', 'UTC'));

        expect(ReceivablesReader::today())->toBe('2026-10-18');

        $this->travelTo(CarbonImmutable::parse('2026-10-17 21:30:00', 'UTC'));

        expect(ReceivablesReader::today())->toBe('2026-10-17');
    });

    it('refuses a today that is not a calendar day', function (): void {
        $this->actingAs(Canary::admin());
        $reader = app(ReceivablesReader::class);

        expect(fn () => $reader->tabCounts('20.10.2026'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $reader->summary('2026-02-30'))->toThrow(InvalidArgumentException::class);
    });
});

describe('the portfolio', function (): void {
    it('keeps the money figure of a document out of the other clients when a client is named', function (): void {
        $mine = recvIssued('2000.00');
        recvIssued('3000.00');

        $reader = app(ReceivablesReader::class);
        $today = '2026-10-20';

        expect($reader->summary($today)['overdue']['count'])->toBe(2)
            ->and($reader->summary($today, $mine->client_id)['overdue']['count'])->toBe(1)
            ->and($reader->summary($today, $mine->client_id)['overdue']['czk']->minor)->toBe(200000)
            ->and($reader->tabCounts($today, $mine->client_id)['all'])->toBe(1)
            ->and($reader->tabCounts($today)['all'])->toBe(2);
    });

    it('issues one query for all the tab counts', function (): void {
        recvIssued();
        $reader = app(ReceivablesReader::class);
        $reader->tabCounts('2026-10-20');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $counts = $reader->tabCounts('2026-10-20');
        $queries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains((string) $query['query'], 'invoices'));
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1)
            ->and(array_keys($counts))->toBe(array_map(static fn (InvoiceListTab $tab): string => $tab->value, InvoiceListTab::cases()));
    });
});

describe('every status and every tab for a mixed portfolio', function (): void {
    it('gives each document its status, remaining amount and days late', function (): void {
        $documents = recvPortfolio();
        $statuses = app(ReceivablesReader::class)->statuses(array_values($documents), '2026-10-20');
        $of = static fn (string $name): array => $statuses[$documents[$name]->id];

        expect($statuses)->toHaveCount(count($documents))
            ->and($of('draft')['status'])->toBe(InvoiceStatus::Draft)
            ->and($of('draft')['remaining'])->toBeNull()
            ->and($of('unpaid')['status'])->toBe(InvoiceStatus::Unpaid)
            ->and($of('unpaid')['remaining']?->minor)->toBe(1000000)
            ->and($of('partial')['status'])->toBe(InvoiceStatus::PartiallyPaid)
            ->and($of('partial')['remaining']?->minor)->toBe(700000)
            ->and($of('overdue')['status'])->toBe(InvoiceStatus::Overdue)
            ->and($of('overdue')['overdue_days'])->toBe(5)
            ->and($of('paid')['status'])->toBe(InvoiceStatus::Paid)
            ->and($of('paid')['remaining']?->minor)->toBe(0)
            ->and($of('cancelled')['status'])->toBe(InvoiceStatus::Cancelled)
            ->and($of('cancelled')['remaining']?->minor)->toBe(0)
            ->and($of('cancelled')['overdue_days'])->toBeNull()
            ->and($of('creditNote')['status'])->toBe(InvoiceStatus::CreditNote)
            ->and($of('creditNote')['remaining'])->toBeNull()
            ->and($of('awaiting')['status'])->toBe(InvoiceStatus::AwaitingInvoice)
            ->and($of('awaiting')['remaining']?->minor)->toBe(0)
            ->and($of('converted')['status'])->toBe(InvoiceStatus::Converted)
            ->and($of('fromProforma')['status'])->toBe(InvoiceStatus::Unpaid)
            ->and($of('overdueProforma')['status'])->toBe(InvoiceStatus::Overdue)
            ->and($of('overdueProforma')['overdue_days'])->toBe(5);
    });

    it('returns exactly its documents under each tab and counts them with the same conditions', function (): void {
        $documents = recvPortfolio();
        $today = '2026-10-20';
        $pick = static fn (string ...$names): array => recvIds(array_map(static fn (string $name): Invoice => $documents[$name], $names));

        $expected = [
            'all' => $pick(...array_keys($documents)),
            'drafts' => $pick('draft'),
            'unpaid' => $pick('unpaid', 'partial', 'fromProforma'),
            'overdue' => $pick('overdue', 'overdueProforma'),
            'paid' => $pick('paid', 'awaiting', 'converted'),
            'cancelled' => $pick('cancelled'),
            'awaiting_invoice' => $pick('awaiting'),
        ];

        $counts = app(ReceivablesReader::class)->tabCounts($today);

        foreach (InvoiceListTab::cases() as $tab) {
            expect(recvTabIds($tab, $today))->toBe($expected[$tab->value], $tab->value)
                ->and($counts[$tab->value])->toBe(count($expected[$tab->value]), $tab->value);
        }

        $creditNote = $documents['creditNote']->id;
        $elsewhere = array_filter(InvoiceListTab::cases(), static fn (InvoiceListTab $tab): bool => $tab !== InvoiceListTab::All && in_array($creditNote, recvTabIds($tab, $today), true));

        expect($elsewhere)->toBe([]);
    });

    it('summarises the owed money, the late money and the paid proformas waiting for an invoice', function (): void {
        recvPortfolio();
        $summary = app(ReceivablesReader::class)->summary('2026-10-20');

        // overdue: the invoice and the proforma, 10 000,00 Kč each; unpaid: 10 000,00 + 7 000,00 + 10 000,00 Kč.
        expect($summary['overdue']['count'])->toBe(2)
            ->and($summary['overdue']['czk']->minor)->toBe(2000000)
            ->and($summary['unpaid']['count'])->toBe(3)
            ->and($summary['unpaid']['czk']->minor)->toBe(2700000)
            ->and($summary['awaiting_invoice'])->toBe(1);
    });

    it('does not count a cancelled invoice as late or owed whatever its due date', function (): void {
        $documents = recvPortfolio();

        expect($documents['cancelled']->due_date?->format('Y-m-d'))->toBe('2026-10-15')
            ->and(recvTabIds(InvoiceListTab::Overdue, '2026-10-20'))->not->toContain($documents['cancelled']->id)
            ->and(recvTabIds(InvoiceListTab::Unpaid, '2026-10-20'))->not->toContain($documents['cancelled']->id);
    });
});

describe('the CZK figures', function (): void {
    it('converts the remaining amount of a foreign document once with its frozen issue-time rate, and takes a CZK one as it is', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-01', '25.125');
        InvoiceFixtures::cnbRate('EUR', '2026-10-10', '24.5');

        $euro = recvIssued('500.00', clientOverrides: ['currency' => 'EUR']);
        $crown = recvIssued('600.00');

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Europe/Prague'));
        recvPay($euro, '100,00');

        $reader = app(ReceivablesReader::class);
        $summary = $reader->summary('2026-10-20');

        // 400,00 EUR at 25,125 is 10 050,00 Kč, not 9 800,00 Kč at the rate of the payment day; plus 600,00 Kč.
        expect($summary['overdue']['count'])->toBe(2)
            ->and($summary['overdue']['czk']->minor)->toBe(1005000 + 60000)
            ->and($summary['overdue']['czk']->currency)->toBe('CZK')
            ->and($reader->summary('2026-10-20', $euro->client_id)['overdue']['czk']->minor)->toBe(1005000)
            ->and($reader->summary('2026-10-20', $crown->client_id)['overdue']['czk']->minor)->toBe(60000)
            ->and($reader->statuses([$euro], '2026-10-20')[$euro->id]['remaining']?->format('cs'))->toContain('400,00');
    });

    it('sums the converted documents in CZK without mixing the currencies of the documents', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-01', '25.125');

        $first = recvIssued('100.00', clientOverrides: ['currency' => 'EUR']);
        recvIssued('200.00', clientOverrides: ['currency' => 'EUR']);
        $this->actingAs(Canary::admin());

        // Each remaining amount is converted and rounded once: 100,00 EUR is 2 512,50 Kč and 200,00 EUR 5 025,00 Kč.
        $summary = app(ReceivablesReader::class)->summary('2026-10-20');

        expect($summary['overdue']['czk']->minor)->toBe(251250 + 502500)
            ->and($first->currency)->toBe('EUR');
    });
});

describe('the due date and the Prague day', function (): void {
    it('treats a due date of today as not late and the day after as late', function (): void {
        $invoice = recvIssued();
        $this->actingAs(Canary::admin());
        $reader = app(ReceivablesReader::class);

        $onDueDay = $reader->statuses([$invoice], '2026-10-15')[$invoice->id];
        $dayAfter = $reader->statuses([$invoice], '2026-10-16')[$invoice->id];

        expect($onDueDay['status'])->toBe(InvoiceStatus::Unpaid)
            ->and($onDueDay['overdue_days'])->toBeNull()
            ->and(recvTabIds(InvoiceListTab::Overdue, '2026-10-15'))->toBe([])
            ->and($dayAfter['status'])->toBe(InvoiceStatus::Overdue)
            ->and($dayAfter['overdue_days'])->toBe(1)
            ->and(recvTabIds(InvoiceListTab::Overdue, '2026-10-16'))->toBe([$invoice->id]);
    });

    it('turns overdue at the Prague midnight, not at the UTC one', function (): void {
        $invoice = recvIssued();
        $this->actingAs(Canary::admin());
        $reader = app(ReceivablesReader::class);

        // 22:30 UTC on 14 October is 00:30 on 15 October in Prague: the due day itself, not late yet.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 22:30:00', 'UTC'));
        $dueDay = $reader->statuses([$invoice], ReceivablesReader::today())[$invoice->id];

        // 22:30 UTC on 15 October is 00:30 on 16 October in Prague, while the UTC date is still the 15th: late.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 22:30:00', 'UTC'));
        $dayAfter = $reader->statuses([$invoice], ReceivablesReader::today())[$invoice->id];

        expect($dueDay['status'])->toBe(InvoiceStatus::Unpaid)
            ->and($dayAfter['status'])->toBe(InvoiceStatus::Overdue)
            ->and($dayAfter['overdue_days'])->toBe(1);
    });
});

it('has a Czech label for every tab', function (): void {
    expect(InvoiceListTab::Overdue->getLabel())->toBe('Po splatnosti')
        ->and(InvoiceListTab::AwaitingInvoice->getLabel())->toBe('Zálohy k vyfakturování');
});
