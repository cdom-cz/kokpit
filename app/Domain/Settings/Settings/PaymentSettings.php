<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

/**
 * Online payments (group `payments`): whether invoices offer a card payment link.
 *
 * The toggle is the global operand of the card switch (Phase 11, D-06): while it is on and Stripe is configured, a
 * document issued with its own switch on gets a stable pay URL and, from plan 11-07, a Stripe payment link created at
 * issue. It affects documents issued later only; the switch of an issued document is frozen. The Stripe keys are never
 * part of the settings (D-16).
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
