<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\TransactionCzk;
use App\Domain\Finance\TransactionInput;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records an income or expense (FI-01, D-12, D-13): the one checked entry point of a new transaction.
 *
 * The Admin only. The data is validated by {@see TransactionInput} and the CZK figure is fixed once by
 * {@see TransactionCzk}: identity for CZK, the typed figure as manual, otherwise the CNB list of the
 * transaction date with its snapshot. A foreign amount with neither a figure nor a list is refused with a
 * field error on `czk_amount`; saving never waits for a rate that is not there.
 *
 * Data keys: `type`, `transaction_date`, `category_id`, `amount`, `currency`, `czk_amount`, `czk_manual`,
 * `description`, `counterparty`, `document_number`, `client_id`, `project_id`. A typed `czk_amount` makes the
 * figure manual unless `czk_manual` is false.
 */
final readonly class RecordTransaction
{
    public function __construct(private TransactionCzk $czk) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): Transaction
    {
        Gate::forUser($actor)->authorize('create', Transaction::class);

        return DB::transaction(function () use ($data): Transaction {
            $input = TransactionInput::fromArray($data);
            $czk = $this->czk->resolve($input->amount, $input->transactionDate, $input->manualCzk());

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
                ...$czk,
            ]);
            $transaction->save();

            return $transaction->refresh();
        });
    }
}
