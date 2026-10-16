<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Jobs\RemoveDocumentObjects;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Deletes a transaction (FI-01, D-09, D-14, D-21, D-23): the Admin only, and for real, together with its receipts.
 *
 * Transactions are the only document owner that is hard-deleted; there is no archive state for them. The row is
 * read again under a row lock, so a stale page cannot delete a row another request is changing, and an upload
 * that waits for the shared lock of its owner finds the row gone instead of attaching to a deleted transaction.
 *
 * The transaction row and every receipt row (with its media row) go in one database transaction. Each receipt is
 * deleted through {@see DeleteDocument}, which registers the removal of its stored object with `DB::afterCommit()`:
 * Laravel attaches that callback of the nested transaction to this outer one, runs it only after the outermost
 * commit and drops it when this transaction rolls back (WR-01). A later failure (a second receipt, the transaction
 * row, the commit) therefore leaves every receipt with its row, media row and object. An object the disk refuses
 * after the commit is reported and queued ({@see RemoveDocumentObjects}, 3 tries, then the failed-job alert to the
 * Admin) and never undoes the delete.
 */
final readonly class DeleteTransaction
{
    public function __construct(private DeleteDocument $deleteDocument) {}

    /**
     * @throws RuntimeException when the database refuses the delete; nothing at all is deleted then
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
