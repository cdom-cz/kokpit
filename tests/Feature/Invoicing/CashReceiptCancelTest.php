<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\CancelCashReceipt;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;
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
    Mail::getSymfonyTransport()->flush();
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

/**
 * The confirmation row of a payment, read as the system.
 */
function cashReceiptCancelConfirmation(InvoicePayment $payment): PaymentConfirmation
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmation => PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->sole());
}

/**
 * One try of the sending job through its own middleware and without the failure handling of the queue.
 */
function cashReceiptCancelRun(string $confirmationId): void
{
    $job = new SendPaymentConfirmation($confirmationId);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (SendPaymentConfirmation $job): mixed => app()->call($job->handle(...)));
}

/**
 * The tables of the row locks (`for update`) among the given queries, in the order they were first locked, limited to
 * the tables of the lock order of a payment writer.
 *
 * @param  array<int, array<string, mixed>>  $log
 * @return list<string>
 */
function cashReceiptCancelLockOrder(array $log): array
{
    $order = ['invoices', 'invoice_payments', 'cash_receipts', 'transactions'];
    $seen = [];

    foreach ($log as $entry) {
        $query = is_string($entry['query'] ?? null) ? $entry['query'] : '';

        if (stripos($query, 'for update') !== false && preg_match('/\bfrom "([a-z_]+)"/', $query, $match) === 1 && in_array($match[1], $order, true)) {
            $seen[] = $match[1];
        }
    }

    return array_values(array_unique($seen));
}

/**
 * Deletes the stored PDF of a receipt through DeleteDocument as the Admin.
 */
function cashReceiptCancelDeletePdf(CashReceipt $receipt, bool $confirmed = false): void
{
    app(DeleteDocument::class)->handle(test()->admin, Document::query()->findOrFail(cashReceiptCancelFresh($receipt)->pdf_document_id), $confirmed);
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

describe('who may cancel, with which reason, and how often', function (): void {
    it('refuses a Partner of the client and changes nothing', function (): void {
        [, , $receipt] = cashReceiptCancelPay();
        $pdfId = $receipt->pdf_document_id;
        $partner = Canary::partnerFor($receipt->client_id);

        expect(static fn () => app(CancelCashReceipt::class)->handle($partner, $receipt, 'Chybně zadaná částka platby'))->toThrow(AuthorizationException::class);

        $stored = cashReceiptCancelFresh($receipt);

        expect($stored->cancelled_at)->toBeNull()
            ->and($stored->pdf_document_id)->toBe($pdfId)
            ->and(cashReceiptCancelRows($receipt))->toBe(0);
    });

    it('refuses a reason of 9 or 501 characters, and a short one padded with white space, under reason', function (): void {
        [, , $receipt] = cashReceiptCancelPay();

        foreach ([str_repeat('a', 9), str_repeat('a', 501), '   '.str_repeat('a', 9).'   '] as $reason) {
            $errors = cashReceiptCancelErrors(fn () => app(CancelCashReceipt::class)->handle($this->admin, $receipt, $reason));

            expect($errors)->toHaveKey('reason')
                ->and($errors['reason'][0])->toBe(__('cash_receipts.errors.reason_length'));
        }

        expect(cashReceiptCancelFresh($receipt)->cancelled_at)->toBeNull()
            ->and(cashReceiptCancelRows($receipt))->toBe(0);
    });

    it('accepts a reason of exactly 10 and of exactly 500 characters', function (): void {
        [, , $short] = cashReceiptCancelPay();
        [, , $long] = cashReceiptCancelPay();

        app(CancelCashReceipt::class)->handle($this->admin, $short, str_repeat('a', 10));
        app(CancelCashReceipt::class)->handle($this->admin, $long, str_repeat('b', 500));

        expect(cashReceiptCancelFresh($short)->cancelled_at)->not->toBeNull()
            ->and(cashReceiptCancelFresh($long)->cancelled_at)->not->toBeNull()
            ->and(CashReceiptAudit::cancelReason($long))->toBe(str_repeat('b', 500));
    });

    it('refuses a second cancel under receipt and writes no second row', function (): void {
        [, , $receipt] = cashReceiptCancelPay();

        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        $cancelledAt = cashReceiptCancelFresh($receipt)->cancelled_at;
        $pdfId = cashReceiptCancelFresh($receipt)->pdf_document_id;

        $errors = cashReceiptCancelErrors(fn () => app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Jiný dostatečně dlouhý důvod'));

        expect($errors)->toHaveKey('receipt')
            ->and($errors['receipt'][0])->toBe(__('cash_receipts.errors.already_cancelled'))
            ->and(cashReceiptCancelFresh($receipt)->cancelled_at?->equalTo($cancelledAt))->toBeTrue()
            ->and(cashReceiptCancelFresh($receipt)->pdf_document_id)->toBe($pdfId)
            ->and(cashReceiptCancelRows($receipt))->toBe(1);
    });
});

describe('the cancel next to the send and the other kinds of document', function (): void {
    it('cancels a receipt that the real send locked, and it stays locked', function (): void {
        $address = exampleEmail('client-invoice');
        [, $payment, $receipt] = cashReceiptCancelPay(InvoiceKind::Invoice, $address);

        cashReceiptCancelRun(cashReceiptCancelConfirmation($payment)->id);

        expect(cashReceiptCancelFresh($receipt)->lock_state)->toBe(CashReceiptLockState::Locked);

        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        $stored = cashReceiptCancelFresh($receipt);

        expect($stored->cancelled_at)->not->toBeNull()
            ->and($stored->lock_state)->toBe(CashReceiptLockState::Locked)
            ->and($stored->pdf_document_id)->not->toBeNull()
            ->and(cashReceiptCancelRows($receipt))->toBe(1);
    });

    it('sends the confirmation without the receipt when the receipt was cancelled before it left, and leaves it open (AS-1)', function (): void {
        $address = exampleEmail('client-invoice');
        [, $payment, $receipt] = cashReceiptCancelPay(InvoiceKind::Invoice, $address);
        $confirmation = cashReceiptCancelConfirmation($payment);

        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        cashReceiptCancelRun($confirmation->id);

        $messages = MailFixtures::to($address);
        $stored = cashReceiptCancelFresh($receipt);

        expect($messages)->toHaveCount(1)
            ->and($messages[0]->getAttachments())->toBe([])
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmationState => PaymentConfirmation::query()->findOrFail($confirmation->id)->state))->toBe(PaymentConfirmationState::Sent)
            ->and($stored->lock_state)->toBe(CashReceiptLockState::Open)
            ->and($stored->cancelled_at)->not->toBeNull();
    });

    it('cancels and then deletes the cash payment of a proforma, and the cancelled receipt of the proforma stays', function (): void {
        [$proforma, $payment, $receipt] = cashReceiptCancelPay(InvoiceKind::Proforma);

        expect(cashReceiptCancelErrors(fn () => app(DeletePayment::class)->handle($this->admin, $payment)))->toHaveKey('payment');

        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        app(DeletePayment::class)->handle($this->admin, $payment);

        $stored = cashReceiptCancelFresh($receipt);

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeFalse()
            ->and($stored->invoice_id)->toBe($proforma->id)
            ->and($stored->invoice_payment_id)->toBeNull()
            ->and($stored->cancelled_at)->not->toBeNull()
            ->and(Document::query()->whereKey($stored->pdf_document_id)->exists())->toBeTrue();
    });

    it('drops the queued confirmation with its payment and sends no mail', function (): void {
        $address = exampleEmail('client-invoice');
        [, $payment, $receipt] = cashReceiptCancelPay(InvoiceKind::Invoice, $address);
        $confirmation = cashReceiptCancelConfirmation($payment);

        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        app(DeletePayment::class)->handle($this->admin, $payment);

        expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => PaymentConfirmation::query()->whereKey($confirmation->id)->exists()))->toBeFalse()
            ->and(MailFixtures::sent())->toBe([])
            ->and(cashReceiptCancelFresh($receipt)->cancelled_at)->not->toBeNull();
    });

    it('locks the rows in the order invoice, payment, receipt, income for the cancel and for the deletion', function (): void {
        [, $payment, $receipt] = cashReceiptCancelPay();

        DB::enableQueryLog();
        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        $cancel = cashReceiptCancelLockOrder(DB::getQueryLog());
        DB::flushQueryLog();

        app(DeletePayment::class)->handle($this->admin, $payment);
        $delete = cashReceiptCancelLockOrder(DB::getQueryLog());
        DB::disableQueryLog();

        expect($cancel)->toBe(['invoices', 'invoice_payments', 'cash_receipts'])
            ->and($delete)->toBe(['invoices', 'invoice_payments', 'cash_receipts', 'transactions']);
    });
});

describe('the stored PDF of a receipt is evidence (D-08, D-11)', function (): void {
    it('cannot be deleted on its own while the receipt is open', function (): void {
        [, , $receipt] = cashReceiptCancelPay();
        $pdfId = $receipt->pdf_document_id;

        expect(static fn () => cashReceiptCancelDeletePdf($receipt))->toThrow(DomainException::class, __('cash_receipts.errors.pdf_protected'));

        expect(cashReceiptCancelFresh($receipt)->pdf_document_id)->toBe($pdfId)
            ->and(Document::query()->whereKey($pdfId)->exists())->toBeTrue();
    });

    it('cannot be deleted on its own after the send locked the receipt', function (): void {
        $address = exampleEmail('client-invoice');
        [, $payment, $receipt] = cashReceiptCancelPay(InvoiceKind::Invoice, $address);
        cashReceiptCancelRun(cashReceiptCancelConfirmation($payment)->id);
        $pdfId = cashReceiptCancelFresh($receipt)->pdf_document_id;

        expect(cashReceiptCancelFresh($receipt)->lock_state)->toBe(CashReceiptLockState::Locked)
            ->and(static fn () => cashReceiptCancelDeletePdf($receipt))->toThrow(DomainException::class, __('cash_receipts.errors.pdf_protected'))
            ->and(Document::query()->whereKey($pdfId)->exists())->toBeTrue();
    });

    it('cannot be deleted on its own after the cancel', function (): void {
        [, , $receipt] = cashReceiptCancelPay();
        app(CancelCashReceipt::class)->handle($this->admin, $receipt, 'Chybně zadaná částka platby');
        $pdfId = cashReceiptCancelFresh($receipt)->pdf_document_id;

        expect(static fn () => cashReceiptCancelDeletePdf($receipt))->toThrow(DomainException::class, __('cash_receipts.errors.pdf_protected'))
            ->and(cashReceiptCancelFresh($receipt)->pdf_document_id)->toBe($pdfId)
            ->and(Document::query()->whereKey($pdfId)->exists())->toBeTrue();
    });

    it('is deleted only with the confirmation flag of the sanctioned replace, and the key clears the link', function (): void {
        [, , $receipt] = cashReceiptCancelPay();
        $pdfId = $receipt->pdf_document_id;

        cashReceiptCancelDeletePdf($receipt, confirmed: true);

        expect(Document::query()->whereKey($pdfId)->exists())->toBeFalse()
            ->and(cashReceiptCancelFresh($receipt)->pdf_document_id)->toBeNull();
    });
});
