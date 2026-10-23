<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Actions\StoreGeneratedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Replaces the stored PDF of an open issued document after a correction, so the invoice keeps exactly one PDF and it
 * shows what the document says now (IN-14; D-09, R-10.1-04-5). No confirmation is asked and no older version is kept.
 *
 * It runs inside the caller's transaction, which holds the invoice row lock and has already saved the corrected row
 * and lines (a call outside a transaction is a {@see LogicException}). The order is deliberate:
 *
 * 1. the new bytes are rendered first from the corrected document, so a failing render changes nothing;
 * 2. the Admin-only row is locked and the linked document, if any, is deleted through {@see DeleteDocument} with the
 *    confirmation flag set (the Phase 9 prompt is for a person; the Admin already chose to save). The key
 *    `ON DELETE SET NULL` clears the link itself, and the old object leaves the bucket only after the outermost
 *    transaction commits, so a rollback keeps row and object;
 * 3. the new bytes are stored through {@see StoreGeneratedDocument}, which removes its object again on a rollback, and
 *    linked: a link from null to a file of the same invoice is what the database link guard allows.
 *
 * {@see RegeneratePdf} stays the path for a PDF that is missing; it never replaces one.
 */
final readonly class ReplaceIssuedPdf
{
    public function __construct(
        private InvoicePdf $pdf,
        private StoreGeneratedDocument $storage,
        private DeleteDocument $delete,
    ) {}

    /**
     * @throws LogicException outside a transaction, or for an issued document without its Admin-only row
     * @throws DomainException when the document is not issued
     * @throws RuntimeException `documents.actions.delete_issued_pdf.regenerate_failed` when the new PDF cannot be created or stored
     */
    public function handle(User $actor, Invoice $locked): Document
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The PDF of an issued document is replaced inside the caller\'s transaction.');
        }

        if (! $locked->isIssued()) {
            throw new DomainException('Only an issued document has a PDF to replace.');
        }

        try {
            // The lines may have been read before the correction; the PDF prints the stored ones.
            $locked->unsetRelation('items');

            $bytes = $this->pdf->render($locked);
            $fileName = $this->pdf->fileName($locked);

            $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->lockForUpdate()->first();

            if (! $admin instanceof InvoiceAdmin) {
                throw new LogicException('An issued document without its Admin-only row cannot get a PDF.');
            }

            $old = $admin->pdf_document_id === null ? null : Document::query()->whereKey($admin->pdf_document_id)->first();

            if ($old instanceof Document) {
                $this->delete->handle($actor, $old, confirmedIssuedPdf: true);
                $admin->refresh();
            }

            $document = $this->storage->handle($actor, $locked, $bytes, $fileName);

            $admin->forceFill(['pdf_document_id' => $document->getKey()])->save();

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
