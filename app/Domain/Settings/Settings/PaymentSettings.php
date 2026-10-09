<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

/**
 * Online payments (group `payments`): whether invoices offer a card payment link.
 *
 * The toggle only records the intent; the payment links themselves arrive with
 * the Stripe phase.
 */
class PaymentSettings extends ValidatedSettings
{
    public bool $online_payments_enabled;

    public static function group(): string
    {
        return 'payments';
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'online_payments_enabled' => ['boolean'],
        ];
    }
}
