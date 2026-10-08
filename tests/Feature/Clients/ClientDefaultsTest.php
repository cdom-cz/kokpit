<?php

declare(strict_types=1);

use App\Domain\Clients\Enums\ClientStage;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The typed defaults of a new client (CL-01, D-13): the form opens pre-filled
 * from the settings and the values are copied into the client row once. Every
 * value is fictional.
 */

/**
 * Sets every typed default to a value that differs from the migrated initial one.
 */
function setClientDefaults(string $currency, string $rateMajor, int $terms, InvoiceLanguage $language, bool $online, string $country): void
{
    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = $currency;
    $defaults->default_hourly_rate = Money::fromMajor($rateMajor, $currency);
    $defaults->default_invoice_language = $language;
    $defaults->save();

    $invoicing = app(InvoicingSettings::class);
    $invoicing->payment_due_days = $terms;
    $invoicing->save();

    $payments = app(PaymentSettings::class);
    $payments->online_payments_enabled = $online;
    $payments->save();

    $supplier = app(SupplierSettings::class);
    $supplier->company_name = 'Example s.r.o.';
    $supplier->country = $country;
    $supplier->save();

    app()->forgetScopedInstances();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('opens a new client form pre-filled from every typed default', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    Livewire::test(CreateClient::class)
        ->assertFormSet([
            'currency' => 'EUR',
            'hourly_rate' => '85,50',
            'payment_terms_days' => 30,
            'invoice_language' => InvoiceLanguage::English,
            'online_payment_enabled' => true,
            'country' => 'SK',
            'stage' => ClientStage::Active,
        ]);
});

it('opens the form from the initial defaults on a fresh instance', function (): void {
    Livewire::test(CreateClient::class)
        ->assertFormSet([
            'currency' => 'CZK',
            'payment_terms_days' => 14,
            'invoice_language' => InvoiceLanguage::Czech,
            'online_payment_enabled' => false,
            'country' => 'CZ',
            'stage' => ClientStage::Active,
        ]);
});

it('creates a client from the pre-filled form with only a name typed', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Example defaults client'])
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Example defaults client')->firstOrFail();

    expect($client->currency)->toBe('EUR')
        ->and($client->hourly_rate?->minor)->toBe(8550)
        ->and($client->hourly_rate?->currency)->toBe('EUR')
        ->and($client->payment_terms_days)->toBe(30)
        ->and($client->invoice_language)->toBe(InvoiceLanguage::English)
        ->and($client->online_payment_enabled)->toBeTrue()
        ->and($client->country)->toBe('SK');
});
