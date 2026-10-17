<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Documents\Actions\StoreGeneratedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Creates the PDF of an issued document again from its frozen snapshots when the stored one was deleted (IN-04,
 * D-21, D-23; rulings R-36-2). The Admin only.
 *
 * It works only for an issued document that has no PDF: `invoice_admin.pdf_document_id` is null because the file was
 * deleted. An existing PDF is never replaced, so two stored PDFs of one document can never disagree, and the PDF link
 * guard of the database refuses a repoint as well (plan 10-06 R-06-6). The document is rendered by
 * {@see InvoicePdf}, which reads the snapshot only (supplier, customer, lines, totals, language and the hash-verified
 * logo and signature objects), so the new file prints what the issued one printed, or the call fails.
 *
 * One transaction: the invoice row is locked for reading consistency and never written (the issued row accepts no
 * write, plan 10-04 R-04-6), the Admin-only row is locked, the PDF is rendered, stored through the hardened upload
 * path with the rollback cleanup of {@see StoreGeneratedDocument}, and linked on `invoice_admin`. Any failure rolls
 * everything back, removes a written object and surfaces as one fixed message, without a path or a snapshot value.
 */
final readonly class RegeneratePdf
{
    public function __construct(
        private InvoicePdf $pdf,
        private StoreGeneratedDocument $storage,
    ) {}

    /**
     * @throws AuthorizationException for a user who may not edit documents
     * @throws DomainException when the document is not issued or still has its PDF
     * @throws RuntimeException `documents.actions.delete_issued_pdf.regenerate_failed` when the PDF cannot be created;
     *                          the state is unchanged then
     */
    public function handle(User $actor, Invoice $issued): Document
    {
        Gate::forUser($actor)->authorize('update', $issued);

        try {
            return DB::transaction(function () use ($actor, $issued): Document {
                $locked = Invoice::query()->whereKey($issued->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->isIssued()) {
                    throw new DomainException('Only an issued document has a PDF to create again.');
                }

                $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->lockForUpdate()->first();

                if (! $admin instanceof InvoiceAdmin) {
                    throw new LogicException('An issued document without its Admin-only row cannot get a PDF.');
                }

                if ($admin->pdf_document_id !== null) {
                    throw new DomainException('The document still has its PDF; an existing PDF is never replaced.');
                }

                $document = $this->storage->handle($actor, $locked, $this->pdf->render($locked), $this->pdf->fileName($locked));

                $admin->forceFill(['pdf_document_id' => $document->getKey()])->save();

                return $document;
            });
        } catch (DomainException|LogicException $e) {
            // A refusal is not a failure of the render: it keeps its own reason.
            throw $e;
        } catch (Throwable $e) {
            // A missing or altered snapshot image, a full disk, a failing renderer: one fixed message, the cause stays
            // on the exception chain for the log and never reaches the screen.
            throw new RuntimeException(__('documents.actions.delete_issued_pdf.regenerate_failed'), previous: $e);
        }
    }
}
