<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Queries\PaymentLinkStatus;
use App\Domain\Payments\Support\PayUrl;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * The header action "Zaplatit kartou" of an issued document (PA-02; D-01, D-04, UI-SPEC Surface F, U-7, ruling
 * R11-12-2), shared by the Admin detail (gray) and the Partner detail (the accent, plan 11-15).
 *
 * It opens the page the client opens: always the stable pay URL of the document, never a Stripe address, so the
 * button leaks nothing and keeps working when the link is regenerated. When it shows is decided by the visibility
 * closure of the caller (the Admin passes {@see PaymentLinkStatus}, the Partner detail its
 * own Partner-safe reader); a document that does not qualify renders no action at all.
 */
final class PayByCardAction
{
    /**
     * @param  Closure(): Invoice  $invoice  the document the host page shows
     * @param  Closure(): bool  $visible  whether the document qualifies (an invoice or proforma, not paid, not cancelled, active link)
     * @param  string  $color  `gray` for the Admin, `primary` for the Partner
     * @param  (Closure(): ?string)|null  $url  the address to open; the stable pay URL of the document when null. The Partner
     *                                          passes its own reader, because the token lives in an Admin-only row
     */
    public static function make(Closure $invoice, Closure $visible, string $color = 'gray', ?Closure $url = null): Action
    {
        return Action::make('payByCard')
            ->label(__('payment_links.actions.pay_by_card'))
            ->icon(Heroicon::OutlinedCreditCard)
            ->color($color)
            ->tooltip(__('payment_links.actions.pay_by_card_tooltip'))
            ->url($url ?? static fn (): ?string => PayUrl::forInvoice($invoice()))
            ->openUrlInNewTab()
            ->visible($visible);
    }
}
