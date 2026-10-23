<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;
use Tests\Support\QrDecode;

/*
 * The currency of an open issued invoice or proforma (IN-13, IN-14; D-06 with its owner ruling of 2026-10-23, D-11;
 * research Pitfalls 2 and 6): the CNB figures of the issue date, the time lines, the bank account and the card link
 * follow the new currency, and a document that already has a payment or a credit note keeps its currency. Every name,
 * rate and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 09:30 in Prague: the day of the draft and of the issue.
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
function iccSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A project of the client with the hourly rate in the currency of the client.
 */
function iccProject(Client $client, string $rate): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Alpha project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => $rate,
    ]);
}

/**
 * One finished entry of the project on a day of September, `$hours` long.
 */
function iccEntry(Project $project, int $hours): TimeEntry
{
    $factory = TimeEntry::factory()->forProject($project);
    $start = CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC');

    return iccSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => $start,
        'ended_at' => $start->addHours($hours),
    ])->id));
}

/**
 * An issued document of the client with one time line at the hourly rate (ten hours) and one typed line of 100,00,
 * in the currency; the CNB lists of the issue day are stored for the foreign currencies named.
 *
 * @param  array<string, string>  $lists  currency => rate of the issue day
 */
function iccIssued(Client $client, string $currency, string $rate = '1500', array $lists = ['EUR' => '25.125']): Invoice
{
    foreach ($lists as $code => $quote) {
        InvoiceFixtures::cnbRate($code, '2026-10-14', $quote);
    }

    $project = iccProject($client, $rate);
    iccEntry($project, 10);

    $draft = app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
        'currency' => $currency,
    ]);

    $items = array_map(static fn (InvoiceItem $item): array => [
        'id' => $item->id,
        'description' => $item->description,
        'quantity' => $item->quantity,
        'unit' => $item->unit,
        'unit_price' => Money::ofMinor($item->unit_price_minor, $currency)->toMajor(),
    ], iccLines($draft));
    $items[] = InvoiceFixtures::item('Typed design work', '1', '100.00');

    app(UpdateDraft::class)->handle(test()->admin, $draft, ['items' => $items]);

    return app(IssueDocument::class)->handle(test()->admin, $draft);
}

/**
 * The stored lines of the document in order, with their sources.
 *
 * @return list<InvoiceItem>
 */
function iccLines(Invoice $document): array
{
    return iccSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $document->id)->with('source')->orderBy('position')->get()->all());
}

/**
 * The document row, read fresh.
 */
function iccRow(Invoice $document): Invoice
{
    return iccSystem(static fn (): Invoice => Invoice::query()->whereKey($document->id)->firstOrFail());
}

/**
 * The Admin-only row of the document, read fresh.
 */
function iccAdmin(Invoice $document): InvoiceAdmin
{
    return iccSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $document->id)->firstOrFail());
}

/**
 * Every link row of the document, oldest first.
 *
 * @return list<PaymentLink>
 */
function iccLinks(Invoice $document): array
{
    return iccSystem(static fn (): array => PaymentLink::query()->where('invoice_id', $document->id)->orderBy('created_at')->orderBy('id')->get()->all());
}

/**
 * The editor payload of a stored line, the unit price as the editor shows it in the currency.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function iccPayload(InvoiceItem $line, string $currency, array $overrides = []): array
{
    return [
        'id' => $line->id,
        'description' => $line->description,
        'quantity' => $line->quantity,
        'unit' => $line->unit,
        'unit_price' => Money::ofMinor($line->unit_price_minor, $currency)->toMajor(),
        ...$overrides,
    ];
}

describe('the tracer: a EUR invoice with a CZK-rated time line and a card link is switched to CZK', function (): void {
    it('restores the exact rate, clears the CNB figures, takes the CZK account and renews the link', function (): void {
        $invoice = iccIssued(CardPaymentSetup::client(), 'EUR');
        [$time, $typed] = iccLines($invoice);

        expect($time->price_converted)->toBeTrue()
            ->and($time->source?->conversion_snapshot)->toBeArray()
            ->and(iccAdmin($invoice)->czk_total_minor)->not->toBeNull()
            ->and(iccLinks($invoice))->toHaveCount(1)
            ->and(iccLinks($invoice)[0]->state)->toBe(PaymentLinkState::Active);

        Queue::fake([CreatePaymentLink::class]);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'currency' => 'CZK',
            'items' => [
                iccPayload($time, 'CZK', ['unit_price' => '1500.00']),
                iccPayload($typed, 'CZK'),
            ],
        ]);

        [$timeAfter, $typedAfter] = iccLines($invoice);
        $row = iccRow($invoice);
        $admin = iccAdmin($invoice);
        $czkAccount = iccSystem(static fn (): array => app(BankAccountSettings::class)->forCurrency('CZK')?->toArray() ?? []);

        // The time line is back at its exact CZK rate; the typed line keeps its figure.
        expect($row->currency)->toBe('CZK')
            ->and($timeAfter->unit_price_minor)->toBe(150000)
            ->and($timeAfter->line_total_minor)->toBe(1500000)
            ->and($timeAfter->price_converted)->toBeFalse()
            ->and($timeAfter->source?->conversion_snapshot)->toBeNull()
            ->and($typedAfter->unit_price_minor)->toBe($typed->unit_price_minor)
            ->and($typedAfter->line_total_minor)->toBe($typed->line_total_minor)
            ->and($row->total_minor)->toBe(1500000 + $typed->line_total_minor)
            ->and($admin->exchange_rate)->toBeNull()
            ->and($admin->exchange_unit_amount)->toBeNull()
            ->and($admin->exchange_rate_date)->toBeNull()
            ->and($admin->czk_total_minor)->toBeNull()
            ->and($row->bank_snapshot)->toEqual($czkAccount);

        // The EUR link is switched off, a CZK link for the new total is pending, and Stripe waits for the queued job.
        $links = iccLinks($invoice);

        expect($links)->toHaveCount(2)
            ->and($links[0]->state)->toBe(PaymentLinkState::Deactivated)
            ->and($links[0]->deactivation_reason)->toBe(PaymentLinkDeactivation::AmountChanged)
            ->and($links[0]->currency)->toBe('EUR')
            ->and($links[1]->state)->toBe(PaymentLinkState::Pending)
            ->and($links[1]->currency)->toBe('CZK')
            ->and($links[1]->amount_minor)->toBe($row->total_minor);

        Queue::assertPushed(CreatePaymentLink::class, 1);

        // The regenerated PDF's QR code asks for the new amount in CZK.
        preg_match_all('#<img src="(data:image/png;base64,[^"]+)"#', app(InvoicePdf::class)->html($row), $images);

        expect($images[1])->toHaveCount(1)
            ->and(QrDecode::payload($images[1][0]))->toContain('*CC:CZK*')
            ->and(QrDecode::payload($images[1][0]))->toContain('*AM:15100.00*');
    });

    it('deactivates the old link as amount_changed even when the number happens to be equal', function (): void {
        // One typed line of 1 000,00: the total is the same number in EUR and in CZK after the change.
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $invoice = InvoiceFixtures::issued(CardPaymentSetup::client(['currency' => 'EUR']), [InvoiceFixtures::item('Typed work', '1', '1000.00')]);
        $old = iccLinks($invoice);

        expect($old)->toHaveCount(1);

        expect(iccRow($invoice)->currency)->toBe('EUR');

        Queue::fake([CreatePaymentLink::class]);
        [$line] = iccLines($invoice);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'currency' => 'CZK',
            'items' => [iccPayload($line, 'CZK')],
        ]);

        $links = iccLinks($invoice);

        expect(iccRow($invoice)->total_minor)->toBe(100000)
            ->and($links)->toHaveCount(2)
            ->and($links[0]->deactivation_reason)->toBe(PaymentLinkDeactivation::AmountChanged)
            ->and($links[1]->currency)->toBe('CZK')
            ->and($links[1]->amount_minor)->toBe(100000);

        Queue::assertPushed(CreatePaymentLink::class, 1);
    });
});
