<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Support\ClientDocumentEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * Queues the confirmation e-mail of one payment credited to an invoice or proforma (Phase 13, D-05, D-06), inside the
 * transaction of the payment: {@see RecordPayment::recordLocked()} calls it once the payment is stored, so the manual
 * path, a matched Stripe card payment and the Admin's assignment of one all confirm the same way.
 *
 * It writes the one `payment_confirmations` row of the payment (the unique key of the table refuses a second) with
 * the recipient, the language of the client and the amount still owed right after this payment, a snapshot taken from
 * {@see InvoiceBalances} in the same transaction, so each confirmation describes the document as that payment left it
 * even when two payments land close together. With a recipient it dispatches {@see SendPaymentConfirmation} after the
 * commit and after a grace window of {@see self::GRACE_SECONDS}: a payment deleted within it takes its row with it (the
 * cascade of the table) and nothing is sent, so the usual correction (delete the mistaken payment, record the right one)
 * mails one confirmation (assumption A-13-6). A client without a usable invoice e-mail gets a `skipped` row and no job;
 * the payment is never blocked.
 *
 * Phase 16 (D-06): a client with the switch "send documents by e-mail" off gets a `skipped` row as well, written
 * directly, with the address it would have gone to kept and no job dispatched. The client is the one read fresh in the
 * payment transaction, so the decision is the switch at the moment of the payment; the payment, its income and its cash
 * receipt are recorded as always, and the receipt stays open for the Admin's manual lock.
 *
 * The recipient is the invoice e-mail of the client of the document, read here in the payment transaction, never a
 * typed or request value (T-13-11). An archived client keeps getting confirmations. The language is the client's invoice
 * language (D-05), not the language of the document.
 *
 * The confirmation of a cash payment carries the cash receipt of that payment (Phase 15, D-12): the job attaches the
 * stored PDF at send time, and the grace window is what lets an edit or a cancel of the receipt happen before it
 * leaves. Nothing about the receipt is decided here; the row is queued exactly like any other.
 *
 * A credit note is never paid (RecordPayment refuses it) and the carried copy of a proforma payment on the converted
 * invoice does not pass through the write core, so neither queues one. To stop confirming proforma payments (A-13-1),
 * return early for {@see InvoiceKind::Proforma} here.
 */
final readonly class QueuePaymentConfirmation
{
    /** Seconds the e-mail waits before it leaves, so an immediate correction mails only the corrected payment. */
    public const int GRACE_SECONDS = 300;

    private const int RECIPIENT_MAX = 255;

    public function __construct(private InvoiceBalances $balances) {}

    /**
     * @throws LogicException outside a transaction
     */
    public function handle(Invoice $locked, InvoicePayment $payment): PaymentConfirmation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A payment confirmation is queued inside the transaction of the payment.');
        }

        $client = self::clientOf($locked);
        $recipient = self::cleanAddress($client?->invoice_email);
        $enabled = ClientDocumentEmails::allows($client);
        $remaining = $this->balances->for([(string) $locked->getKey()])[(string) $locked->getKey()]['remaining'] ?? 0;

        $row = new PaymentConfirmation;
        $row->forceFill([
            'invoice_payment_id' => $payment->id,
            'invoice_id' => $locked->id,
            'recipient' => $recipient,
            'language' => $client?->invoice_language->value ?? $locked->language->value,
            'remaining_minor' => max(0, $remaining),
            'state' => $recipient === null || ! $enabled ? PaymentConfirmationState::Skipped : PaymentConfirmationState::Queued,
            'attempts' => 0,
        ])->save();

        if ($recipient !== null && $enabled) {
            SendPaymentConfirmation::dispatch($row->id)->afterCommit()->delay(now()->addSeconds(self::GRACE_SECONDS));
        }

        return $row;
    }

    /**
     * The address a confirmation of this document would go to: the invoice e-mail of its client, or null when there
     * is none or it is not safe to mail. The one recipient rule, also used by the record dialog.
     */
    public static function recipientFor(Invoice $invoice): ?string
    {
        return self::cleanAddress(self::clientOf($invoice)?->invoice_email);
    }

    /**
     * The address trimmed, or null when it is blank, longer than 255 characters, holds a control character or is not a
     * valid address: the rule of {@see QueueInvoiceEmail}'s recipient. The sending job checks the stored value again.
     */
    public static function cleanAddress(?string $address): ?string
    {
        $address = $address === null ? '' : trim($address);

        $valid = $address !== ''
            && preg_match('/[\x00-\x1F\x7F]/', $address) !== 1
            && mb_strlen($address) <= self::RECIPIENT_MAX
            && ! Validator::make(['recipient' => $address], ['recipient' => ['email:rfc']])->fails();

        return $valid ? $address : null;
    }

    private static function clientOf(Invoice $invoice): ?Client
    {
        return Client::query()->withTrashed()->whereKey($invoice->client_id)->first();
    }
}
