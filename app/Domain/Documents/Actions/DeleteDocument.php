<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Jobs\RemoveDocumentObjects;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Throwable;

/**
 * Permanently deletes a file: the documents row, its media row and the stored object (DO-03, D-09, D-23).
 *
 * The deletion is one-way by the owner's decision (no trash, nothing to restore), so the caller asks for a
 * confirmation before it gets here. The actor is checked against the `delete` ability first; the work then
 * runs as system, because `Media` is closed to Partners and the media rows would otherwise be invisible in a
 * Partner's context (research Pitfall 3).
 *
 * The database comes first and the irreversible side effect last (09-REVIEW.md WR-01, which supersedes the
 * order of plan 09-07). Inside one transaction the row is re-read with `lockForUpdate()`, its media rows are
 * deleted with a query-builder delete (no model event, so the medialibrary observer cannot remove the file
 * now) and the document is deleted with `deletePreservingMedia()`; the allowlisted activity row of the deleted
 * event is written in the same transaction. The stored objects are removed by a `DB::afterCommit()` callback,
 * which Laravel runs only after the outermost enclosing transaction commits and drops when any enclosing
 * transaction rolls back. A rollback anywhere (a later receipt, the transaction row, the commit itself)
 * therefore never costs an object: row, media row and object all stay.
 *
 * That timing deviates from D-09 ("together with the row") by the owner decision D-23: the object is removed
 * after the commit; an object the disk then refuses is reported and handed to {@see RemoveDocumentObjects}
 * (3 tries with backoff), and when every try fails the failed-job alert reaches the Admin. A refused object can
 * no longer undo or block a delete the actor confirmed.
 *
 * The PDF of an issued document (the file named by `invoice_admin.pdf_document_id`) needs the explicit confirmation
 * flag (DO-03, D-21, ruling R-36-1): without it the delete is refused before anything changes. The foreign key of
 * that column is `ON DELETE SET NULL`, so the row delete clears the Admin-only link itself and the issued `invoices`
 * row takes no write (D-13, plan 10-04 R-04-6). Any other file an Admin uploaded to an invoice deletes as usual.
 *
 * The stored PDF of a cash receipt (a file owned by a `cash_receipt`) is evidence and is refused the same way, in any
 * lock state of the receipt, unless the flag is set (Phase 15, D-08, D-11): only the sanctioned replace of the receipt,
 * `StoreCashReceiptPdf`, passes it.
 *
 * The after-commit callback may run outside the system context, so it queries no model: it works on the media
 * instances read inside the transaction and on the disk only, and it never throws.
 */
final class DeleteDocument
{
    public function __construct(private readonly PartnerContext $context) {}

    /**
     * @param  bool  $confirmedIssuedPdf  the Admin explicitly confirmed deleting the PDF of an issued document
     *
     * @throws DomainException when the file is the PDF of an issued document and the confirmation is missing
     * @throws RuntimeException when the delete is refused or the database fails; nothing is deleted then
     */
    public function handle(User $actor, Document $document, bool $confirmedIssuedPdf = false): void
    {
        Gate::forUser($actor)->authorize('delete', $document);

        $this->context->runAsSystem(function () use ($document, $confirmedIssuedPdf): void {
            DB::transaction(function () use ($document, $confirmedIssuedPdf): void {
                $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                if (! $confirmedIssuedPdf && $locked->owner_type === DocumentOwnerType::CashReceipt->value) {
                    throw new DomainException(__('cash_receipts.errors.pdf_protected'));
                }

                if (! $confirmedIssuedPdf && $this->isLockedIssuedPdf($locked)) {
                    throw new DomainException(__('invoices.errors.pdf_unconfirmed'));
                }

                $media = Media::query()
                    ->where('model_type', $locked->getMorphClass())
                    ->where('model_id', $locked->getKey())
                    ->get();

                $objects = [];

                foreach ($media as $item) {
                    $objects[] = ['disk' => $item->disk, 'path' => $item->getPathRelativeToRoot()];
                }

                if ($media->isNotEmpty()) {
                    // A query-builder delete fires no model event: the package observer must not remove the file yet.
                    Media::query()->whereKey($media->modelKeys())->delete();
                }

                $locked->deletePreservingMedia();

                DB::afterCommit(fn () => $this->removeObjects(array_values($media->all()), $objects));
            });
        });
    }

    /**
     * Whether the file is the stored PDF of an issued document: a file owned by an invoice that an `invoice_admin`
     * row names (ruling R-36-1). Read without a lock, for the screens that decide which confirmation to ask for; the
     * delete itself decides again under the lock.
     */
    public static function isIssuedPdf(Document $document): bool
    {
        if ($document->owner_type !== DocumentOwnerType::Invoice->value) {
            return false;
        }

        return app(PartnerContext::class)->runAsSystem(
            static fn (): bool => InvoiceAdmin::query()->where('pdf_document_id', $document->getKey())->exists(),
        );
    }

    /**
     * The same question under the row lock of the delete: the foreign key rewrites the `invoice_admin` row when the
     * document goes, so a concurrent regeneration or delete waits here. Runs inside the system context of the delete.
     */
    private function isLockedIssuedPdf(Document $document): bool
    {
        if ($document->owner_type !== DocumentOwnerType::Invoice->value) {
            return false;
        }

        return InvoiceAdmin::query()->where('pdf_document_id', $document->getKey())->lockForUpdate()->first() instanceof InvoiceAdmin;
    }

    /**
     * Removes the objects of a deleted document after the commit. Queries no model and never throws: the
     * delete is already committed, so a failure here is reported and left to the retry, not undone.
     *
     * @param  list<Media>  $media
     * @param  list<array{disk: string, path: string}>  $objects
     */
    private function removeObjects(array $media, array $objects): void
    {
        // The package remover (conversions and the empty directory included) swallows and reports a failing disk.
        foreach ($media as $item) {
            try {
                app(Filesystem::class)->removeAllFiles($item);
            } catch (Throwable) {
                // The strict check below finds what is left.
            }
        }

        $standing = [];
        $cause = null;

        foreach ($objects as $object) {
            try {
                if ($this->exists($object)) {
                    // The package remover went on after a failure; try once more where the error is not swallowed.
                    Storage::disk($object['disk'])->delete($object['path']);
                }

                if ($this->exists($object)) {
                    $standing[] = $object;
                }
            } catch (Throwable $e) {
                $cause = $e;
                $standing[] = $object;
            }
        }

        if ($standing === []) {
            return;
        }

        // No name and no path in the message: the exception can reach a log or a screen.
        report(new RuntimeException('A stored object of a deleted document could not be removed ('.($cause === null ? 'the disk kept it' : $cause::class).').'));

        // The retry job removes exactly the objects left, 3 tries, then the failed-job alert to the Admin (D-23).
        try {
            RemoveDocumentObjects::dispatch($standing);
        } catch (Throwable $e) {
            report(new RuntimeException('The removal of a stored object of a deleted document could not be queued ('.$e::class.').'));
        }
    }

    /**
     * Reads the disk every time: the answer changes when an object is deleted between two calls.
     *
     * @param  array{disk: string, path: string}  $object
     *
     * @phpstan-impure
     */
    private function exists(array $object): bool
    {
        return Storage::disk($object['disk'])->exists($object['path']);
    }
}
