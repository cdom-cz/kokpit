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
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Invoicing\Support\PaymentInput;
use App\Domain\Payments\Support\PaymentLinks;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

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
 * converted into an invoice, by {@see RecordPayment::carry()}, with the real payment date. The CZK figure of the
 * income follows the finance rule, the CNB list of the income date; without one the payment fails under the date and
 * nothing is stored.
 *
 * Data keys: see {@see PaymentInput}.
 *
 * Two callers, one core: {@see RecordPayment::handle()} is the Admin's, with the Gate; {@see RecordPayment::recordLocked()}
 * is what both it and a queue job without a user (a matched Stripe card payment) run once the document is locked, so a
 * payment arriving by card obeys the same rules, income included, as one typed by hand.
 */
final readonly class RecordPayment
{
    public function __construct(
        private RecordTransaction $transactions,
        private InvoiceBalances $balances,
        private ExchangeRateConverter $rates,
        private PaymentLinks $paymentLinks,
    ) {}

    /**
     * The manual path: the Admin records a payment by hand. The Gate, the transaction and the lock of the document
     * are here; everything after the lock is {@see RecordPayment::recordLocked()}, shared with the system path.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Invoice $invoice, array $data): InvoicePayment
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        return DB::transaction(function () use ($actor, $invoice, $data): InvoicePayment {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($this->refuses($locked)) {
                throw ValidationException::withMessages(['payment' => __('invoices.errors.payment_not_allowed')]);
            }

            return $this->recordLocked($locked, PaymentInput::fromArray($data, $locked, IssueContext::today())->toFacts(), $actor);
        });
    }

    /**
     * The write core of a payment, for a caller that already holds the lock of the document inside its transaction:
     * the manual path above, and a queue job that matched a Stripe payment, which has no user (PA-06, PA-08; D-10,
     * D-13). It checks that the document can be paid (`payment_not_allowed`), that the CNB list of the income date
     * exists for an invoice in a foreign currency, inserts the payment and, for an invoice, books the income through
     * the one finance write path (CONTEXT rule: income only through the rules of RecordPayment).
     *
     * It does no authorisation of its own: the caller authorised (the Admin through the Gate in `handle()`, the system
     * through the signed webhook and the matching). That is why it is guarded instead: it throws outside a transaction,
     * and `$actor` may be null only for a card payment, because no person records one (the database holds the same
     * rule in `invoice_payments_creator_check`). A payment without a creator is traced by its Stripe record and the
     * activity log, which then has no causer.
     *
     * @param  User|null  $actor  the person who records the payment, null only for a card payment of the system
     *
     * @throws LogicException outside a transaction, or without an actor for a method other than card
     * @throws ValidationException `payment_not_allowed`, or `cnb_missing` when the list of the income date is missing
     */
    public function recordLocked(Invoice $locked, PaymentFacts $facts, ?User $actor): InvoicePayment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A payment is recorded inside the caller\'s transaction, with the document locked.');
        }

        if ($actor === null && $facts->method !== PaymentMethod::Card) {
            throw new LogicException('Only a card payment is recorded without a person.');
        }

        if ($this->refuses($locked)) {
            throw ValidationException::withMessages(['payment' => __('invoices.errors.payment_not_allowed')]);
        }

        $books = $locked->kind === InvoiceKind::Invoice;
        $dateKey = $facts->incomeDate === $facts->paidOn ? 'paid_on' : 'income_date';

        if ($books) {
            $this->assertRateExists($locked->currency, $facts->incomeDate, $dateKey);
        }

        $payment = new InvoicePayment(['note' => $facts->note]);
        $payment->forceFill([
            'invoice_id' => $locked->id,
            'currency' => $locked->currency,
            'amount_minor' => $facts->amount->minor,
            'paid_on' => $facts->paidOn,
            'method' => $facts->method,
            'created_by' => $actor?->getKey(),
        ]);
        $payment->save();

        if ($books) {
            $this->bookIncome($locked, $payment, $facts->amount, $facts->incomeDate, $facts->categoryId, $dateKey);
        }

        // D-08: what is owed changed, so a live payment link that offers the old amount is deactivated in this transaction.
        $this->paymentLinks->reconcile($locked);

        return $payment->refresh();
    }

    private function refuses(Invoice $locked): bool
    {
        return $locked->state !== InvoiceState::Issued || ! $locked->kind->carriesPayment() || $locked->isCancelled();
    }

    /**
     * Carries a payment of a proforma over to the invoice made from it (D-15, D-18), inside the transaction of the
     * conversion: a new payment row of the invoice with the same amount, currency, date, method and note and the
     * link `carried_from_payment_id`, and the income of it, booked now with the real payment date in the system
     * income category, because a proforma books no income. The source payment is neither moved nor edited, it stays
     * the history of the proforma (D-19). The caller holds the lock of the proforma and has checked that it is paid.
     *
     * A foreign-currency payment needs the CNB list of its payment date for the CZK figure of the income; without
     * one the carry fails under `cnb_missing` and the caller's transaction takes everything back.
     *
     * @throws LogicException outside a transaction, or when the payment is not one of the invoice's proforma
     * @throws ValidationException `cnb_missing` when the list of the payment date is missing
     */
    public function carry(User $actor, InvoicePayment $proformaPayment, Invoice $invoice): InvoicePayment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A payment is carried over inside the caller\'s transaction.');
        }

        Gate::forUser($actor)->authorize('update', $invoice);

        if ($invoice->kind !== InvoiceKind::Invoice || $invoice->state !== InvoiceState::Issued
            || $invoice->proforma_id === null || $invoice->proforma_id !== $proformaPayment->invoice_id
            || $invoice->currency !== $proformaPayment->currency) {
            throw new LogicException('Only a payment of the proforma of an issued invoice is carried over to it.');
        }

        $paidOn = $proformaPayment->paid_on->format('Y-m-d');
        $amount = $proformaPayment->amount();

        $this->assertRateExists($invoice->currency, $paidOn, 'cnb_missing');

        $payment = new InvoicePayment(['note' => $proformaPayment->note]);
        $payment->forceFill([
            'invoice_id' => $invoice->id,
            'currency' => $invoice->currency,
            'amount_minor' => $proformaPayment->amount_minor,
            'paid_on' => $paidOn,
            'method' => $proformaPayment->method,
            'carried_from_payment_id' => $proformaPayment->id,
            'created_by' => $actor->getKey(),
        ]);
        $payment->save();

        $this->bookIncome($invoice, $payment, $amount, $paidOn, null, 'cnb_missing');

        return $payment->refresh();
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
     * The income of the payment, through the one finance write path, with the invoice and payment link. The payment
     * itself was authorised by the caller, so the income needs no Gate of its own ({@see RecordTransaction::writePaymentIncome()}).
     * A card payment has its own description, "Platba kartou faktury :number".
     *
     * @throws ValidationException
     */
    private function bookIncome(Invoice $invoice, InvoicePayment $payment, Money $amount, string $incomeDate, ?string $categoryId, string $dateKey): void
    {
        $customer = $invoice->customer_snapshot['name'] ?? null;
        $description = $payment->method === PaymentMethod::Card ? 'invoices.payment_income.card_description' : 'invoices.payment_income.description';

        try {
            $this->transactions->writePaymentIncome([
                'type' => 'income',
                'transaction_date' => $incomeDate,
                'category_id' => $categoryId ?? TransactionCategory::invoicePayments()->getKey(),
                'amount' => $amount->toMajor(),
                'currency' => $invoice->currency,
                'description' => __($description, ['number' => (string) $invoice->number]),
                'counterparty' => is_string($customer) ? mb_substr($customer, 0, 255) : null,
                'document_number' => $invoice->number,
                'client_id' => $invoice->client_id,
            ], ['invoice_id' => $invoice->id, 'invoice_payment_id' => $payment->id]);
        } catch (ValidationException $e) {
            // The list may disappear between the check and the booking; the answer is the same as for the check.
            if (array_key_exists('czk_amount', $e->errors())) {
                throw $this->cnbMissing($invoice->currency, $incomeDate, $dateKey);
            }

            throw $e;
        }
    }

    /**
     * Fails the payment under its date when the CNB list of the income date is missing, before anything is written.
     *
     * @throws ValidationException
     */
    private function assertRateExists(string $currency, string $day, string $key): void
    {
        if ($currency === 'CZK') {
            return;
        }

        try {
            $this->rates->rateFor($currency, $day);
        } catch (ExchangeRateMissing) {
            throw $this->cnbMissing($currency, $day, $key);
        }
    }

    /**
     * The missing-list error under the field that chose the day of the list: the income date when it differs from
     * the payment date, otherwise the payment date.
     */
    private function cnbMissing(string $currency, string $day, string $key): ValidationException
    {
        return ValidationException::withMessages([
            $key => __('invoices.errors.cnb_missing', ['currency' => $currency, 'date' => IssueChecks::czechDate($day)]),
        ]);
    }
}
