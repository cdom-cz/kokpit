<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\CustomRate;
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
 * - switching a manual transaction back to computed always recomputes;
 * - an own rate typed in the data (`exchange_rate`) makes any transaction custom, with the transaction date as the
 *   rate date; a typed `czk_amount` makes it manual (owner decision A1 on D-06);
 * - a custom transaction (booked at an own rate) keeps that rate while the data says nothing about `czk_manual`: a
 *   changed amount in the same currency is recomputed at the stored rate and unit amount with the stored rate date,
 *   a changed currency falls back to the CNB rule of the transaction date, and a changed date or any other edit
 *   leaves the figure and the snapshot as stored.
 * A CZK transaction needs no rate: it is identity whatever was typed or kept.
 *
 * A receipt counts for the client and project of its transaction (D-11), so when either changes the receipts
 * get the new pair in the same database transaction (research Pitfall 5). That is one query update, not a model
 * event: the logged attributes of a document do not include the client or the project.
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

            if ($locked->wasChanged(['client_id', 'project_id'])) {
                $locked->documents()->update([
                    'client_id' => $locked->client_id,
                    'project_id' => $locked->project_id,
                ]);
            }

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
        $wasCustom = $stored->czk_source === CzkSource::Custom;

        $manual = $input->manualCzk();

        if (! $manual instanceof Money && $input->customRate instanceof CustomRate) {
            // A new own rate makes the transaction custom, whatever it was; its rate date is the transaction date.
            return $this->czk->resolve($input->amount, $input->transactionDate, null, $input->customRate);
        }

        if (! $manual instanceof Money && ($input->czkManual ?? $wasManual)) {
            // Manual stays manual: keep the stored figure (freezing it when manual is switched on without one).
            $manual = $stored->czk();
        }

        if ($manual instanceof Money) {
            return $this->czk->resolve($input->amount, $input->transactionDate, $manual);
        }

        if ($wasCustom && $input->czkManual === null) {
            return $this->stickyFigure($stored, $input);
        }

        $changed = $input->transactionDate !== $stored->transaction_date->toDateString()
            || ! $input->amount->equals($stored->amount);

        if ($wasManual || $changed) {
            return $this->czk->resolve($input->amount, $input->transactionDate, null);
        }

        return [];
    }

    /**
     * The figure of a transaction booked at an own rate when the data does not mention `czk_manual` (owner decision
     * A1 on D-06, the sticky rate): the rate is the one fixed for this transaction.
     * - a changed currency drops it for the CNB rule of the transaction date (identity for CZK);
     * - a changed amount in the same currency recomputes the figure at the stored rate and unit amount, and keeps
     *   the stored rate date, because the transaction date does not move a rate fixed for it;
     * - anything else, a changed date included, leaves the figure and the snapshot as stored.
     *
     * @return array{czk_minor?: int, czk_source?: CzkSource, exchange_rate?: string|null, exchange_unit_amount?: int|null, exchange_rate_date?: string|null}
     */
    private function stickyFigure(Transaction $stored, TransactionInput $input): array
    {
        if ($input->amount->currency !== $stored->amount_currency) {
            return $this->czk->resolve($input->amount, $input->transactionDate, null);
        }

        if ($input->amount->equals($stored->amount)) {
            return [];
        }

        $rate = new CustomRate((string) $stored->exchange_rate, (int) $stored->exchange_unit_amount, $stored->exchange_rate_date?->toDateString());

        return $this->czk->resolve($input->amount, $input->transactionDate, null, $rate);
    }
}
