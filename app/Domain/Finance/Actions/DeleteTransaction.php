<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Deletes a transaction (FI-01, D-09, D-14, D-21): the Admin only, and for real, together with its receipts.
 *
 * Transactions are the only document owner that is hard-deleted; there is no archive state for them. The row is
 * read again under a row lock, so a stale page cannot delete a row another request is changing, and an upload
 * that waits for the shared lock of its owner finds the row gone instead of attaching to a deleted transaction.
 * Every receipt is then deleted through {@see DeleteDocument} (row, media row and stored object) before the
 * transaction row, all inside one database transaction.
 *
 * When an object cannot be removed the Action throws and the database transaction rolls back, so the
 * transaction and the receipts that were not reached stay. An object that was already removed when a later one
 * failed is the accepted rare case of the one-way deletion (D-09); the consistency check of plan 09-20 finds the
 * row that is left without its object.
 */
final readonly class DeleteTransaction
{
    public function __construct(private DeleteDocument $deleteDocument) {}

    /**
     * @throws RuntimeException when a stored object of a receipt cannot be removed; nothing is deleted then
     */
    public function handle(User $actor, Transaction $transaction): void
    {
        Gate::forUser($actor)->authorize('delete', $transaction);

        DB::transaction(function () use ($actor, $transaction): void {
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            foreach ($locked->documents()->orderBy('id')->get() as $receipt) {
                $this->deleteDocument->handle($actor, $receipt);
            }

            $locked->delete();
        });
    }
}
