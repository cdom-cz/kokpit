<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\DocumentNumbering;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues the cash receipt (PPD) of one cash payment (Phase 15; D-06, D-07, D-09, D-11), inside the transaction of the
 * payment: {@see RecordPayment::recordLocked()} calls it once the payment, its income and the payment link are done,
 * for a cash payment only. It takes the next number of the `cash_receipt` series for the issue day (the Prague day of
 * today, so a back-dated payment takes a number of the current year and keeps its payment date as the receipt date,
 * D-09), copies everything the receipt prints from the locked document and the payment, writes the row and the one
 * Admin-only audit row ({@see CashReceiptAudit::issued()}). A failure anywhere after the number was allocated rolls the
 * whole payment back with it, so the number is given back and the series has no gap.
 *
 * What it copies (D-11, assumption AS-11): the amount and currency of the payment, the payment date as the receipt
 * date, the supplier, customer and appearance snapshots and the number of the paid document, so the receipt PDF is
 * made from the receipt row alone; the language is the client's invoice language (the document's language when the
 * client is gone), and the purpose is the default "payment of invoice no. N" text in that language. Last, the PDF of
 * the receipt is rendered from the saved row and stored as a document of the receipt ({@see StoreCashReceiptPdf}, D-11),
 * so a failing render rolls the whole payment back with the number.
 *
 * It does no authorisation of its own: the caller authorised the payment. The database holds the shape of the row
 * (one receipt per cash payment, the same invoice, amount, currency and document number).
 */
final readonly class IssueCashReceipt
{
    public function __construct(
        private DocumentNumbering $numbering,
        private StoreCashReceiptPdf $pdf,
    ) {}

    /**
     * @param  Invoice  $locked  the paid invoice or proforma, locked by the caller
     *
     * @throws LogicException outside a transaction, for a payment that is not a cash payment of the document, or for
     *                        a document without a number
     * @throws \RuntimeException when the PDF of the receipt cannot be created or stored; the payment fails with it
     */
    public function handle(Invoice $locked, InvoicePayment $payment, User $actor): CashReceipt
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A cash receipt is issued inside the transaction of the payment.');
        }

        if ($payment->method !== PaymentMethod::Cash || $payment->invoice_id !== $locked->id) {
            throw new LogicException('A cash receipt belongs to a cash payment of the document it is issued for.');
        }

        $documentNumber = (string) $locked->number;

        if ($documentNumber === '') {
            throw new LogicException('Only an issued document has a number to print on its receipt.');
        }

        $language = Client::query()->withTrashed()->whereKey($locked->client_id)->value('invoice_language') ?? $locked->language;
        $issueDay = IssueContext::today();

        $receipt = new CashReceipt;
        $receipt->forceFill([
            'invoice_id' => $locked->id,
            'client_id' => $locked->client_id,
            'invoice_payment_id' => $payment->id,
            'number' => $this->numbering->next(DocumentKind::CashReceipt, self::noonOf($issueDay)),
            'document_number' => $documentNumber,
            'issued_on' => $issueDay,
            'received_on' => $payment->paid_on->format('Y-m-d'),
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'purpose' => self::purpose($locked, $documentNumber, $language),
            'language' => $language,
            'supplier_snapshot' => $locked->supplier_snapshot ?? [],
            'customer_snapshot' => $locked->customer_snapshot ?? [],
            'appearance_snapshot' => $locked->appearance_snapshot ?? [],
            'lock_state' => CashReceiptLockState::Open,
        ]);
        $receipt->save();

        CashReceiptAudit::issued($receipt, $actor);

        $this->pdf->handle($actor, $receipt);

        return $receipt;
    }

    /**
     * The default purpose: "Payment of invoice no. N" or "Payment of proforma invoice no. N", in the language of the
     * receipt. The Admin may edit it while the receipt is open.
     */
    private static function purpose(Invoice $document, string $number, InvoiceLanguage $language): string
    {
        $kind = $document->kind === InvoiceKind::Proforma ? 'proforma' : 'invoice';

        return trans('cash_receipt_pdf.purpose.'.$kind, ['number' => $number], $language->value);
    }

    /**
     * Midday of a Prague calendar day: the instant the numbering reads its year from, away from every midnight and
     * every daylight-saving shift.
     */
    private static function noonOf(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $day.' 12:00', FilamentTimezone::get())
            ?? throw new LogicException('Not a calendar day: '.$day);
    }
}
