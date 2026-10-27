<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Actions\StoreGeneratedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Pdf\CashReceiptPdf;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Renders the PDF of a cash receipt and stores it as the one document of the receipt (Phase 15; D-11, ruling
 * R-15-09-4): the first time at issue, again whenever the receipt changes (edit, regenerate). The receipt keeps exactly
 * one PDF and it shows what the receipt row says now; no older version is kept.
 *
 * It runs inside the caller's transaction (a call outside one is a {@see LogicException}), like
 * {@see ReplaceIssuedPdf}. The order is deliberate:
 *
 * 1. the new bytes are rendered first from the receipt row, so a failing render changes nothing;
 * 2. the receipt row is locked and the linked document, if any, is deleted through {@see DeleteDocument} with the
 *    confirmation flag set (the Admin already chose the change). The key `ON DELETE SET NULL` clears the link itself,
 *    and the old object leaves the bucket only after the outermost transaction commits, so a rollback keeps row and
 *    object;
 * 3. the new bytes are stored through {@see StoreGeneratedDocument}, which removes its object again on a rollback, and
 *    linked: a link from null to a file owned by this receipt is what the database link guard allows.
 *
 * Any failure that is not a refusal becomes one fixed message with the cause chained, so no path or storage detail
 * reaches a screen. Protecting the stored file against every other delete path is the work of the cancel plan.
 */
final readonly class StoreCashReceiptPdf
{
    public function __construct(
        private CashReceiptPdf $pdf,
        private StoreGeneratedDocument $storage,
        private DeleteDocument $delete,
    ) {}

    /**
     * @param  CashReceipt  $locked  the receipt as saved by the caller, whose transaction holds the lock on it
     *
     * @throws LogicException outside a transaction
     * @throws DomainException when the delete of the old file is refused
     * @throws RuntimeException `documents.actions.delete_issued_pdf.regenerate_failed` when the new PDF cannot be created or stored
     */
    public function handle(User $actor, CashReceipt $locked): Document
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The PDF of a cash receipt is stored inside the caller\'s transaction.');
        }

        try {
            $bytes = $this->pdf->render($locked);
            $fileName = $this->pdf->fileName($locked);

            $row = CashReceipt::query()->whereKey($locked->id)->lockForUpdate()->firstOrFail();
            $old = $row->pdf_document_id === null ? null : Document::query()->whereKey($row->pdf_document_id)->first();

            if ($old instanceof Document) {
                $this->delete->handle($actor, $old, confirmedIssuedPdf: true);
                $row->refresh();
            }

            $document = $this->storage->handle($actor, $row, $bytes, $fileName);

            $row->forceFill(['pdf_document_id' => $document->getKey()])->save();

            // The caller's instance shows the new link without a second write.
            $locked->forceFill(['pdf_document_id' => $document->getKey()])->syncOriginalAttribute('pdf_document_id');

            return $document;
        } catch (DomainException|LogicException $e) {
            // A refusal is not a failure of the render: it keeps its own reason.
            throw $e;
        } catch (Throwable $e) {
            // A missing snapshot image, a full disk, a failing renderer: one fixed message, the cause stays on the
            // exception chain for the log and never reaches the screen.
            throw new RuntimeException(__('documents.actions.delete_issued_pdf.regenerate_failed'), previous: $e);
        }
    }
}
