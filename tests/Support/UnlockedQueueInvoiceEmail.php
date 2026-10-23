<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Test-only mutation for the invoice edit versus e-mail queue concurrency harness; never used by application code.
 *
 * Identical to QueueInvoiceEmail except that the document is read without FOR UPDATE, so the queue does not wait for
 * an edit that holds the invoice row. A short sleep between that read and the write of the queued row widens the race
 * window; the production class gets no such hook. If the harness does not see a defect with this twin, a clean run
 * against the real Action proves nothing.
 */
final readonly class UnlockedQueueInvoiceEmail extends QueueInvoiceEmail
{
    #[\Override]
    protected function document(string $id): Invoice
    {
        // The only difference to the real read: no FOR UPDATE.
        $document = Invoice::query()->whereKey($id)->firstOrFail();

        DB::select('select pg_sleep(0.05)');

        return $document;
    }
}
