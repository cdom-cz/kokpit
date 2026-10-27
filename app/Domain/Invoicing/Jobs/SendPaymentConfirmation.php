<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\QueuePaymentConfirmation;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Mail\PaymentConfirmationMail;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Invoicing\Pdf\CashReceiptPdf;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one queued payment confirmation (Phase 13, D-05, D-06): the facts of the payment to the client. The only
 * attachment is the receipt of a cash payment (Phase 15, D-12).
 *
 * The unit is the `payment_confirmations` row, one per payment. The job claims it with a conditional update (`queued`
 * to `sending`, one attempt counted); a second run, a double dispatch and a row that is sending, sent, failed, skipped
 * or gone (its payment was deleted within the grace window, the cascade took it) do nothing. A run that throws puts
 * the row back to `queued` for the next try; once the queue gives up, {@see self::failed()} marks it `failed` with a
 * fixed class. The job takes no lock on and writes nothing to the invoice: a confirmation never locks, edits or audits
 * the document it confirms. The document e-mail stays the only lock of an invoice (Phase 10.1).
 *
 * The confirmation of a cash payment carries the receipt of that payment (Phase 15, D-12): the stored PDF as read at
 * send time, so an edit within the grace window is what the client gets (D-11), and its successful send locks that
 * receipt (D-10), a different row in a different table, in one transaction with the sent mark and with one audit row.
 * A receipt cancelled before the send is neither attached nor locked and the confirmation still goes out (AS-1); a
 * confirmation that is skipped, failed or never sent leaves the receipt open (AS-10); a receipt whose stored PDF is
 * missing fails the confirmation with `attachment_missing`, so nothing is sent without its receipt.
 *
 * Failure classes: `transport` (the mail transport refused), `invalid_recipient` (the stored address no longer passes
 * the recipient rule, final at once), `attachment_missing` (the stored PDF of the receipt is gone, final at once),
 * `unexpected` (anything else). Only the class is stored; neither the row nor the
 * failed-job alert holds a provider answer or the recipient (T-13-07). A final failure reaches the Admin through the
 * failed-job report of {@see KokpitJob}, not a notice of this class.
 *
 * Delivery is at-least-once, like the document e-mail: what can send twice is a run that fails after the transport
 * accepted the message, while the row is being marked sent.
 */
#[Idempotent(how: 'identified by the confirmation row id, one per payment by a unique key; the row is claimed by a conditional update from queued to sending, so a row that is sending, sent, failed, skipped or gone is left alone and a rerun of a finished row sends nothing')]
final class SendPaymentConfirmation extends KokpitJob
{
    public const string FAILURE_TRANSPORT = 'transport';

    public const string FAILURE_UNEXPECTED = 'unexpected';

    public const string FAILURE_INVALID_RECIPIENT = 'invalid_recipient';

    public const string FAILURE_ATTACHMENT_MISSING = 'attachment_missing';

    /** The message the queue and the failed-job alert see for a refused transport. */
    private const string TRANSPORT_FAILED = 'The mail transport refused the message.';

    public function __construct(public readonly string $confirmationId) {}

    public function handle(): void
    {
        // The id comes from the queue payload: a value that is no UUID names no row.
        if (! Str::isUuid($this->confirmationId)) {
            return;
        }

        if (! $this->claim()) {
            return;
        }

        $row = PaymentConfirmation::query()->findOrFail($this->confirmationId);

        try {
            $recipient = QueuePaymentConfirmation::cleanAddress($row->recipient);

            if ($recipient === null) {
                $this->giveUp(self::FAILURE_INVALID_RECIPIENT);

                return;
            }

            $payment = InvoicePayment::query()->findOrFail($row->invoice_payment_id);
            $invoice = Invoice::query()->findOrFail($row->invoice_id);

            // Phase 15 (D-12): the receipt of a cash payment travels with the confirmation. It is read now, so what the
            // client gets is the stored PDF of this moment (D-11); a cancelled receipt is not attached (AS-1).
            $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->first();
            $attachment = null;

            if ($receipt instanceof CashReceipt && ! $receipt->isCancelled()) {
                $attachment = $this->receiptAttachment($receipt);

                if ($attachment === null) {
                    $this->giveUp(self::FAILURE_ATTACHMENT_MISSING);

                    return;
                }
            }

            Mail::to($recipient)->send(PaymentConfirmationMail::forPayment(
                number: (string) $invoice->number,
                amount: $payment->amount(),
                paidOn: $payment->paid_on,
                remainingMinor: $row->remaining_minor,
                language: $row->language,
                receipt: $attachment,
            ));

            $this->markSent($attachment === null ? null : $receipt?->id);
        } catch (Throwable $e) {
            // Back to queued so the next try can claim the row again; the queue decides when it gives up.
            PaymentConfirmation::query()
                ->whereKey($row->id)
                ->where('state', PaymentConfirmationState::Sending->value)
                ->update(['state' => PaymentConfirmationState::Queued->value, 'updated_at' => now()]);

            // The provider's answer can name the recipient, and the failed-job alert prints the first line of a
            // message into the bell (T-13-07): a transport failure leaves as a fixed message. The original stays
            // as the previous exception, which only the failed_jobs record and the log hold.
            throw $e instanceof TransportExceptionInterface ? new RuntimeException(self::TRANSPORT_FAILED, 0, $e) : $e;
        }
    }

    /**
     * The queue gave up. The row is marked failed with a fixed class; a row that is already sent, failed, skipped or
     * gone is left alone. The job middleware does not wrap this method, so the system context is entered here.
     */
    public function failed(Throwable $exception): void
    {
        app(PartnerContext::class)->runAsSystem(function () use ($exception): void {
            $transport = $exception instanceof TransportExceptionInterface || $exception->getPrevious() instanceof TransportExceptionInterface;

            $this->giveUp($transport ? self::FAILURE_TRANSPORT : self::FAILURE_UNEXPECTED);
        });
    }

    /**
     * Claims the row for this run with one conditional update, `queued` to `sending`, one attempt counted. True when
     * this run claimed it; a row that is sending, sent, failed, skipped or gone is left alone.
     */
    private function claim(): bool
    {
        return PaymentConfirmation::query()
            ->whereKey($this->confirmationId)
            ->where('state', PaymentConfirmationState::Queued->value)
            ->update([
                'state' => PaymentConfirmationState::Sending->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * The conditional update from `sending` to `sent` and, for a delivered cash receipt, its lock, in one transaction
     * (D-10, D-12). The receipt row is locked first; then the conditional update `sending` to `sent`; the lock follows
     * only when that update changed the row and the receipt is neither cancelled nor locked yet. It is saved through the
     * model and {@see CashReceiptAudit::locked()} writes its one history row in the same transaction. A failure rolls
     * both back and reaches the catch of {@see self::handle()}, which puts the row back to queued.
     *
     * The cancel of a receipt locks the invoice, the payment and then the receipt row; this job locks only the receipt
     * row and reads `cancelled_at` and `lock_state` under that lock, so a cancel and a send serialize on the receipt
     * row: a receipt cancelled first is never locked by the send, and a receipt locked first can still be cancelled.
     * The job never takes the invoice or payment row lock, so the two cannot deadlock.
     *
     * @param  string|null  $receiptId  the receipt that was attached, null when none was
     */
    private function markSent(?string $receiptId): void
    {
        DB::transaction(function () use ($receiptId): void {
            $receipt = $receiptId === null ? null : CashReceipt::query()->whereKey($receiptId)->lockForUpdate()->first();

            $marked = PaymentConfirmation::query()
                ->whereKey($this->confirmationId)
                ->where('state', PaymentConfirmationState::Sending->value)
                ->update([
                    'state' => PaymentConfirmationState::Sent->value,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($marked === 1 && $receipt instanceof CashReceipt && ! $receipt->isCancelled() && ! $receipt->isLocked()) {
                $receipt->forceFill(['lock_state' => CashReceiptLockState::Locked])->save();

                CashReceiptAudit::locked($receipt, $this->creatorOf($receipt));
            }
        });
    }

    /**
     * The file name, the bytes and the number of the receipt as the mail carries them, or null when the stored PDF is
     * not there (no link, no object or an empty one). The file name is generated, never typed text.
     *
     * @return array{name: string, data: string, number: string}|null
     */
    private function receiptAttachment(CashReceipt $receipt): ?array
    {
        $document = $receipt->pdf_document_id === null ? null : Document::query()->find($receipt->pdf_document_id);
        $media = $document?->getFirstMedia(Document::MEDIA_COLLECTION);

        if ($media === null) {
            return null;
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return ['name' => app(CashReceiptPdf::class)->fileName($receipt), 'data' => $bytes, 'number' => $receipt->number];
    }

    /**
     * The user who recorded the payment of the receipt, the causer of the lock row; null when it cannot be found.
     */
    private function creatorOf(CashReceipt $receipt): ?User
    {
        $creatorId = InvoicePayment::query()->whereKey($receipt->invoice_payment_id)->value('created_by');

        return is_string($creatorId) ? User::query()->find($creatorId) : null;
    }

    /**
     * Marks a row that is still queued or sending failed with a fixed class.
     */
    private function giveUp(string $failureCode): void
    {
        if (! Str::isUuid($this->confirmationId)) {
            return;
        }

        PaymentConfirmation::query()
            ->whereKey($this->confirmationId)
            ->whereIn('state', [PaymentConfirmationState::Queued->value, PaymentConfirmationState::Sending->value])
            ->update([
                'state' => PaymentConfirmationState::Failed->value,
                'failure_code' => $failureCode,
                'updated_at' => now(),
            ]);
    }
}
