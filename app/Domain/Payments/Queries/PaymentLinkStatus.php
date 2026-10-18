<?php

declare(strict_types=1);

namespace App\Domain\Payments\Queries;

use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Where the card payment of one document stands, for the Admin (PA-01, PA-02, PA-04; D-04, D-06 to D-09, ruling
 * R11-12-1). Nothing is stored: the state is the frozen switch of `invoice_admin`, the newest `payment_links` row
 * ({@see PaymentLink::latestFor()}: the live row is always the newest, because a new row is opened only when none is
 * live), the balance and the cancellation.
 *
 * "Vypnuto" (switched off) is not a link state: it is `switch = false`, with no state. A document whose switch is on
 * but that has no row at all has `state = null` too; the link section then shows the dash and the warning banner
 * offers to create a link.
 *
 * Both the reader and the SQL fragments read the Admin-only tables and ignore the Partner scope, so they are used only
 * by Admin screens: the reader refuses everyone but the Admin and a system run, and the list filter applies the
 * fragments only after {@see PartnerContext::isAdmin()} (T-11-44).
 *
 * @phpstan-type LinkStatusRow array{switch: bool, state: PaymentLinkState|null, reason: PaymentLinkDeactivation|null, failed: bool, offered: Money|null, created_at: CarbonImmutable|null, remaining: Money|null, payable: bool, regenerable: bool, url: string|null}
 */
final class PaymentLinkStatus
{
    /**
     * The state of the newest link of the document, as SQL over the table `invoices`; the same order as
     * {@see PaymentLink::latestFor()} (the live row first, then the newest).
     */
    public const string LATEST_STATE_SQL = "(SELECT latest_link.state FROM payment_links AS latest_link WHERE latest_link.invoice_id = invoices.id ORDER BY CASE WHEN latest_link.state IN ('pending', 'active') THEN 0 ELSE 1 END, latest_link.created_at DESC, latest_link.id DESC LIMIT 1)";

    /** The frozen card switch of the document, as SQL over the table `invoices`. */
    public const string SWITCH_SQL = '(SELECT admin_row.card_payment_enabled FROM invoice_admin AS admin_row WHERE admin_row.invoice_id = invoices.id)';

    /**
     * `switch` the frozen card switch; `state` the state of the newest link, null without one; `reason` why a
     * deactivated link ended; `failed` whether the newest link failed; `offered` and `created_at` the amount and
     * creation instant of the newest link; `remaining` what the document still owes (null for a credit note or a
     * draft); `payable` whether the client can pay it by card right now (an invoice or proforma, issued, not paid, not
     * cancelled, active link); `regenerable` whether a new link may be created (switch on, something owed, no link
     * being prepared); `url` the stable pay URL, null without a token.
     *
     * @return LinkStatusRow
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public static function for(Invoice $invoice): array
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The payment link status is available to the Admin only.');
        }

        $admin = InvoiceAdmin::query()->where('invoice_id', $invoice->getKey())->first();
        $switch = $admin instanceof InvoiceAdmin && $admin->card_payment_enabled;
        $link = PaymentLink::latestFor($invoice->getKey());

        $remainingMinor = $invoice->isIssued() && $invoice->kind->carriesPayment()
            ? (app(InvoiceBalances::class)->for([$invoice->getKey()])[$invoice->getKey()]['remaining'] ?? null)
            : null;
        $owes = $remainingMinor !== null && $remainingMinor > 0 && ! $invoice->isCancelled();
        $state = $link?->state;

        return [
            'switch' => $switch,
            'state' => $state,
            'reason' => $state === PaymentLinkState::Deactivated ? $link->deactivation_reason : null,
            'failed' => $state === PaymentLinkState::Failed,
            'offered' => $link?->amount(),
            'created_at' => $link?->created_at,
            'remaining' => $remainingMinor === null ? null : Money::ofMinor($remainingMinor, $invoice->currency),
            'payable' => $owes && $state === PaymentLinkState::Active,
            'regenerable' => $switch && $owes && $invoice->state === InvoiceState::Issued && $state !== PaymentLinkState::Pending,
            'url' => $switch ? PayUrl::forInvoice($invoice) : null,
        ];
    }
}
