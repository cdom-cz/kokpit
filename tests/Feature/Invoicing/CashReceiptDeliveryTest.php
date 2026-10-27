<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * The delivery of a cash receipt (Phase 15; D-10, D-11, D-12; P15-07, P15-09; AS-1, AS-3, AS-10): the payment confirmation
 * of Phase 13 carries the stored PDF of the receipt of a cash payment, and its successful send locks that receipt, and
 * nothing else. The queue is held back, so each test decides when the job runs. Every name, address and amount is
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
 * Records a payment of 1 500 CZK with the queue held back, on a new issued document of a client with the given invoice
 * e-mail (null for none), and returns the payment, its confirmation row and the receipt, if any.
 *
 * @return array{0: Invoice, 1: InvoicePayment, 2: PaymentConfirmation, 3: CashReceipt|null}
 */
function cashReceiptDeliveryPay(?string $address, string $method = 'cash', InvoiceKind $kind = InvoiceKind::Invoice, string $language = 'cs'): array
{
    $client = InvoiceFixtures::client(['invoice_email' => $address, 'invoice_language' => $language]);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')], $kind);

    $payment = app(RecordPayment::class)->handle(test()->admin, $invoice, ['amount' => '1500', 'paid_on' => '2026-10-14', 'method' => $method]);

    $confirmation = app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmation => PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->sole());
    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->first();

    return [$invoice->refresh(), $payment, $confirmation, $receipt];
}

/**
 * One try of the sending job through its own middleware and without the failure handling of the queue.
 */
function cashReceiptDeliveryRun(string $confirmationId): void
{
    $job = new SendPaymentConfirmation($confirmationId);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (SendPaymentConfirmation $job): mixed => app()->call($job->handle(...)));
}

function cashReceiptDeliveryRow(string $confirmationId): PaymentConfirmation
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmation => PaymentConfirmation::query()->findOrFail($confirmationId));
}

/**
 * The receipt as the database holds it now; the receipt of a cash payment always exists.
 */
function cashReceiptDeliveryReceipt(?CashReceipt $receipt): CashReceipt
{
    return CashReceipt::query()->findOrFail($receipt?->id ?? throw new LogicException('No receipt.'));
}

/**
 * The bytes of the stored PDF of a receipt, read from the media disk.
 */
function cashReceiptDeliveryStoredBytes(?CashReceipt $receipt): string
{
    $media = Document::query()->findOrFail(cashReceiptDeliveryReceipt($receipt)->pdf_document_id)->getFirstMedia(Document::MEDIA_COLLECTION);

    return (string) Storage::disk($media?->disk)->get((string) $media?->getPathRelativeToRoot());
}

/**
 * The rows `cash_receipt_locked` of a receipt.
 */
function cashReceiptDeliveryLockRows(?CashReceipt $receipt): int
{
    return Activity::query()
        ->where('subject_type', 'cash_receipt')
        ->where('subject_id', $receipt?->id)
        ->where('event', 'cash_receipt_locked')
        ->count();
}

/**
 * The raw invoice row, so a test sees even a timestamp-only write.
 */
function cashReceiptDeliveryInvoiceRow(Invoice $invoice): object
{
    return DB::table('invoices')->where('id', $invoice->id)->first(['lock_state', 'updated_at']) ?? throw new LogicException('No invoice row.');
}

/**
 * The text a reader sees in the HTML part, white space collapsed, without style elements.
 */
function cashReceiptDeliveryVisible(Email $email): string
{
    $dom = HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);

    foreach ($dom->querySelectorAll('style, script') as $element) {
        $element->remove();
    }

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', (string) $dom->body?->textContent));
}

/**
 * A raw write on the receipt as the system, the way the Actions do (the database guard allows the listed columns only).
 *
 * @param  array<string, mixed>  $attributes
 */
function cashReceiptDeliveryWrite(?CashReceipt $receipt, array $attributes): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($receipt, $attributes): void {
        cashReceiptDeliveryReceipt($receipt)->forceFill($attributes)->save();
    });
}

describe('the receipt travels with the confirmation (D-12)', function (): void {
    it('mails the stored receipt PDF with the confirmation of a cash payment and locks the receipt in the same send', function (): void {
        $address = exampleEmail('client-invoice');
        [$invoice, , $confirmation, $receipt] = cashReceiptDeliveryPay($address);
        $before = cashReceiptDeliveryInvoiceRow($invoice);

        expect($receipt)->not->toBeNull()
            ->and($receipt?->number)->toBe('PPD2026'.'0001')
            ->and($receipt?->lock_state)->toBe(CashReceiptLockState::Open)
            ->and(MailFixtures::sent())->toBe([]);

        cashReceiptDeliveryRun($confirmation->id);

        $messages = MailFixtures::to($address);

        expect($messages)->toHaveCount(1);

        $attachments = $messages[0]->getAttachments();

        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]->getFilename())->toBe('prijmovy-doklad-'.$receipt?->number.'.pdf')
            ->and($attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype())->toBe('application/pdf')
            ->and($attachments[0]->getBody())->toBe(cashReceiptDeliveryStoredBytes($receipt))
            ->and(cashReceiptDeliveryVisible($messages[0]))->toContain('V příloze posíláme příjmový pokladní doklad č. '.$receipt?->number.'.')
            ->and(cashReceiptDeliveryRow($confirmation->id)->state)->toBe(PaymentConfirmationState::Sent)
            ->and(cashReceiptDeliveryReceipt($receipt)->lock_state)->toBe(CashReceiptLockState::Locked)
            ->and(cashReceiptDeliveryLockRows($receipt))->toBe(1)
            ->and(cashReceiptDeliveryInvoiceRow($invoice))->toEqual($before);
    });

    it('does the same for a cash payment of a proforma (AS-3)', function (): void {
        $address = exampleEmail('client-invoice');
        [$proforma, , $confirmation, $receipt] = cashReceiptDeliveryPay($address, 'cash', InvoiceKind::Proforma);
        $before = cashReceiptDeliveryInvoiceRow($proforma);

        cashReceiptDeliveryRun($confirmation->id);

        $messages = MailFixtures::to($address);

        expect($messages)->toHaveCount(1)
            ->and($messages[0]->getAttachments())->toHaveCount(1)
            ->and($messages[0]->getAttachments()[0]->getBody())->toBe(cashReceiptDeliveryStoredBytes($receipt))
            ->and(cashReceiptDeliveryReceipt($receipt)->lock_state)->toBe(CashReceiptLockState::Locked)
            ->and(cashReceiptDeliveryInvoiceRow($proforma))->toEqual($before);
    });

    it('names the receipt in English for an English client', function (): void {
        $address = exampleEmail('client-invoice');
        [, , $confirmation, $receipt] = cashReceiptDeliveryPay($address, 'cash', InvoiceKind::Invoice, 'en');

        cashReceiptDeliveryRun($confirmation->id);

        $messages = MailFixtures::to($address);

        expect($messages)->toHaveCount(1)
            ->and($messages[0]->getAttachments()[0]->getFilename())->toBe('cash-receipt-'.$receipt?->number.'.pdf')
            ->and(cashReceiptDeliveryVisible($messages[0]))->toContain('The cash receipt no. '.$receipt?->number.' is attached.');
    });

    it('sends a transfer payment confirmation without an attachment and without the receipt sentence', function (): void {
        $address = exampleEmail('client-invoice');
        [, , $confirmation, $receipt] = cashReceiptDeliveryPay($address, 'transfer');

        cashReceiptDeliveryRun($confirmation->id);

        $messages = MailFixtures::to($address);

        expect($receipt)->toBeNull()
            ->and($messages)->toHaveCount(1)
            ->and($messages[0]->getAttachments())->toBe([])
            ->and(cashReceiptDeliveryVisible($messages[0]))->not->toContain('V příloze')
            ->and(cashReceiptDeliveryRow($confirmation->id)->state)->toBe(PaymentConfirmationState::Sent);
    });

    it('sends nothing and writes no second audit row when the job runs a second time', function (): void {
        $address = exampleEmail('client-invoice');
        [, , $confirmation, $receipt] = cashReceiptDeliveryPay($address);

        cashReceiptDeliveryRun($confirmation->id);
        cashReceiptDeliveryRun($confirmation->id);

        expect(MailFixtures::to($address))->toHaveCount(1)
            ->and(cashReceiptDeliveryLockRows($receipt))->toBe(1);
    });
});
