<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
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

it('has a Czech label for every tab', function (): void {
    expect(InvoiceListTab::Overdue->getLabel())->toBe('Po splatnosti')
        ->and(InvoiceListTab::AwaitingInvoice->getLabel())->toBe('Zálohy k vyfakturování');
});
