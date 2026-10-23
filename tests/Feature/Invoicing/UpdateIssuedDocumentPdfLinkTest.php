<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Payments\Actions\DeactivatePaymentLink;
use App\Domain\Payments\Actions\RegeneratePaymentLink;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Support\Canary;
use Tests\Support\CardPaymentFixtures;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;
use Tests\Support\QrDecode;

/*
 * What an edit of an open issued document does to the card payment link and the PDF (IN-14; D-04, D-09, D-11): the
 * link follows the amount owed inside the edit transaction, Stripe is only called by the queued job after the commit,
 * the regenerated PDF and its QR code carry the new amount, and a failed render undoes the whole edit. The gateway is
 * the in-memory fake; every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
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
function linkEditSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Every link row of the document, oldest first.
 *
 * @return Collection<int, PaymentLink>
 */
function linkEditLinks(Invoice $invoice): Collection
{
    return linkEditSystem(static fn (): Collection => PaymentLink::query()->where('invoice_id', $invoice->id)->orderBy('created_at')->orderBy('id')->get());
}

/**
 * The one live link of the document, or null.
 */
function linkEditLive(Invoice $invoice): ?PaymentLink
{
    return linkEditLinks($invoice)->first(static fn (PaymentLink $link): bool => $link->isLive());
}

/**
 * The lines of the document in order, read fresh.
 *
 * @return list<InvoiceItem>
 */
function linkEditLines(Invoice $invoice): array
{
    return array_values(linkEditSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get()->all()));
}

/**
 * The editor payload of a stored line with the given overrides.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function linkEditPayload(InvoiceItem $line, array $overrides = []): array
{
    return [
        'id' => $line->id,
        'description' => $line->description,
        'quantity' => $line->quantity,
        'unit' => $line->unit,
        'unit_price' => $line->unitPrice()->toMajor(),
        ...$overrides,
    ];
}

/**
 * The document row, read fresh.
 */
function linkEditRow(Invoice $invoice): Invoice
{
    return linkEditSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

/**
 * A card-on invoice of one line of 1 000,00 whose link is active at the fake gateway.
 */
function linkEditInvoice(): Invoice
{
    return CardPaymentSetup::issued(CardPaymentSetup::client(), amount: '1000.00');
}

/**
 * Sets the quantity of the only line of the document through the real edit path.
 */
function linkEditQuantity(Invoice $invoice, string $quantity): void
{
    [$line] = linkEditLines($invoice);

    app(UpdateIssuedDocument::class)->handle(test()->admin, $invoice, ['items' => [linkEditPayload($line, ['quantity' => $quantity])]]);
}

/**
 * The bytes of the one stored PDF object.
 */
function linkEditStoredPdf(): string
{
    $files = Storage::disk('local')->allFiles();

    expect($files)->toHaveCount(1);

    return Storage::disk('local')->get($files[0]) ?? '';
}

describe('the tracer', function (): void {
    it('follows a lowered total with the link, and the PDF and its QR code ask for the new amount', function (): void {
        $invoice = linkEditInvoice();
        $old = linkEditLive($invoice);

        expect($old?->state)->toBe(PaymentLinkState::Active)
            ->and($old->amount_minor)->toBe(100000);

        Queue::fake([CreatePaymentLink::class]);
        $callsBefore = $this->stripe->createCalls();

        linkEditQuantity($invoice, '0.5');

        $links = linkEditLinks($invoice);

        expect($links)->toHaveCount(2)
            ->and($links[0]->state)->toBe(PaymentLinkState::Deactivated)
            ->and($links[0]->deactivation_reason)->toBe(PaymentLinkDeactivation::AmountChanged)
            ->and($links[1]->state)->toBe(PaymentLinkState::Pending)
            ->and($links[1]->amount_minor)->toBe(50000)
            ->and($links[1]->currency)->toBe('CZK')
            ->and($this->stripe->createCalls())->toBe($callsBefore);

        Queue::assertPushed(CreatePaymentLink::class, 1);

        $html = app(InvoicePdf::class)->html(linkEditRow($invoice));
        preg_match_all('#<img src="(data:image/png;base64,[^"]+)"#', $html, $images);

        expect($images[1])->toHaveCount(1)
            ->and(QrDecode::payload($images[1][0]))->toContain('*AM:500.00*')
            ->and(QrDecode::payload($images[1][0]))->not->toContain('*AM:1000.00*');

        $text = PdfText::flat(linkEditStoredPdf());

        if ($text === null) {
            $this->markTestIncomplete('Ghostscript is not installed, the printed total cannot be read back.');
        }

        expect($text)->toContain(trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', linkEditRow($invoice)->total()->format('cs'))));
    });

    it('calls Stripe only after the commit, from the queued job', function (): void {
        $invoice = linkEditInvoice();
        $levels = [];
        $this->stripe->onCreate(static function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $baseline = DB::transactionLevel();

        linkEditQuantity($invoice, '2');

        $live = linkEditLive($invoice);

        expect($levels)->toBe([$baseline])
            ->and($live?->state)->toBe(PaymentLinkState::Active)
            ->and($live->amount_minor)->toBe(200000);
    });
});

describe('when the link is renewed', function (): void {
    it('leaves the live link alone when the amount owed does not change', function (): void {
        $invoice = linkEditInvoice();
        $live = linkEditLive($invoice);
        Queue::fake([CreatePaymentLink::class]);
        $callsBefore = $this->stripe->createCalls();
        [$line] = linkEditLines($invoice);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'items' => [linkEditPayload($line, ['description' => 'Example work, corrected text'])],
        ]);

        $links = linkEditLinks($invoice);

        expect($links)->toHaveCount(1)
            ->and($links[0]->id)->toBe($live?->id)
            ->and($links[0]->state)->toBe(PaymentLinkState::Active)
            ->and($this->stripe->deactivated())->toBe([])
            ->and($this->stripe->createCalls())->toBe($callsBefore);

        Queue::assertNothingPushed();
    });

    it('offers the new remaining amount after a partial payment and a raised total', function (): void {
        $invoice = linkEditInvoice();
        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);
        app(RegeneratePaymentLink::class)->handle($this->admin, $invoice);

        expect(linkEditLive($invoice)?->amount_minor)->toBe(60000);

        linkEditQuantity($invoice, '2');

        $links = linkEditLinks($invoice);
        $live = linkEditLive($invoice);

        expect($links)->toHaveCount(3)
            ->and($links[1]->deactivation_reason)->toBe(PaymentLinkDeactivation::AmountChanged)
            ->and($live?->id)->toBe($links[2]->id)
            ->and($live->amount_minor)->toBe(160000)
            ->and($live->state)->toBe(PaymentLinkState::Active);
    });

    it('gives a fully paid document that is edited upwards a link for what it owes again, and keeps the card payment record', function (): void {
        $invoice = linkEditInvoice();
        $record = CardPaymentFixtures::matched($invoice);
        $stored = linkEditSystem(static fn (): array => CardPayment::query()->whereKey($record->id)->firstOrFail()->getAttributes());

        expect(linkEditLinks($invoice)[0]->deactivation_reason)->toBe(PaymentLinkDeactivation::Paid)
            ->and(linkEditLive($invoice))->toBeNull();

        linkEditQuantity($invoice, '1.5');

        $live = linkEditLive($invoice);
        $after = linkEditSystem(static fn (): array => CardPayment::query()->whereKey($record->id)->firstOrFail()->getAttributes());

        expect(linkEditLinks($invoice))->toHaveCount(2)
            ->and($live?->amount_minor)->toBe(50000)
            ->and($after)->toBe($stored);
    });

    it('opens no link for a paid document whose total did not grow', function (): void {
        $invoice = linkEditInvoice();
        CardPaymentFixtures::matched($invoice);
        [$line] = linkEditLines($invoice);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'items' => [linkEditPayload($line, ['description' => 'Example work, corrected text'])],
        ]);

        expect(linkEditLinks($invoice))->toHaveCount(1)
            ->and(linkEditLive($invoice))->toBeNull();
    });

    it('opens no link after the Admin switched it off by hand', function (): void {
        $invoice = linkEditInvoice();
        app(DeactivatePaymentLink::class)->handle($this->admin, $invoice);

        linkEditQuantity($invoice, '2');

        $links = linkEditLinks($invoice);

        expect($links)->toHaveCount(1)
            ->and($links[0]->deactivation_reason)->toBe(PaymentLinkDeactivation::Manual)
            ->and(linkEditLive($invoice))->toBeNull();
    });

    it('opens no link for a document whose card switch was off at issue', function (): void {
        CardPaymentSetup::onlinePayments(false);
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(), amount: '1000.00');
        Queue::fake([CreatePaymentLink::class]);

        linkEditQuantity($invoice, '2');

        expect(linkEditLinks($invoice))->toHaveCount(0);

        Queue::assertNothingPushed();
    });
});

describe('a failed render rolls the whole edit back', function (): void {
    it('leaves lines, totals, links and the old PDF as they were', function (): void {
        $invoice = linkEditInvoice();
        [$line] = linkEditLines($invoice);
        $rowBefore = linkEditRow($invoice);
        $linksBefore = linkEditLinks($invoice)->map(static fn (PaymentLink $link): array => $link->getAttributes())->all();
        $adminBefore = (array) DB::table('invoice_admin')->where('invoice_id', $invoice->id)->first();
        $objectsBefore = linkEditSystem(static fn (): array => Media::query()->get()->map(static fn (Media $media): string => $media->getPathRelativeToRoot())->all());
        $filesBefore = Storage::disk('local')->allFiles();
        $callsBefore = $this->stripe->createCalls();

        View::composer('pdf.invoice', static function (): void {
            throw new RuntimeException('The rendering failed.');
        });

        expect(fn () => linkEditQuantity($invoice, '3'))->toThrow(RuntimeException::class);

        $rowAfter = linkEditRow($invoice);

        expect($rowAfter->total_minor)->toBe($rowBefore->total_minor)
            ->and($rowAfter->subtotal_minor)->toBe($rowBefore->subtotal_minor)
            ->and(linkEditLines($invoice)[0]->quantity)->toBe($line->quantity)
            ->and(linkEditLinks($invoice)->map(static fn (PaymentLink $link): array => $link->getAttributes())->all())->toBe($linksBefore)
            ->and((array) DB::table('invoice_admin')->where('invoice_id', $invoice->id)->first())->toBe($adminBefore)
            ->and(linkEditSystem(static fn (): array => Media::query()->get()->map(static fn (Media $media): string => $media->getPathRelativeToRoot())->all()))->toBe($objectsBefore)
            ->and(Storage::disk('local')->allFiles())->toBe($filesBefore)
            ->and($this->stripe->createCalls())->toBe($callsBefore)
            ->and($this->stripe->deactivated())->toBe([]);
    });
});
