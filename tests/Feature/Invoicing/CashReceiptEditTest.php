<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\RegenerateCashReceiptPdf;
use App\Domain\Invoicing\Actions\UnlockCashReceipt;
use App\Domain\Invoicing\Actions\UpdateCashReceipt;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The limits of the edit of a cash receipt and the recreation of a missing PDF (Phase 15; D-10, D-11, AS-12; P15-07,
 * P15-08; threats T-15-29, T-15-31, T-15-32): only the allowed fields of an open receipt change, a Partner changes
 * nothing, and the Admin gets a lost PDF back from the receipt row in every state. Every name, address and amount is
 * fictional.
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
 * Records a cash payment of 1 500 CZK on a new issued invoice of a client with an invoice e-mail address and returns the
 * receipt of the payment.
 */
function cashReceiptEditPay(): CashReceipt
{
    $client = InvoiceFixtures::client(['name' => 'Example Client s.r.o.', 'invoice_email' => exampleEmail('client-invoice')]);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);

    $payment = app(RecordPayment::class)->handle(test()->admin, $invoice, ['amount' => '1500', 'paid_on' => '2026-10-14', 'method' => 'cash']);

    return CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();
}

function cashReceiptEditRow(CashReceipt $receipt): CashReceipt
{
    return CashReceipt::query()->findOrFail($receipt->id);
}

/**
 * A raw write on the receipt as the system, the way the sender and the cancel do (the guard allows the listed columns).
 *
 * @param  array<string, mixed>  $attributes
 */
function cashReceiptEditWrite(CashReceipt $receipt, array $attributes): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($receipt, $attributes): void {
        cashReceiptEditRow($receipt)->forceFill($attributes)->save();
    });
}

/**
 * Sets the state of the confirmation e-mail of the payment of the receipt, as the system.
 */
function cashReceiptEditConfirmation(CashReceipt $receipt, PaymentConfirmationState $state): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($receipt, $state): void {
        PaymentConfirmation::query()->where('invoice_payment_id', $receipt->invoice_payment_id)->sole()->forceFill(['state' => $state])->save();
    });
}

/**
 * Deletes the stored PDF of the receipt the sanctioned way (with the confirmation flag); the key clears the link.
 */
function cashReceiptEditLosePdf(CashReceipt $receipt): void
{
    app(DeleteDocument::class)->handle(test()->admin, Document::query()->findOrFail($receipt->pdf_document_id), true);
}

/**
 * The error messages of a refused call, by key.
 *
 * @param  callable(): mixed  $call
 * @return array<string, list<string>>
 */
function cashReceiptEditErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function cashReceiptEditRows(CashReceipt $receipt, string $event): int
{
    return Activity::query()
        ->where('subject_type', 'cash_receipt')
        ->where('subject_id', $receipt->id)
        ->where('event', $event)
        ->count();
}

describe('who may and what is refused', function (): void {
    it('refuses a Partner every one of the three Actions', function (): void {
        $receipt = cashReceiptEditPay();
        $partner = Canary::partnerFor($receipt->client_id);

        expect(fn () => app(UpdateCashReceipt::class)->handle($partner, $receipt, ['purpose' => 'Cizí úprava']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UnlockCashReceipt::class)->handle($partner, $receipt, 'Oprava jména plátce po odeslání'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RegenerateCashReceiptPdf::class)->handle($partner, $receipt))->toThrow(AuthorizationException::class)
            ->and(cashReceiptEditRow($receipt)->purpose)->toBe($receipt->purpose);
    });

    it('refuses a receipt date after the issue day and one that is not a day', function (): void {
        $receipt = cashReceiptEditPay();

        foreach (['2026-10-15', '2026-02-30', '14.10.2026', ''] as $value) {
            $errors = cashReceiptEditErrors(fn () => app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['received_on' => $value]));

            expect($errors)->toHaveKey('received_on')
                ->and($errors['received_on'][0])->toBe(__('cash_receipts.errors.received_on_invalid'));
        }

        expect(cashReceiptEditRow($receipt)->received_on->format('Y-m-d'))->toBe('2026-10-14');
    });

    it('refuses an empty purpose and one of 501 characters but accepts one of 500', function (): void {
        $receipt = cashReceiptEditPay();

        foreach (['', '   ', str_repeat('x', 501)] as $value) {
            $errors = cashReceiptEditErrors(fn () => app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['purpose' => $value]));

            expect($errors)->toHaveKey('purpose')
                ->and($errors['purpose'][0])->toBe(__('cash_receipts.errors.purpose_invalid'));
        }

        $edited = app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['purpose' => str_repeat('y', 500)]);

        expect(mb_strlen($edited->purpose))->toBe(500);
    });

    it('refuses a customer name longer than the client column allows', function (): void {
        $receipt = cashReceiptEditPay();

        $errors = cashReceiptEditErrors(fn () => app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['customer' => ['name' => str_repeat('n', 256)]]));

        expect($errors)->toHaveKey('customer.name')
            ->and(cashReceiptEditRow($receipt)->customer_snapshot)->toBe($receipt->customer_snapshot);
    });

    it('ignores the amount, the currency, the number and the language in the data', function (): void {
        $receipt = cashReceiptEditPay();

        $edited = app(UpdateCashReceipt::class)->handle($this->admin, $receipt, [
            'purpose' => 'Úhrada v hotovosti na pobočce',
            'amount_minor' => 999,
            'currency' => 'EUR',
            'number' => 'PPD-OTHER',
            'language' => 'en',
        ]);

        $row = cashReceiptEditRow($receipt);

        expect($edited->purpose)->toBe('Úhrada v hotovosti na pobočce')
            ->and($row->amount_minor)->toBe($receipt->amount_minor)
            ->and($row->currency)->toBe($receipt->currency)
            ->and($row->number)->toBe($receipt->number)
            ->and($row->language)->toBe($receipt->language);
    });

    it('refuses the edit and the unlock of a cancelled receipt', function (): void {
        $receipt = cashReceiptEditPay();
        cashReceiptEditWrite($receipt, ['cancelled_at' => CarbonImmutable::parse('2026-10-14 09:35:00', 'Europe/Prague')]);

        $edit = cashReceiptEditErrors(fn () => app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['purpose' => 'Úhrada v hotovosti na pobočce']));
        $unlock = cashReceiptEditErrors(fn () => app(UnlockCashReceipt::class)->handle($this->admin, $receipt, 'Oprava jména plátce po odeslání'));

        expect($edit)->toHaveKey('receipt')
            ->and($edit['receipt'][0])->toBe(__('cash_receipts.errors.cancelled'))
            ->and($unlock)->toHaveKey('receipt')
            ->and($unlock['receipt'][0])->toBe(__('cash_receipts.errors.cancelled'))
            ->and(cashReceiptEditRow($receipt)->purpose)->toBe($receipt->purpose);
    });

    it('refuses the edit while the confirmation is being sent and allows it while it is queued', function (): void {
        $receipt = cashReceiptEditPay();

        cashReceiptEditConfirmation($receipt, PaymentConfirmationState::Sending);

        $errors = cashReceiptEditErrors(fn () => app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['purpose' => 'Úhrada v hotovosti na pobočce']));

        expect($errors)->toHaveKey('receipt')
            ->and($errors['receipt'][0])->toBe(__('cash_receipts.errors.sending'))
            ->and(cashReceiptEditRow($receipt)->purpose)->toBe($receipt->purpose);

        cashReceiptEditConfirmation($receipt, PaymentConfirmationState::Queued);

        $edited = app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['purpose' => 'Úhrada v hotovosti na pobočce']);

        expect($edited->purpose)->toBe('Úhrada v hotovosti na pobočce');
    });

    it('writes nothing and keeps the same PDF for an edit that changes nothing', function (): void {
        $receipt = cashReceiptEditPay();

        $same = app(UpdateCashReceipt::class)->handle($this->admin, $receipt, [
            'received_on' => $receipt->received_on->format('Y-m-d'),
            'purpose' => $receipt->purpose,
            'customer' => ['name' => $receipt->customer_snapshot['name'] ?? null],
        ]);

        expect($same->pdf_document_id)->toBe($receipt->pdf_document_id)
            ->and(cashReceiptEditRow($receipt)->updated_at?->toIso8601String())->toBe($receipt->updated_at?->toIso8601String())
            ->and(cashReceiptEditRows($receipt, 'cash_receipt_edited'))->toBe(0);
    });
});

describe('a missing PDF is created again from the receipt row (AS-12)', function (): void {
    it('links a new PDF of an open receipt after the stored one was deleted', function (): void {
        $receipt = cashReceiptEditPay();
        cashReceiptEditLosePdf($receipt);

        expect(cashReceiptEditRow($receipt)->pdf_document_id)->toBeNull();

        $document = app(RegenerateCashReceiptPdf::class)->handle($this->admin, $receipt);
        $row = cashReceiptEditRow($receipt);
        $media = $document->getFirstMedia(Document::MEDIA_COLLECTION);

        expect($row->pdf_document_id)->toBe($document->getKey())
            ->and($document->owner_type)->toBe('cash_receipt')
            ->and($document->owner_id)->toBe($receipt->id)
            ->and($media)->not->toBeNull()
            ->and(Storage::disk($media?->disk)->get((string) $media?->getPathRelativeToRoot()))->toStartWith('%PDF-')
            ->and($row->lock_state)->toBe(CashReceiptLockState::Open);
    });

    it('does the same for a locked receipt without changing its lock', function (): void {
        $receipt = cashReceiptEditPay();
        cashReceiptEditWrite($receipt, ['lock_state' => CashReceiptLockState::Locked]);
        cashReceiptEditLosePdf($receipt);

        $document = app(RegenerateCashReceiptPdf::class)->handle($this->admin, $receipt);
        $row = cashReceiptEditRow($receipt);

        expect($row->pdf_document_id)->toBe($document->getKey())
            ->and($row->lock_state)->toBe(CashReceiptLockState::Locked);
    });

    it('does the same for a cancelled receipt', function (): void {
        $receipt = cashReceiptEditPay();
        cashReceiptEditWrite($receipt, ['cancelled_at' => CarbonImmutable::parse('2026-10-14 09:35:00', 'Europe/Prague')]);
        cashReceiptEditLosePdf($receipt);

        $document = app(RegenerateCashReceiptPdf::class)->handle($this->admin, $receipt);
        $row = cashReceiptEditRow($receipt);

        expect($row->pdf_document_id)->toBe($document->getKey())
            ->and($row->isCancelled())->toBeTrue();
    });

    it('refuses when the receipt still has its PDF and replaces nothing', function (): void {
        $receipt = cashReceiptEditPay();

        $errors = cashReceiptEditErrors(fn () => app(RegenerateCashReceiptPdf::class)->handle($this->admin, $receipt));

        expect($errors)->toHaveKey('receipt')
            ->and($errors['receipt'][0])->toBe(__('cash_receipts.errors.pdf_present'))
            ->and(cashReceiptEditRow($receipt)->pdf_document_id)->toBe($receipt->pdf_document_id)
            ->and(Document::query()->where('owner_type', 'cash_receipt')->where('owner_id', $receipt->id)->count())->toBe(1);
    });
});
