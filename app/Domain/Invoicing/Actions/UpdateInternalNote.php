<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Edits the internal note of a document, a draft or an issued one (D-13, research Open Question 12, ruling R-11-5):
 * the one writable field of D-13 that lives with the document. The Admin only.
 *
 * Only `invoice_admin.internal_note` and its marker `internal_note_updated_at` change, under the row lock of the
 * invoice. The `invoices` row itself is never written, because an issued row accepts no write at all; the database
 * lets exactly these two columns (and the PDF link) of the Admin-only row move after issue. The activity history
 * records the marker and never the text: free text is not logged in this codebase (T-10-30). A blank note clears the
 * note, and a note equal to the stored one writes nothing, so the marker keeps the time of the last real change.
 */
final readonly class UpdateInternalNote
{
    private const int MAX_LENGTH = 2000;

    /**
     * @throws ValidationException `internal_note` for more than 2 000 characters
     */
    public function handle(User $actor, Invoice $invoice, ?string $note): void
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        $text = $note === null ? '' : trim($note);

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['internal_note' => __('invoices.errors.internal_note_length')]);
        }

        $text = $text === '' ? null : $text;

        DB::transaction(function () use ($invoice, $text): void {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->lockForUpdate()->first();

            if (! $admin instanceof InvoiceAdmin) {
                // Every draft is created with its Admin-only row; an issued document accepts no new child row.
                if (! $locked->isDraft()) {
                    throw new LogicException('An issued document without its Admin-only row cannot get one.');
                }

                $admin = new InvoiceAdmin;
                $admin->forceFill(['invoice_id' => $locked->id]);
            }

            if ($admin->internal_note === $text) {
                return;
            }

            $admin->fill(['internal_note' => $text]);
            $admin->forceFill(['internal_note_updated_at' => now()]);
            $admin->save();
        });
    }
}
