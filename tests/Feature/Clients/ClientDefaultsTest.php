<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateClient as CreateClientAction;
use App\Domain\Clients\Actions\UpdateClient as UpdateClientAction;
use App\Domain\Clients\Enums\ClientStage;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
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

it('fills every missing value of the Action from the typed defaults', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    $client = app(CreateClientAction::class)->handle(['name' => 'Example action client', 'country' => 'CZ']);

    expect($client->country)->toBe('CZ')
        ->and($client->currency)->toBe('EUR')
        ->and($client->hourly_rate?->minor)->toBe(8550)
        ->and($client->hourly_rate?->currency)->toBe('EUR')
        ->and($client->payment_terms_days)->toBe(30)
        ->and($client->invoice_language)->toBe(InvoiceLanguage::English)
        ->and($client->online_payment_enabled)->toBeTrue()
        ->and($client->stage)->toBe(ClientStage::Active);
});

it('takes the country from the supplier when the Action gets none', function (): void {
    setClientDefaults('CZK', '1200', 14, InvoiceLanguage::Czech, false, 'SK');

    $client = app(CreateClientAction::class)->handle(['name' => 'Example country client']);

    expect($client->country)->toBe('SK');
});

it('keeps a value the caller passes instead of the default', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    $client = app(CreateClientAction::class)->handle([
        'name' => 'Example explicit client',
        'country' => 'CZ',
        'currency' => 'CZK',
        'hourly_rate' => '1500',
        'payment_terms_days' => 0,
        'invoice_language' => 'cs',
        'online_payment_enabled' => false,
        'stage' => 'lead',
    ]);

    expect($client->currency)->toBe('CZK')
        ->and($client->hourly_rate?->minor)->toBe(150000)
        ->and($client->payment_terms_days)->toBe(0)
        ->and($client->invoice_language)->toBe(InvoiceLanguage::Czech)
        ->and($client->online_payment_enabled)->toBeFalse()
        ->and($client->stage)->toBe(ClientStage::Lead);
});

it('does not apply the default rate to a client in another currency than the default', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    try {
        app(CreateClientAction::class)->handle(['name' => 'Example other currency', 'country' => 'CZ', 'currency' => 'CZK']);
        $this->fail('A rate in the wrong currency was invented.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['hourly_rate']);
    }

    expect(Client::query()->where('name', 'Example other currency')->exists())->toBeFalse();
});

it('does not treat an explicitly empty rate as missing', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    expect(fn () => app(CreateClientAction::class)->handle(['name' => 'Example empty rate', 'country' => 'CZ', 'hourly_rate' => '']))
        ->toThrow(ValidationException::class);
});

it('leaves existing clients unchanged when every default changes later and gives the new values to later clients', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    $first = app(CreateClientAction::class)->handle(['name' => 'Example first client']);

    setClientDefaults('USD', '120.25', 7, InvoiceLanguage::Czech, false, 'DE');

    $first->refresh();

    expect($first->currency)->toBe('EUR')
        ->and($first->hourly_rate?->minor)->toBe(8550)
        ->and($first->hourly_rate?->currency)->toBe('EUR')
        ->and($first->payment_terms_days)->toBe(30)
        ->and($first->invoice_language)->toBe(InvoiceLanguage::English)
        ->and($first->online_payment_enabled)->toBeTrue()
        ->and($first->country)->toBe('SK');

    $second = app(CreateClientAction::class)->handle(['name' => 'Example second client']);

    expect($second->currency)->toBe('USD')
        ->and($second->hourly_rate?->minor)->toBe(12025)
        ->and($second->payment_terms_days)->toBe(7)
        ->and($second->invoice_language)->toBe(InvoiceLanguage::Czech)
        ->and($second->online_payment_enabled)->toBeFalse()
        ->and($second->country)->toBe('DE');
});

it('never reads the settings when a client is updated', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    $client = app(CreateClientAction::class)->handle(['name' => 'Example update client']);

    setClientDefaults('USD', '120.25', 7, InvoiceLanguage::Czech, false, 'DE');

    $updated = app(UpdateClientAction::class)->handle($client, [
        'name' => 'Example update client renamed',
        'country' => $client->country,
        'stage' => $client->stage->value,
        'currency' => $client->currency,
        'hourly_rate' => '85,50',
        'payment_terms_days' => $client->payment_terms_days,
        'invoice_language' => $client->invoice_language->value,
        'online_payment_enabled' => $client->online_payment_enabled,
    ]);

    expect($updated->name)->toBe('Example update client renamed')
        ->and($updated->currency)->toBe('EUR')
        ->and($updated->hourly_rate?->minor)->toBe(8550)
        ->and($updated->payment_terms_days)->toBe(30)
        ->and($updated->invoice_language)->toBe(InvoiceLanguage::English)
        ->and($updated->online_payment_enabled)->toBeTrue();
});

it('keeps an edited client form on its stored values after the defaults changed', function (): void {
    setClientDefaults('EUR', '85.50', 30, InvoiceLanguage::English, true, 'SK');

    $client = app(CreateClientAction::class)->handle(['name' => 'Example edit client']);

    setClientDefaults('USD', '120.25', 7, InvoiceLanguage::Czech, false, 'DE');

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertFormSet([
            'currency' => 'EUR',
            'hourly_rate' => '85,50',
            'payment_terms_days' => 30,
            'invoice_language' => InvoiceLanguage::English,
            'online_payment_enabled' => true,
            'country' => 'SK',
        ])
        ->fillForm(['name' => 'Example edit client renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $client->refresh();

    expect($client->name)->toBe('Example edit client renamed')
        ->and($client->currency)->toBe('EUR')
        ->and($client->payment_terms_days)->toBe(30)
        ->and($client->invoice_language)->toBe(InvoiceLanguage::English)
        ->and($client->online_payment_enabled)->toBeTrue();
});
