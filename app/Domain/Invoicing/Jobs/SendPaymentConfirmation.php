<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Invoicing\Actions\QueuePaymentConfirmation;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Mail\PaymentConfirmationMail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one queued payment confirmation (Phase 13, D-05, D-06): the facts of the payment to the client, no attachment.
 *
 * The unit is the `payment_confirmations` row, one per payment. The job claims it with a conditional update (`queued`
 * to `sending`, one attempt counted); a second run, a double dispatch and a row that is sending, sent, failed, skipped
 * or gone (its payment was deleted within the grace window, the cascade took it) do nothing. A run that throws puts
 * the row back to `queued` for the next try; once the queue gives up, {@see self::failed()} marks it `failed` with a
 * fixed class. The job writes nothing but its own row, so it takes no lock on the invoice: a confirmation never locks,
 * edits or audits the document it confirms. The document e-mail stays the only lock of the system (Phase 10.1).
 *
 * Failure classes: `transport` (the mail transport refused), `invalid_recipient` (the stored address no longer passes
 * the recipient rule, final at once), `unexpected` (anything else). Only the class is stored; neither the row nor the
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

            Mail::to($recipient)->send(PaymentConfirmationMail::forPayment(
                number: (string) $invoice->number,
                amount: $payment->amount(),
                paidOn: $payment->paid_on,
                remainingMinor: $row->remaining_minor,
                language: $row->language,
            ));

            $this->markSent();
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
     * The conditional update from `sending` to `sent`: it changes the row only while this run still holds it.
     */
    private function markSent(): void
    {
        PaymentConfirmation::query()
            ->whereKey($this->confirmationId)
            ->where('state', PaymentConfirmationState::Sending->value)
            ->update([
                'state' => PaymentConfirmationState::Sent->value,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
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
