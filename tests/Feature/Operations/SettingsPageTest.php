<?php

declare(strict_types=1);

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Settings\VatMode;
use App\Domain\Shared\Models\SettingsProperty;
use App\Filament\Pages\SettingsPage;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The one settings page (D-02): an Admin edits the supplier data in Czech and it
 * persists; nobody else gets in.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * @return array<string, string|null>
 */
function fictionalSupplierState(string $email): array
{
    return [
        'company_name' => 'Example s.r.o.',
        'street' => 'Sample Street 1',
        'city' => 'Sampletown',
        'postal_code' => '10000',
        'country' => 'CZ',
        'company_id' => '12345678',
        'vat_id' => null,
        'email' => $email,
        'phone' => null,
        'website' => null,
        'registration_note' => 'Registered in the example register.',
    ];
}

it('saves the supplier data on the page and shows it after a reload', function (): void {
    $email = exampleEmail();
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => fictionalSupplierState($email)])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $fresh = app(SupplierSettings::class);

    expect($fresh->company_name)->toBe('Example s.r.o.')
        ->and($fresh->company_id)->toBe('12345678')
        ->and($fresh->email)->toBe($email)
        ->and(SettingsProperty::query()->where('group', 'supplier')->exists())->toBeTrue();

    app()->forgetScopedInstances();

    Livewire::test(SettingsPage::class)
        ->assertSet('data.supplier.company_name', 'Example s.r.o.')
        ->assertSet('data.supplier.company_id', '12345678')
        ->assertSet('data.supplier.email', $email);
});

it('keeps empty optional fields empty and does not choke on empty required-type strings', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => [
            ...fictionalSupplierState(exampleEmail()),
            'street' => '',
            'company_id' => '',
            'phone' => '',
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $fresh = app(SupplierSettings::class);

    expect($fresh->street)->toBe('')
        ->and($fresh->company_id)->toBeNull()
        ->and($fresh->phone)->toBeNull();
});

it('refuses invalid supplier values with a field error and writes nothing', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => [
            ...fictionalSupplierState(exampleEmail()),
            'company_name' => '',
            'company_id' => '1234567',
        ]])
        ->call('save')
        ->assertHasFormErrors(['supplier.company_name', 'supplier.company_id']);

    app()->forgetScopedInstances();

    expect(app(SupplierSettings::class)->company_name)->toBe('');
});

it('renders the Czech navigation label and tab for the Admin', function (): void {
    $this->actingAs(Canary::admin())->get('/admin/settings')
        ->assertOk()
        ->assertSee(__('kokpit.settings.navigation_label'))
        ->assertSee(__('kokpit.settings.tabs.supplier'))
        ->assertSee(__('kokpit.settings.save'));
});

it('refuses the settings page to a Partner with a client, a Partner without a client and a user without a role', function (): void {
    $states = [
        'partner with a client' => fn () => Canary::partnerFor(Canary::twoClients()[0]),
        'partner without a client' => fn () => Canary::partnerFor(null),
        'user without a role' => fn () => Canary::userWithoutRole(null),
    ];

    foreach ($states as $state => $make) {
        $this->actingAs($make())->get('/admin/settings')->assertForbidden();
    }
});

it('runs no settings query for a Partner before refusing', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->get('/admin/settings')->assertForbidden();

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"settings"')))->toBe([]);
});

it('rolls the save back and shows a data-layer error on the field when the class refuses the values', function (): void {
    $this->actingAs(Canary::admin());

    // Looser than the form on purpose: it writes first and is then refused by its own stricter rules,
    // so only a rollback of the page's transaction keeps the table unchanged.
    app()->bind(SupplierSettings::class, fn (): SupplierSettings => new class extends SupplierSettings
    {
        public static function rules(): array
        {
            return [...parent::rules(), 'company_name' => ['required', 'string', 'max:5']];
        }

        public function save(): static
        {
            DB::table('settings')
                ->where('group', 'supplier')
                ->where('name', 'country')
                ->update(['payload' => json_encode('DE', JSON_THROW_ON_ERROR)]);

            return parent::save();
        }
    });

    $before = SettingsProperty::query()->where('group', 'supplier')->pluck('payload', 'name')->all();

    Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => fictionalSupplierState(exampleEmail())])
        ->call('save')
        ->assertHasErrors(['data.supplier.company_name']);

    expect(SettingsProperty::query()->where('group', 'supplier')->pluck('payload', 'name')->all())->toBe($before);
});

it('saves the default currency and a default rate typed with a decimal comma and shows it after a reload', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'defaults' => ['default_currency' => 'CZK', 'default_hourly_rate' => '1250,50', 'default_invoice_language' => 'cs'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $fresh = app(DefaultsSettings::class);

    expect($fresh->default_currency)->toBe('CZK')
        ->and($fresh->default_hourly_rate->minor)->toBe(125050)
        ->and($fresh->default_hourly_rate->currency)->toBe('CZK');

    app()->forgetScopedInstances();

    Livewire::test(SettingsPage::class)
        ->assertSet('data.defaults.default_currency', 'CZK')
        ->assertSet('data.defaults.default_hourly_rate', '1250,50');
});

it('saves the default invoice language on the defaults tab and shows it after a reload', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->assertSet('data.defaults.default_invoice_language', 'cs')
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'defaults' => ['default_currency' => 'CZK', 'default_hourly_rate' => '1250,50', 'default_invoice_language' => 'en'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(app(DefaultsSettings::class)->default_invoice_language)->toBe(InvoiceLanguage::English);

    app()->forgetScopedInstances();

    Livewire::test(SettingsPage::class)
        ->assertSet('data.defaults.default_invoice_language', 'en');
});

it('refuses an unknown default invoice language on the page and stores nothing', function (): void {
    $this->actingAs(Canary::admin());

    $before = SettingsProperty::query()->where('group', 'defaults')->pluck('payload', 'name')->all();

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'defaults' => ['default_currency' => 'CZK', 'default_hourly_rate' => '10', 'default_invoice_language' => 'de'],
        ])
        ->call('save')
        ->assertHasFormErrors(['defaults.default_invoice_language']);

    expect(SettingsProperty::query()->where('group', 'defaults')->pluck('payload', 'name')->all())->toBe($before);
});

it('shows a form error on the rate and stores nothing when more decimals are typed than the currency allows', function (): void {
    $this->actingAs(Canary::admin());

    $before = SettingsProperty::query()->where('group', 'defaults')->pluck('payload', 'name')->all();

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'defaults' => ['default_currency' => 'CZK', 'default_hourly_rate' => '12,345', 'default_invoice_language' => 'cs'],
        ])
        ->call('save')
        ->assertHasFormErrors(['defaults.default_hourly_rate']);

    expect(SettingsProperty::query()->where('group', 'defaults')->pluck('payload', 'name')->all())->toBe($before);
});

it('refuses a grouped, a negative and an empty rate on the page', function (string $typed): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'defaults' => ['default_currency' => 'CZK', 'default_hourly_rate' => $typed, 'default_invoice_language' => 'cs'],
        ])
        ->call('save')
        ->assertHasFormErrors(['defaults.default_hourly_rate']);
})->with(['1 250,50', '-5', '']);

it('checks the decimals against the selected currency, so a currency without fraction takes whole amounts only', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'defaults' => ['default_currency' => 'JPY', 'default_hourly_rate' => '10,5', 'default_invoice_language' => 'cs'],
        ])
        ->call('save')
        ->assertHasFormErrors(['defaults.default_hourly_rate']);
});

it('saves the VAT mode and the payment due days on the invoicing tab and shows them after a reload', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'invoicing' => ['vat_mode' => 'non_payer', 'payment_due_days' => 30],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $fresh = app(InvoicingSettings::class);

    expect($fresh->vat_mode)->toBe(VatMode::NonPayer)
        ->and($fresh->payment_due_days)->toBe(30);

    app()->forgetScopedInstances();

    Livewire::test(SettingsPage::class)
        ->assertSet('data.invoicing.vat_mode', 'non_payer')
        ->assertSet('data.invoicing.payment_due_days', 30);
});

it('saves the VAT mode, the due days and the online-payment toggle in one Save and shows them after a reload', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'invoicing' => ['vat_mode' => 'non_payer', 'payment_due_days' => 30],
            'payments' => ['online_payments_enabled' => true],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(app(InvoicingSettings::class)->payment_due_days)->toBe(30)
        ->and(app(PaymentSettings::class)->online_payments_enabled)->toBeTrue();

    app()->forgetScopedInstances();

    Livewire::test(SettingsPage::class)
        ->assertSet('data.invoicing.payment_due_days', 30)
        ->assertSet('data.payments.online_payments_enabled', true);
});

it('shows the payer option in the VAT mode select but disabled', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->assertFormFieldExists('invoicing.vat_mode', function (Select $field): bool {
            return array_keys($field->getEnabledOptions()) === ['non_payer']
                && array_key_exists('payer', $field->getOptions());
        });
});

it('refuses a forced payer VAT mode with a form error and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = SettingsProperty::query()->where('group', 'invoicing')->pluck('payload', 'name')->all();

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'invoicing' => ['vat_mode' => 'payer', 'payment_due_days' => 30],
        ])
        ->call('save')
        ->assertHasFormErrors(['invoicing.vat_mode']);

    expect(SettingsProperty::query()->where('group', 'invoicing')->pluck('payload', 'name')->all())->toBe($before);
});

it('refuses payment due days outside 0 to 365 with a form error', function (int $days): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'invoicing' => ['vat_mode' => 'non_payer', 'payment_due_days' => $days],
        ])
        ->call('save')
        ->assertHasFormErrors(['invoicing.payment_due_days']);
})->with([-1, 366]);

it('accepts the payment due day boundaries 0 and 365', function (int $days): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => fictionalSupplierState(exampleEmail()),
            'invoicing' => ['vat_mode' => 'non_payer', 'payment_due_days' => $days],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(app(InvoicingSettings::class)->payment_due_days)->toBe($days);
})->with([0, 365]);
