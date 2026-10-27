<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\CancelCashReceipt;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;

/*
 * The storno of a cash receipt (Phase 15; D-08, D-11; P15-10, P15-12; AS-1, AS-16): a payment with a valid receipt cannot
 * be deleted; the Admin cancels the receipt with a recorded reason, which keeps its number and replaces its PDF with one
 * that carries the cancelled band; only then the payment goes, and the cancelled receipt stays. The queue is held back,
 * so each test decides when a job runs. Every name, address and amount is fictional.
 */

beforeEach(function (): void {
    Queue::fake();
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Records a cash payment of 1 500 CZK on a new issued document (an invoice unless given) of a new client, and returns the
 * document, the payment and its receipt.
 *
 * @return array{0: Invoice, 1: InvoicePayment, 2: CashReceipt}
 */
function cashReceiptCancelPay(InvoiceKind $kind = InvoiceKind::Invoice, ?string $address = null): array
{
    $client = InvoiceFixtures::client(['invoice_email' => $address]);
    $document = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')], $kind);

    $payment = app(RecordPayment::class)->handle(test()->admin, $document, ['amount' => '1500', 'paid_on' => '2026-10-14', 'method' => 'cash']);

    return [$document->refresh(), $payment, CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole()];
}

/**
 * The receipt as the database holds it now.
 */
function cashReceiptCancelFresh(CashReceipt $receipt): CashReceipt
{
    return CashReceipt::query()->findOrFail($receipt->id);
}

/**
 * The text of the stored PDF of a receipt, or null when Ghostscript is not installed.
 */
function cashReceiptCancelPdfText(CashReceipt $receipt): ?string
{
    $media = Document::query()->findOrFail(cashReceiptCancelFresh($receipt)->pdf_document_id)->getFirstMedia(Document::MEDIA_COLLECTION);

    return PdfText::flat((string) Storage::disk($media?->disk)->get((string) $media?->getPathRelativeToRoot()));
}

/**
 * The messages a Closure raises as a validation failure, keyed by field; empty when it raises none.
 *
 * @return array<string, list<string>>
 */
function cashReceiptCancelErrors(Closure $run): array
{
    try {
        $run();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * The `cash_receipt_cancelled` rows of a receipt.
 */
function cashReceiptCancelRows(CashReceipt $receipt): int
{
    return Activity::query()
        ->where('subject_type', 'cash_receipt')
        ->where('subject_id', $receipt->id)
        ->where('event', 'cash_receipt_cancelled')
        ->count();
}

describe('the deletion of a cash payment (D-08)', function (): void {
    it('refuses to delete a payment while its receipt is valid, and changes nothing', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptCancelPay();
        $pdfId = $receipt->pdf_document_id;

        $errors = cashReceiptCancelErrors(fn () => app(DeletePayment::class)->handle($this->admin, $payment));

        expect($errors)->toHaveKey('payment')
            ->and($errors['payment'][0])->toBe(__('cash_receipts.errors.payment_has_receipt', ['number' => $receipt->number]))
            ->and($errors['payment'][0])->toContain($receipt->number)
            ->and(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
            ->and(Transaction::query()->where('invoice_payment_id', $payment->id)->exists())->toBeTrue()
            ->and(cashReceiptCancelFresh($receipt)->invoice_payment_id)->toBe($payment->id)
            ->and(cashReceiptCancelFresh($receipt)->pdf_document_id)->toBe($pdfId)
            ->and($invoice->refresh()->payments()->count())->toBe(1);
    });
});

describe('the cancel of a cash receipt (D-08, P15-12)', function (): void {
    it('cancels the receipt with a reason, keeps the number, replaces the PDF and audits the reason only in the Admin log', function (): void {
        [, , $receipt] = cashReceiptCancelPay();
        $oldPdf = $receipt->pdf_document_id;
        $reason = 'Chybně zadaná částka platby';
        $before = cashReceiptCancelPdfText($receipt);

        $cancelled = app(CancelCashReceipt::class)->handle($this->admin, $receipt, $reason);
        $stored = cashReceiptCancelFresh($receipt);

        expect($cancelled->cancelled_at)->not->toBeNull()
            ->and($stored->cancelled_at?->toDateString())->toBe('2026-10-14')
            ->and($stored->number)->toBe($receipt->number)
            ->and($stored->pdf_document_id)->not->toBeNull()
            ->and($stored->pdf_document_id)->not->toBe($oldPdf)
            ->and(Document::query()->whereKey($oldPdf)->exists())->toBeFalse()
            ->and(Document::query()->whereKey($stored->pdf_document_id)->exists())->toBeTrue()
            ->and(cashReceiptCancelRows($receipt))->toBe(1)
            ->and(CashReceiptAudit::cancelReason($stored))->toBe($reason)
            ->and(array_filter(Schema::getColumnListing('cash_receipts'), static fn (string $column): bool => str_contains($column, 'reason')))->toBe([]);

        $row = Activity::query()->where('subject_id', $receipt->id)->where('event', 'cash_receipt_cancelled')->sole();

        expect($row->causer_id)->toBe($this->admin->id)
            ->and((array) $row->attribute_changes?->get('attributes'))->toBe(['cancel_reason' => $reason]);

        $after = cashReceiptCancelPdfText($receipt);

        if ($before === null || $after === null) {
            $this->markTestSkipped('Ghostscript (gs) is not installed, so the PDF text cannot be read back.');
        }

        expect($before)->not->toContain('STORNO')
            ->and($after)->toContain('STORNO')
            ->and($after)->toContain($receipt->number);
    });

    it('lets the payment be deleted after the cancel: the receipt and its PDF stay with an empty payment link', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptCancelPay();

        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        $pdfId = cashReceiptCancelFresh($receipt)->pdf_document_id;

        app(DeletePayment::class)->handle($this->admin, $payment);

        $stored = cashReceiptCancelFresh($receipt);

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeFalse()
            ->and(Transaction::query()->where('invoice_payment_id', $payment->id)->exists())->toBeFalse()
            ->and($stored->invoice_payment_id)->toBeNull()
            ->and($stored->number)->toBe($receipt->number)
            ->and($stored->cancelled_at)->not->toBeNull()
            ->and($stored->pdf_document_id)->toBe($pdfId)
            ->and(Document::query()->whereKey($pdfId)->exists())->toBeTrue()
            ->and($invoice->refresh()->payments()->count())->toBe(0);
    });

    it('never reuses the number of a cancelled receipt: the next cash payment gets the next one', function (): void {
        [, $payment, $receipt] = cashReceiptCancelPay();

        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        app(DeletePayment::class)->handle($this->admin, $payment);

        [, , $next] = cashReceiptCancelPay();

        expect($receipt->number)->toBe('PPD2026'.'0001')
            ->and($next->number)->toBe('PPD2026'.'0002')
            ->and(CashReceipt::query()->count())->toBe(2);
    });
});
