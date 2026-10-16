<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes a transaction (FI-01, D-14, D-21): the Admin only, and for real.
 *
 * Transactions are the only document owner that is hard-deleted; there is no archive state for them. The row is
 * read again under a row lock, so a stale page cannot delete a row another request is changing. The receipts of
 * the transaction are removed with it when plan 09-13 adds them to this deletion.
 */
final class DeleteTransaction
{
    public function handle(User $actor, Transaction $transaction): void
    {
        Gate::forUser($actor)->authorize('delete', $transaction);

        DB::transaction(function () use ($transaction): void {
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            $locked->delete();
        });
    }
}
