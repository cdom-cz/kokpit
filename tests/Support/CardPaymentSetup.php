<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Shared\Auth\PartnerContext;

/**
 * The preconditions of a card payment for a test (Phase 11): both Stripe secrets set, the global switch on, a client
 * whose online-payment flag is on and an issued document of it. Every value is fictional or assembled at runtime.
 */
final class CardPaymentSetup
{
    /**
     * Configures Stripe (both secrets) and switches online payments on in the settings.
     */
    public static function enable(): void
    {
        StripeTestConfig::configure();
        self::onlinePayments(true);
    }

    /**
     * Sets the global setting `online_payments_enabled` and drops the scoped settings instances.
     */
    public static function onlinePayments(bool $on): void
    {
        app(PartnerContext::class)->runAsSystem(static function () use ($on): void {
            $settings = app(PaymentSettings::class);
            $settings->online_payments_enabled = $on;
            $settings->save();
        });

        app()->forgetScopedInstances();
    }

    /**
     * A fictional client whose online-payment flag is on.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function client(array $overrides = []): Client
    {
        return InvoiceFixtures::client(['online_payment_enabled' => true, ...$overrides]);
    }

    /**
     * An issued document of the client through the real issue pipeline, one line of the given amount.
     */
    public static function issued(Client $client, InvoiceKind $kind = InvoiceKind::Invoice, string $amount = '12500.00'): Invoice
    {
        return InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', $amount)], $kind);
    }
}
