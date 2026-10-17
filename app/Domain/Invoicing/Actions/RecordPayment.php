<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\PaymentInput;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records a manual payment of an issued invoice or proforma (IN-05, FI-03; D-17, D-18, D-19): one transaction that
 * locks the document row first (the lock order shared with the conversion of a proforma and the deletion of a
 * payment), checks that the document can be paid, inserts the payment and, for an invoice, books the income through
 * the one finance write path. The Admin only.
 *
 * Several payments are normal, and a payment on a document that is already paid or above the remaining amount is
 * allowed: the screen asks for confirmation from {@see RecordPayment::warnings()}, the Action never blocks on them
 * (D-17). What it refuses is a payment on a draft, a credit note or a cancelled invoice (`payment_not_allowed`).
 * Whether a document is unpaid, partly paid or paid is derived from the sum of its payments by
 * {@see InvoiceBalances}; no state is stored.
 *
 * An invoice payment creates, in the same transaction, an income transaction in the system income category by
 * default, dated with the payment date by default, both editable when the payment is entered; the transaction
 * carries the invoice and the payment, and its amount, currency, client and texts come from the payment and the
 * document. A proforma payment is stored as a payment only (D-18): the income is booked when the proforma is
 * converted into an invoice. The CZK figure of the income follows the finance rule, the CNB list of the income date;
 * without one the payment fails under the date and nothing is stored.
 *
 * Data keys: see {@see PaymentInput}.
 */
final readonly class RecordPayment
{
    public function __construct(
        private RecordTransaction $transactions,
        private InvoiceBalances $balances,
        private ExchangeRateConverter $rates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Invoice $invoice, array $data): InvoicePayment
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        return DB::transaction(function () use ($actor, $invoice, $data): InvoicePayment {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state !== InvoiceState::Issued || ! $locked->kind->carriesPayment() || $locked->isCancelled()) {
                throw ValidationException::withMessages(['payment' => __('invoices.errors.payment_not_allowed')]);
            }

            $input = PaymentInput::fromArray($data, $locked, IssueContext::now()->today);
            $books = $locked->kind === InvoiceKind::Invoice;

            if ($books) {
                $this->assertRateExists($locked->currency, $input);
            }

            $payment = new InvoicePayment(['note' => $input->note]);
            $payment->forceFill([
                'invoice_id' => $locked->id,
                'currency' => $locked->currency,
                'amount_minor' => $input->amount->minor,
                'paid_on' => $input->paidOn,
                'method' => $input->method,
                'created_by' => $actor->getKey(),
            ]);
            $payment->save();

            if ($books) {
                $this->bookIncome($actor, $locked, $payment, $input);
            }

            return $payment->refresh();
        });
    }

    /**
     * What the screen asks the Admin to confirm before saving: `already_paid` when nothing is owed any more,
     * `above_remaining` when the amount is more than what is still owed. Empty for a document that owes nothing by
     * nature (a draft or a credit note) and for an amount in another currency.
     *
     * @return list<string>
     */
    public function warnings(Invoice $invoice, Money $amount): array
    {
        $remaining = $this->balances->for([(string) $invoice->getKey()])[(string) $invoice->getKey()]['remaining'] ?? null;

        if ($remaining === null || $amount->currency !== $invoice->currency) {
            return [];
        }

        if ($remaining <= 0) {
            return ['already_paid'];
        }

        return $amount->minor > $remaining ? ['above_remaining'] : [];
    }

    /**
     * The income of the payment, through the one finance write path, with the invoice and payment link.
     *
     * @throws ValidationException
     */
    private function bookIncome(User $actor, Invoice $invoice, InvoicePayment $payment, PaymentInput $input): void
    {
        $customer = $invoice->customer_snapshot['name'] ?? null;

        try {
            $this->transactions->handle($actor, [
                'type' => 'income',
                'transaction_date' => $input->incomeDate,
                'category_id' => $input->categoryId ?? TransactionCategory::invoicePayments()->getKey(),
                'amount' => $input->amount->toMajor(),
                'currency' => $invoice->currency,
                'description' => __('invoices.payment_income.description', ['number' => (string) $invoice->number]),
                'counterparty' => is_string($customer) ? mb_substr($customer, 0, 255) : null,
                'document_number' => $invoice->number,
                'client_id' => $invoice->client_id,
            ], ['invoice_id' => $invoice->id, 'invoice_payment_id' => $payment->id]);
        } catch (ValidationException $e) {
            // The list may disappear between the check and the booking; the answer is the same as for the check.
            if (array_key_exists('czk_amount', $e->errors())) {
                throw $this->cnbMissing($invoice->currency, $input);
            }

            throw $e;
        }
    }

    /**
     * Fails the payment under its date when the CNB list of the income date is missing, before anything is written.
     *
     * @throws ValidationException
     */
    private function assertRateExists(string $currency, PaymentInput $input): void
    {
        if ($currency === 'CZK') {
            return;
        }

        try {
            $this->rates->rateFor($currency, $input->incomeDate);
        } catch (ExchangeRateMissing) {
            throw $this->cnbMissing($currency, $input);
        }
    }

    /**
     * The missing-list error under the field that chose the day of the list: the income date when it differs from
     * the payment date, otherwise the payment date.
     */
    private function cnbMissing(string $currency, PaymentInput $input): ValidationException
    {
        $key = $input->incomeDate === $input->paidOn ? 'paid_on' : 'income_date';

        return ValidationException::withMessages([
            $key => __('invoices.errors.cnb_missing', ['currency' => $currency, 'date' => IssueChecks::czechDate($input->incomeDate)]),
        ]);
    }
}
