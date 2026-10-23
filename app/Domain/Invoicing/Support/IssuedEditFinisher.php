<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\ReplaceIssuedPdf;
use App\Domain\Invoicing\Issuing\DocumentIssuer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PaymentLinks;
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
 * 2. the card payment link follows what the document owes now (D-11): the live link that no longer offers it is
 *    deactivated (`amount_changed`, `paid`, `cancelled`) by {@see PaymentLinks::reconcile()}, and, when the document
 *    is still offered card payment, a new pending link for the remaining amount is opened. The row is written here,
 *    Stripe is never called inside the transaction: {@see CreatePaymentLink} is dispatched after the commit and is the
 *    only caller of Stripe, so a Stripe outage cannot fail or block an edit (T-10.1-21). A card payment already
 *    recorded keeps its record, and a link the Admin switched off by hand stays off until the Admin regenerates it,
 *    unless the edit itself changed how the document is paid (`$forceNewLink`: a new payment method, the card switch
 *    turned on, a new currency; Phase 14, D-10, OA-3);
 * 3. the stored PDF is replaced ({@see ReplaceIssuedPdf}), so it prints the corrected document;
 * 4. the audit row of a post-send correction is written ({@see InvoiceAudit::edited()}), from the values before the
 *    edit that the caller took; only this step reads `$before`, and it writes nothing for a document never sent.
 *
 * Nothing is sent: no e-mail row, no queued job (D-10). The Admin sends by hand, and that send locks the document.
 */
final readonly class IssuedEditFinisher
{
    public function __construct(
        private ReplaceIssuedPdf $pdf,
        private PaymentLinks $links,
        private InvoiceBalances $balances,
    ) {}

    /**
     * @param  array<string, string|int|null>  $before  {@see InvoiceAudit::snapshot()} taken before the write
     * @param  bool  $forceNewLink  the edit changed how the document is paid, so a link switched off by hand earlier no
     *                              longer keeps the new one from opening (the default keeps every older caller as it was)
     *
     * @throws LogicException outside a transaction, or for a foreign-currency document without its stored rate
     */
    public function finish(User $actor, Invoice $locked, array $before, bool $forceNewLink = false): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An issued edit is finished inside the caller\'s transaction.');
        }

        $this->czkTotal($locked);

        $this->paymentLink($actor, $locked, $forceNewLink);

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

    /**
     * Brings the card link in line with what the document owes (R-10.1-05-1).
     *
     * {@see PaymentLinks::reconcile()} first: a live link that offers anything else than the remaining amount is
     * deactivated, one that still offers it is left alone. A new pending link is opened only when no live link remains
     * and the document may be offered card payment: an issued, uncancelled invoice or proforma with its card
     * switch on that still owes something, whose newest link was not switched off by the Admin (`manual`). With
     * `$forceNewLink` that last rule is skipped, because the Admin has just changed how the document is paid and a new
     * link is the point of the change (OA-3); every other condition stays.
     */
    private function paymentLink(User $actor, Invoice $locked, bool $forceNewLink): void
    {
        $this->links->reconcile($locked);

        if (! $locked->isIssued() || ! $locked->kind->carriesPayment() || $locked->isCancelled()) {
            return;
        }

        $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->first();

        if (! $admin instanceof InvoiceAdmin || ! $admin->card_payment_enabled) {
            return;
        }

        $remaining = $this->balances->for([$locked->id])[$locked->id]['remaining'] ?? 0;

        if ($remaining <= 0 || PaymentLink::query()->live()->where('invoice_id', $locked->id)->exists()) {
            return;
        }

        if (! $forceNewLink && PaymentLink::latestFor($locked->id)?->deactivation_reason === PaymentLinkDeactivation::Manual) {
            return;
        }

        $link = PaymentLink::open($locked, $remaining, $actor);

        CreatePaymentLink::dispatch($link->id)->afterCommit();
    }
}
