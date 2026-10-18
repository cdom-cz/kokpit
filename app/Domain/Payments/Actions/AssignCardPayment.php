<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Invoicing\Support\PaymentInput;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Admin books a duplicate or unmatched card payment to a document (PA-08; D-13, ruling R11-19-1): one transaction
 * that writes the payment and its income through the rules of {@see RecordPayment::recordLocked()} (method card, the
 * Admin as creator, the link of the document reconciled there) and then resolves the record as assigned, for good.
 *
 * Lock order: the document first, then the record, the order of the webhook matching. A resolved record is refused with
 * `already_resolved` (key `card_payment`), a matched or refund record with `not_assignable`, a document in another
 * currency with `currency_mismatch` under `invoice_id`; the refusals of the payment itself, a cancelled document for
 * one, stand. An amount that differs from what the document owes is the screen's warning, never a refusal here.
 *
 * Data keys: `invoice_id`, `paid_on` (a Prague day not after today, default the day the payment was received),
 * `income_date`, `category_id` (both ignored on a proforma, see {@see PaymentInput}) and `note` (at most 500
 * characters). The Admin only.
 */
final readonly class AssignCardPayment
{
    public function __construct(private RecordPayment $payments) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, CardPayment $record, array $data): CardPayment
    {
        Gate::forUser($actor)->authorize('update', $record);

        return DB::transaction(function () use ($actor, $record, $data): CardPayment {
            $document = $this->lockDocument($data['invoice_id'] ?? null);
            $locked = CardPayment::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->resolution !== CardPaymentResolution::None) {
                throw ValidationException::withMessages(['card_payment' => __('card_payments.errors.already_resolved')]);
            }

            if (! in_array($locked->type, [CardPaymentType::Duplicate, CardPaymentType::Unmatched], true)) {
                throw ValidationException::withMessages(['card_payment' => __('card_payments.errors.not_assignable')]);
            }

            if ($document->currency !== $locked->currency) {
                throw ValidationException::withMessages(['invoice_id' => __('card_payments.errors.currency_mismatch', ['currency' => $locked->currency])]);
            }

            $input = PaymentInput::fromArray([
                'amount' => $locked->amount()->toMajor(),
                'paid_on' => $data['paid_on'] ?? $this->receivedDay($locked),
                'method' => PaymentMethod::Transfer->value,
                'income_date' => $data['income_date'] ?? null,
                'category_id' => $this->category($data['category_id'] ?? null),
                'note' => $data['note'] ?? null,
            ], $document, IssueContext::today());

            // The input only checked the typed values; the payment is a card payment of the Admin's decision.
            $payment = $this->payments->recordLocked($document, new PaymentFacts(
                $input->amount,
                $input->paidOn,
                PaymentMethod::Card,
                $input->incomeDate,
                $input->categoryId,
                $input->note,
            ), $actor);

            $locked->forceFill([
                'resolution' => CardPaymentResolution::Assigned,
                'resolved_at' => CarbonImmutable::now(),
                'resolved_by' => $actor->getKey(),
                'assigned_invoice_id' => $document->getKey(),
                'invoice_payment_id' => $payment->getKey(),
            ])->save();

            return $locked;
        });
    }

    /**
     * The document, locked. An unknown id, a draft and a credit note are one answer: the Admin must choose a document
     * that can be paid.
     *
     * @throws ValidationException
     */
    private function lockDocument(mixed $id): Invoice
    {
        $unavailable = ValidationException::withMessages(['invoice_id' => __('card_payments.errors.document_required')]);

        if (! is_string($id) || ! Str::isUuid($id)) {
            throw $unavailable;
        }

        $document = Invoice::query()->whereKey(strtolower($id))->lockForUpdate()->first();

        if (! $document instanceof Invoice || $document->state !== InvoiceState::Issued || $document->kind === InvoiceKind::CreditNote) {
            throw $unavailable;
        }

        return $document;
    }

    /**
     * The system income category is chosen as "no category", which means the same and is what the write path takes.
     */
    private function category(mixed $value): mixed
    {
        return is_string($value) && $value === TransactionCategory::invoicePayments()->getKey() ? null : $value;
    }

    /**
     * The Prague calendar day the payment was received.
     */
    private function receivedDay(CardPayment $record): string
    {
        return $record->received_at->setTimezone(FilamentTimezone::get())->format('Y-m-d');
    }
}
