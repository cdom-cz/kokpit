<?php

declare(strict_types=1);

use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Settings\VatMode;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Money\Money;
use App\Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Data-layer protection of the settings groups beyond the supplier data: what
 * the settings page cannot enforce on its own, because code outside the form
 * can write settings too.
 */

/**
 * @return array<string, mixed>
 */
function storedDefaults(): array
{
    return SettingsProperty::query()->where('group', 'defaults')->pluck('payload', 'name')->all();
}

it('starts with CZK as default currency and a zero CZK rate after migrate', function (): void {
    $this->actingAs(Canary::admin());

    $defaults = app(DefaultsSettings::class);

    expect($defaults->default_currency)->toBe('CZK')
        ->and($defaults->default_hourly_rate->equals(Money::zero('CZK')))->toBeTrue();
});

it('stores the default rate as integer minor units plus currency and reads it back', function (): void {
    $this->actingAs(Canary::admin());

    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = 'EUR';
    $defaults->default_hourly_rate = Money::ofMinor(8550, 'EUR');
    $defaults->save();
    app()->forgetScopedInstances();

    $fresh = app(DefaultsSettings::class);

    expect($fresh->default_currency)->toBe('EUR')
        ->and($fresh->default_hourly_rate->equals(Money::ofMinor(8550, 'EUR')))->toBeTrue()
        ->and(json_decode((string) SettingsProperty::query()->where('group', 'defaults')->where('name', 'default_hourly_rate')->value('payload'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['minor' => 8550, 'currency' => 'EUR']);
});

it('refuses a default rate in another currency than the default currency and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedDefaults();

    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = 'CZK';
    $defaults->default_hourly_rate = Money::ofMinor(8550, 'EUR');

    expect(fn () => $defaults->save())->toThrow(ValidationException::class);
    expect(storedDefaults())->toBe($before);
});

it('refuses a negative default rate and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedDefaults();

    $defaults = app(DefaultsSettings::class);
    $defaults->default_hourly_rate = Money::ofMinor(-100, 'CZK');

    expect(fn () => $defaults->save())->toThrow(ValidationException::class);
    expect(storedDefaults())->toBe($before);
});

it('refuses an unknown default currency and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedDefaults();

    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = 'XYZ';
    $defaults->default_hourly_rate = Money::zero('CZK');

    expect(fn () => $defaults->save())->toThrow(ValidationException::class);
    expect(storedDefaults())->toBe($before);
});

it('names the rate field when the class refuses a mismatching currency', function (): void {
    $this->actingAs(Canary::admin());

    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = 'CZK';
    $defaults->default_hourly_rate = Money::ofMinor(100, 'EUR');

    try {
        $defaults->save();
        $this->fail('The mismatching currency was saved.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['default_hourly_rate']);
    }
});

it('turns a rate text that Money refuses into a field error instead of an exception of another kind', function (): void {
    $this->actingAs(Canary::admin());

    $defaults = app(DefaultsSettings::class);

    try {
        $defaults->fillFromFormState(['default_currency' => 'CZK', 'default_hourly_rate' => '12,345']);
        $this->fail('The excess decimals were accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['default_hourly_rate']);
    }
});

/**
 * @return array<string, mixed>
 */
function storedGroup(string $group): array
{
    return SettingsProperty::query()->where('group', $group)->pluck('payload', 'name')->all();
}

it('starts with non-payer, 14 due days and online payments off after migrate', function (): void {
    $this->actingAs(Canary::admin());

    $invoicing = app(InvoicingSettings::class);

    expect($invoicing->vat_mode)->toBe(VatMode::NonPayer)
        ->and($invoicing->payment_due_days)->toBe(14)
        ->and(app(PaymentSettings::class)->online_payments_enabled)->toBeFalse();
});

it('refuses the payer VAT mode outside the form and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedGroup('invoicing');

    $invoicing = app(InvoicingSettings::class);
    $invoicing->vat_mode = VatMode::Payer;

    try {
        $invoicing->save();
        $this->fail('The payer mode was saved.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['vat_mode']);
    }

    expect(storedGroup('invoicing'))->toBe($before);
});

it('refuses payment due days outside 0 to 365 outside the form and stores nothing', function (int $days): void {
    $this->actingAs(Canary::admin());
    $before = storedGroup('invoicing');

    $invoicing = app(InvoicingSettings::class);
    $invoicing->payment_due_days = $days;

    expect(fn () => $invoicing->save())->toThrow(ValidationException::class);
    expect(storedGroup('invoicing'))->toBe($before);
})->with([-1, 366, 400]);

it('stores the online-payment toggle as a boolean', function (): void {
    $this->actingAs(Canary::admin());

    $payments = app(PaymentSettings::class);
    $payments->online_payments_enabled = true;
    $payments->save();
    app()->forgetScopedInstances();

    expect(app(PaymentSettings::class)->online_payments_enabled)->toBeTrue()
        ->and(json_decode((string) SettingsProperty::query()->where('group', 'payments')->where('name', 'online_payments_enabled')->value('payload'), true, 512, JSON_THROW_ON_ERROR))
        ->toBeTrue();
});

it('turns a non-integer due day text and an unknown VAT mode into field errors', function (): void {
    $this->actingAs(Canary::admin());

    foreach ([['vat_mode' => 'bogus'], ['payment_due_days' => '3.5'], ['payment_due_days' => 'abc']] as $state) {
        $invoicing = app(InvoicingSettings::class);

        try {
            $invoicing->fillFromFormState($state);
            $this->fail('The value was accepted.');
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe([array_key_first($state)]);
        }
    }
});

it('stores nothing in any group when one Save has a valid tab and a tab the data layer refuses', function (): void {
    $this->actingAs(Canary::admin());

    // The form accepts 30 days; this class refuses it after the supplier group was already written.
    app()->bind(InvoicingSettings::class, fn (): InvoicingSettings => new class extends InvoicingSettings
    {
        public static function rules(): array
        {
            return [...parent::rules(), 'payment_due_days' => ['required', 'integer', 'max:20']];
        }
    });

    $supplierBefore = storedGroup('supplier');
    $invoicingBefore = storedGroup('invoicing');

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => [
                'company_name' => 'Example s.r.o.',
                'street' => 'Sample Street 1',
                'city' => 'Sampletown',
                'postal_code' => '10000',
                'country' => 'CZ',
                'company_id' => '12345678',
                'vat_id' => null,
                'email' => exampleEmail(),
                'phone' => null,
                'website' => null,
                'registration_note' => null,
            ],
            'invoicing' => ['vat_mode' => 'non_payer', 'payment_due_days' => 30],
        ])
        ->call('save')
        ->assertHasErrors(['data.invoicing.payment_due_days']);

    expect(storedGroup('supplier'))->toBe($supplierBefore)
        ->and(storedGroup('invoicing'))->toBe($invoicingBefore);
});

it('has Czech wording for the invoicing and payments tabs', function (): void {
    foreach (['kokpit.settings.tabs.invoicing', 'kokpit.settings.tabs.payments', 'kokpit.settings.invoicing.vat_mode', 'kokpit.settings.payments.online_payments_enabled'] as $key) {
        expect(Lang::has($key, 'cs'))->toBeTrue($key);
    }
});
