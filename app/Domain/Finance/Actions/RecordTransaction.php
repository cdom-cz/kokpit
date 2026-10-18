<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Api\ExternalObject;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\TransactionCzk;
use App\Domain\Finance\TransactionInput;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Records an income or expense (FI-01, D-12, D-13): the one checked entry point of a new transaction.
 *
 * The Admin only. The data is validated by {@see TransactionInput} and the CZK figure is fixed once by
 * {@see TransactionCzk}: identity for CZK, the typed figure as manual, otherwise the CNB list of the
 * transaction date with its snapshot. A foreign amount with neither a figure nor a list is refused with a
 * field error on `czk_amount`; saving never waits for a rate that is not there.
 *
 * Data keys: `type`, `transaction_date`, `category_id`, `amount`, `currency`, `czk_amount`, `czk_manual`,
 * `exchange_rate`, `exchange_unit_amount`, `description`, `counterparty`, `document_number`, `client_id`,
 * `project_id`, `external` (an optional JSON object of an importer, see {@see ExternalObject}). A typed `czk_amount` makes the figure manual unless `czk_manual` is false; an `exchange_rate`
 * (with the unit amount, default 1) makes it custom: the amount converted at that rate, stored as the snapshot.
 *
 * The optional invoice link (`invoice_id`, `invoice_payment_id`) is passed by `RecordPayment` only: it is written in
 * the same insert, with `forceFill`, never read from the data, so no request can book an income against an invoice
 * (D-19). The database checks that the invoice is of the client and the payment of the invoice.
 *
 * There are two entry points and one write. {@see RecordTransaction::handle()} is the person's, behind the Gate.
 * {@see RecordTransaction::writePaymentIncome()} is the income of a payment, without a Gate, because a queue job that
 * matches a Stripe payment has no user; it requires the invoice link and is therefore not a way to write any other
 * transaction.
 */
final readonly class RecordTransaction
{
    public function __construct(private TransactionCzk $czk) {}

    /**
     * The entry point of a person: the Gate first, then the shared write.
     *
     * @param  array<string, mixed>  $data
     * @param  array{invoice_id: string, invoice_payment_id: string}|null  $invoiceLink
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data, ?array $invoiceLink = null): Transaction
    {
        Gate::forUser($actor)->authorize('create', Transaction::class);

        return $this->write($data, $invoiceLink);
    }

    /**
     * Books the income of a payment with no Gate: for `RecordPayment` only (CONTEXT rule: income only through the
     * rules of RecordPayment). The caller has authorised the payment itself (the Admin through `RecordPayment::handle()`,
     * a queue job through the matching of a Stripe payment) and holds the lock of the document.
     *
     * It is unreachable without the payment link, which the type requires, so no system path can book an arbitrary
     * transaction through it; it must run inside the caller's transaction, otherwise the payment and its income could
     * be stored apart.
     *
     * @param  array<string, mixed>  $data
     * @param  array{invoice_id: string, invoice_payment_id: string}  $invoiceLink
     *
     * @throws ValidationException
     * @throws LogicException outside a transaction
     */
    public function writePaymentIncome(array $data, array $invoiceLink): Transaction
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The income of a payment is booked inside the transaction of the payment.');
        }

        return $this->write($data, $invoiceLink);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{invoice_id: string, invoice_payment_id: string}|null  $invoiceLink
     *
     * @throws ValidationException
     */
    private function write(array $data, ?array $invoiceLink): Transaction
    {
        $external = ExternalObject::parse($data['external'] ?? null);

        return DB::transaction(function () use ($data, $invoiceLink, $external): Transaction {
            $input = TransactionInput::fromArray($data);
            $czk = $this->czk->resolve($input->amount, $input->transactionDate, $input->manualCzk(), $input->customRate);

            $transaction = new Transaction([
                'description' => $input->description,
                'counterparty' => $input->counterparty,
                'document_number' => $input->documentNumber,
            ]);
            $transaction->forceFill([
                'type' => $input->type,
                'transaction_date' => $input->transactionDate,
                'category_id' => $input->categoryId,
                'amount' => $input->amount,
                'client_id' => $input->clientId,
                'project_id' => $input->projectId,
                'external' => $external,
                ...$czk,
            ]);

            if ($invoiceLink !== null) {
                $transaction->forceFill([
                    'invoice_id' => $invoiceLink['invoice_id'],
                    'invoice_payment_id' => $invoiceLink['invoice_payment_id'],
                ]);
            }

            $transaction->save();

            return $transaction->refresh();
        });
    }
}
