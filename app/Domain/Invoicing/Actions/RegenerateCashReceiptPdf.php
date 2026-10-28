<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * Creates the PDF of a cash receipt again from its row when the stored one is missing (Phase 15; D-11, assumption AS-12,
 * ruling R-15-13-3), the twin of {@see RegeneratePdf}. The Admin only, in every lock state and for a cancelled receipt
 * as well: the new file shows what the row says now, including the cancelled band, and changes nothing but the PDF
 * link, which the database guard of the receipt allows from nothing to a file in every state.
 *
 * It works only for a receipt that has no usable PDF: the link is empty because the file was deleted, or the linked
 * document has lost its stored object. A PDF that is present is never replaced by this path (`pdf_present` under
 * `receipt`), so two stored PDFs of one receipt can never disagree; the replacement after an edit is the work of
 * {@see UpdateCashReceipt}.
 *
 * One transaction: the invoice row is locked, then the receipt row (the lock order of every writer of a receipt), the
 * refusal is judged on the locked row, and {@see StoreCashReceiptPdf} renders the receipt from its row and links the new
 * document. Any failure rolls everything back and surfaces as the one fixed message of that class, without a path or a
 * snapshot value.
 */
final readonly class RegenerateCashReceiptPdf
{
    public function __construct(private StoreCashReceiptPdf $pdf) {}

    /**
     * @throws AuthorizationException for a user who may not edit receipts
     * @throws ValidationException `receipt` when the receipt still has its PDF
     * @throws DomainException|LogicException when the storage of the new PDF is refused
     * @throws RuntimeException `documents.actions.delete_issued_pdf.regenerate_failed` when the PDF cannot be created
     */
    public function handle(User $actor, CashReceipt $receipt): Document
    {
        Gate::forUser($actor)->authorize('update', $receipt);

        return DB::transaction(function () use ($actor, $receipt): Document {
            Invoice::query()->whereKey($receipt->invoice_id)->lockForUpdate()->first();
            $locked = CashReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if ($this->hasStoredPdf($locked)) {
                throw ValidationException::withMessages(['receipt' => __('cash_receipts.errors.pdf_present')]);
            }

            return $this->pdf->handle($actor, $locked);
        });
    }

    /**
     * Whether the linked document row exists and its media object is on the disk.
     */
    private function hasStoredPdf(CashReceipt $locked): bool
    {
        if ($locked->pdf_document_id === null) {
            return false;
        }

        $document = Document::query()->whereKey($locked->pdf_document_id)->first();
        $media = $document?->getFirstMedia(Document::MEDIA_COLLECTION);

        return $media !== null && Storage::disk($media->disk)->exists($media->getPathRelativeToRoot());
    }
}
