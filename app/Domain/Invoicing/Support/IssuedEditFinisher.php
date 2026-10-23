<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\ReplaceIssuedPdf;
use App\Domain\Invoicing\Issuing\DocumentIssuer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What every correction of an open issued document does after the row and the lines are saved, in the same
 * transaction and under the same invoice row lock (R-10.1-04-4). One class so that the Actions that write an issued
 * document (the items and the note today, the dates, the language and the currency later) end the same way.
 *
 * The order is fixed:
 *
 * 1. a document not in CZK gets its CZK total again from the new total at the stored rate, unit and list date,
 *    exactly as {@see DocumentIssuer} computed it at issue (D-12, IN-07): the rate is
 *    never looked up again here;
 * 2. (plan 10.1-05) the card payment link follows the new total;
 * 3. the stored PDF is replaced ({@see ReplaceIssuedPdf}), so it prints the corrected document;
 * 4. the audit row of a post-send correction is written ({@see InvoiceAudit::edited()}), from the values before the
 *    edit that the caller took; only this step reads `$before`, and it writes nothing for a document never sent.
 *
 * Nothing is sent: no e-mail row, no queued job (D-10). The Admin sends by hand, and that send locks the document.
 */
final readonly class IssuedEditFinisher
{
    public function __construct(private ReplaceIssuedPdf $pdf) {}

    /**
     * @param  array<string, string|int|null>  $before  {@see InvoiceAudit::snapshot()} taken before the write
     *
     * @throws LogicException outside a transaction, or for a foreign-currency document without its stored rate
     */
    public function finish(User $actor, Invoice $locked, array $before): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An issued edit is finished inside the caller\'s transaction.');
        }

        $this->czkTotal($locked);

        $this->pdf->handle($actor, $locked);

        InvoiceAudit::edited($locked, $actor, $before);
    }

    /**
     * Recomputes `invoice_admin.czk_total_minor` for a document not in CZK; a CZK document has none.
     */
    private function czkTotal(Invoice $locked): void
    {
        if ($locked->currency === 'CZK') {
            return;
        }

        $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->lockForUpdate()->first();

        if (! $admin instanceof InvoiceAdmin || $admin->exchange_rate === null || $admin->exchange_unit_amount === null) {
            throw new LogicException('A document not in CZK has its stored exchange rate.');
        }

        $czk = $locked->total()->convert($admin->exchange_rate, 'CZK', $admin->exchange_unit_amount)->minor;

        if ($admin->czk_total_minor !== $czk) {
            $admin->forceFill(['czk_total_minor' => $czk])->save();
        }
    }
}
