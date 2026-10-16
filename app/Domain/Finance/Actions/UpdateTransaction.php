<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\TransactionCzk;
use App\Domain\Finance\TransactionInput;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Edits a transaction (FI-01, D-12, D-13, D-14): freely, by the Admin only.
 *
 * The row is read again under a row lock and the data is validated against it with
 * {@see TransactionInput::fromArray()}: a key that is absent from the data keeps the stored value, and an
 * archived category, client or project that the transaction already carries stays acceptable.
 *
 * The CZK figure follows one rule (research Pitfall 13):
 * - a typed `czk_amount` is the new figure and the source becomes manual, unless `czk_manual` is false;
 * - a manual transaction stays manual, whatever changes in the date, currency or amount, until the data says
 *   `czk_manual` false: the figure is kept and the snapshot stays empty. Switching manual on without a figure
 *   freezes the stored one;
 * - a computed transaction (identity or CNB) is recomputed through {@see TransactionCzk} only when the date,
 *   the currency or the amount changed, which also refreshes the snapshot; any other edit leaves the figure and
 *   the snapshot exactly as stored, so a list added or corrected later never moves them;
 * - switching a manual transaction back to computed always recomputes.
 * A CZK transaction needs no rate: it is identity whatever was typed or kept.
 */
final readonly class UpdateTransaction
{
    public function __construct(private TransactionCzk $czk) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Transaction $transaction, array $data): Transaction
    {
        Gate::forUser($actor)->authorize('update', $transaction);

        return DB::transaction(function () use ($transaction, $data): Transaction {
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
            $input = TransactionInput::fromArray($data, $locked);

            $locked->fill([
                'description' => $input->description,
                'counterparty' => $input->counterparty,
                'document_number' => $input->documentNumber,
            ]);
            $locked->forceFill([
                'type' => $input->type,
                'transaction_date' => $input->transactionDate,
                'category_id' => $input->categoryId,
                'amount' => $input->amount,
                'client_id' => $input->clientId,
                'project_id' => $input->projectId,
                ...$this->figure($locked, $input),
            ]);
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * The CZK columns to write; an empty array leaves the stored figure and snapshot untouched.
     *
     * @return array{czk_minor?: int, czk_source?: CzkSource, exchange_rate?: string|null, exchange_unit_amount?: int|null, exchange_rate_date?: string|null}
     */
    private function figure(Transaction $stored, TransactionInput $input): array
    {
        $wasManual = $stored->czk_source === CzkSource::Manual;

        $manual = $input->manualCzk();

        if (! $manual instanceof Money && ($input->czkManual ?? $wasManual)) {
            // Manual stays manual: keep the stored figure (freezing it when manual is switched on without one).
            $manual = $stored->czk();
        }

        if ($manual instanceof Money) {
            return $this->czk->resolve($input->amount, $input->transactionDate, $manual);
        }

        $changed = $input->transactionDate !== $stored->transaction_date->toDateString()
            || ! $input->amount->equals($stored->amount);

        if ($wasManual || $changed) {
            return $this->czk->resolve($input->amount, $input->transactionDate, null);
        }

        return [];
    }
}
