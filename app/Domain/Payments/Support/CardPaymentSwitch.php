<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Settings\Settings\PaymentSettings;

/**
 * The one predicate of D-06 (rulings R11-03-1, UI-SPEC U-4), used by the issue and by the draft editor.
 *
 * A document is offered payment by card when its kind carries a payment and is not a credit note (D-05), its own
 * switch is on, the global setting `online_payments_enabled` is on, and both Stripe secrets are set. The client flag
 * is not a fourth operand: it is the default of the draft switch (the draft takes the client's value, the Admin can
 * override it), so the draft switch already holds it.
 */
final readonly class CardPaymentSwitch
{
    public const string SETTINGS_OFF = 'settings_off';

    public const string STRIPE_MISSING = 'stripe_missing';

    public function __construct(
        private PaymentSettings $settings,
        private StripeConfig $stripe,
    ) {}

    /**
     * The value frozen at issue.
     */
    public function effective(InvoiceKind $kind, bool $draftSwitch): bool
    {
        return $kind->carriesPayment() && $draftSwitch && $this->available();
    }

    /**
     * Whether the installation can offer a card payment at all: the global setting is on and Stripe is configured.
     */
    public function available(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Why the installation cannot offer a card payment: `settings_off`, `stripe_missing`, or null when it can.
     */
    public function unavailableReason(): ?string
    {
        if (! $this->settings->online_payments_enabled) {
            return self::SETTINGS_OFF;
        }

        return $this->stripe->isConfigured() ? null : self::STRIPE_MISSING;
    }
}
