<?php

declare(strict_types=1);

use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Banking\Iban;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Filament\Pages\SettingsPage;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The bank tab of the settings page (D-03, D-04): accounts per currency, the
 * format decides the fields, the IBAN is checked by the own rule.
 *
 * Fictional IBANs are composed at runtime from a country and a digit fragment,
 * so no line of this file holds a complete IBAN or an account number with its
 * bank code.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * A valid supplier tab, so a Save is not refused for the supplier data.
 *
 * @return array<string, string|null>
 */
function bankTestSupplier(): array
{
    return [
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
    ];
}

/**
 * A valid fictional IBAN for a country, composed at runtime.
 */
function fictionalIban(string $country = 'DE', string $fragment = '7'): string
{
    $length = Iban::lengthFor($country);
    assert($length !== null);

    return Iban::compose($country, substr(str_repeat($fragment, $length), 0, $length - 4));
}

it('stores one IBAN-only account in EUR with a normalised IBAN and shows it again after a reload', function (): void {
    $this->actingAs(Canary::admin());
    $iban = fictionalIban('DE');
    $typed = mb_strtolower(trim(chunk_split($iban, 4, ' ')));

    Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => bankTestSupplier(), 'bank' => ['accounts' => [
            ['format' => 'europe_2', 'label' => 'Example EUR', 'currency' => 'EUR', 'iban' => $typed],
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $accounts = app(BankAccountSettings::class)->accounts;

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0])->toBeInstanceOf(BankAccount::class)
        ->and($accounts[0]->format)->toBe(BankAccountFormat::Europe2)
        ->and($accounts[0]->label)->toBe('Example EUR')
        ->and($accounts[0]->currency)->toBe('EUR')
        ->and($accounts[0]->iban)->toBe($iban)
        ->and($accounts[0]->accountNumber)->toBeNull()
        ->and($accounts[0]->bankCode)->toBeNull()
        ->and($accounts[0]->bic)->toBeNull();

    app()->forgetScopedInstances();

    $component = Livewire::test(SettingsPage::class);
    $shown = array_values($component->get('data.bank.accounts'));

    expect($shown)->toHaveCount(1)
        ->and($shown[0]['label'])->toBe('Example EUR')
        ->and($shown[0]['iban'])->toBe($iban);
});

it('shows a form error on the IBAN of the repeater item and stores nothing when the checksum is broken', function (): void {
    $this->actingAs(Canary::admin());
    $iban = fictionalIban('DE');
    $broken = substr($iban, 0, -1).(string) (((int) substr($iban, -1) + 1) % 10);

    $test = Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => bankTestSupplier(), 'bank' => ['accounts' => [
            ['format' => 'europe_2', 'label' => 'Example EUR', 'currency' => 'EUR', 'iban' => $broken],
        ]]])
        ->call('save');

    $errors = $test->errors()->toArray();
    $ibanKeys = array_values(array_filter(array_keys($errors), fn (string $key): bool => str_ends_with($key, '.iban')));

    expect($ibanKeys)->toHaveCount(1)
        ->and($errors[$ibanKeys[0]])->toBe([__('kokpit.settings.bank.iban_invalid')]);

    app()->forgetScopedInstances();

    expect(app(BankAccountSettings::class)->accounts)->toBe([]);
});
