<?php

declare(strict_types=1);

use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Banking\Iban;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Filament\Pages\SettingsPage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
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

/**
 * The form state of a Save with a valid supplier tab and the given accounts.
 *
 * @param  list<array<string, string|null>>  $accounts
 * @return array<string, mixed>
 */
function bankFormState(array $accounts): array
{
    return ['supplier' => bankTestSupplier(), 'bank' => ['accounts' => $accounts]];
}

/**
 * The error keys of a component that end with the given field name.
 *
 * @return list<string>
 */
function bankErrorKeys(Testable $test, string $field): array
{
    return array_values(array_filter(
        array_keys($test->errors()->toArray()),
        static fn (string $key): bool => str_ends_with($key, '.'.$field),
    ));
}

/**
 * A Europe 1 account (account number, bank code, bank name, IBAN) of fictional data.
 *
 * @param  array<string, string|null>  $override
 * @return array<string, string|null>
 */
function bankEurope1(array $override = []): array
{
    return [
        'format' => 'europe_1',
        'label' => 'Example CZK',
        'currency' => 'CZK',
        'account_number' => '1234567',
        'bank_code' => '4321',
        'bank_name' => 'Example Bank',
        'iban' => fictionalIban('CZ', '5'),
        ...$override,
    ];
}

/**
 * A World account (account number, recipient, bank name and address) of fictional data.
 *
 * @param  array<string, string|null>  $override
 * @return array<string, string|null>
 */
function bankWorld(array $override = []): array
{
    return [
        'format' => 'world',
        'label' => 'Example USD',
        'currency' => 'USD',
        'account_number' => 'AB1234567',
        'recipient_name' => 'Example s.r.o.',
        'bank_name' => 'Example Bank Ltd',
        'bank_address' => '1 Sample Street, Sampletown',
        ...$override,
    ];
}

it('saves a Europe 1 account with account number, bank code and IBAN and keeps the world-only fields empty', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankEurope1(['recipient_name' => 'Ignored', 'bank_address' => 'Ignored'])]))
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $account = app(BankAccountSettings::class)->accounts[0];

    expect($account->format)->toBe(BankAccountFormat::Europe1)
        ->and($account->accountNumber)->toBe('1234567')
        ->and($account->bankCode)->toBe('4321')
        ->and($account->bankName)->toBe('Example Bank')
        ->and($account->iban)->toBe(fictionalIban('CZ', '5'))
        ->and($account->recipientName)->toBeNull()
        ->and($account->bankAddress)->toBeNull();
});

it('refuses a Europe 1 account without a bank code or with a three-digit bank code on that field', function (?string $bankCode): void {
    $this->actingAs(Canary::admin());

    $test = Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankEurope1(['bank_code' => $bankCode])]))
        ->call('save');

    expect(bankErrorKeys($test, 'bank_code'))->toHaveCount(1);

    app()->forgetScopedInstances();

    expect(app(BankAccountSettings::class)->accounts)->toBe([]);
})->with([
    'missing' => [null],
    'three digits' => ['432'],
    'five digits' => ['43210'],
    'letters' => ['43AB'],
]);

it('refuses a Europe 1 account number that is not a number with an optional prefix', function (string $accountNumber): void {
    $this->actingAs(Canary::admin());

    $test = Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankEurope1(['account_number' => $accountNumber])]))
        ->call('save');

    expect(bankErrorKeys($test, 'account_number'))->toHaveCount(1);
})->with(['letters' => ['12AB56'], 'one digit' => ['1'], 'too long' => ['12345678901'], 'trailing dash' => ['123-']]);

it('shows the fields each format needs and hides the others', function (): void {
    $this->actingAs(Canary::admin());

    $test = Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankEurope1(), bankWorld()]));

    [$europe, $world] = array_keys($test->get('data.bank.accounts'));
    $path = static fn (int|string $key, string $field): string => "bank.accounts.{$key}.{$field}";

    foreach (['account_number', 'bank_code', 'bank_name', 'iban'] as $field) {
        $test->assertFormFieldIsVisible($path($europe, $field));
    }
    foreach (['recipient_name', 'bank_address'] as $field) {
        $test->assertFormFieldIsHidden($path($europe, $field));
    }
    foreach (['account_number', 'recipient_name', 'bank_name', 'bank_address'] as $field) {
        $test->assertFormFieldIsVisible($path($world, $field));
    }
    foreach (['iban', 'bank_code'] as $field) {
        $test->assertFormFieldIsHidden($path($world, $field));
    }
});

it('saves a World account with account number, recipient and bank and stores the IBAN and bank code as null even when sent', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankWorld(['iban' => fictionalIban('DE'), 'bank_code' => '4321'])]))
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $account = app(BankAccountSettings::class)->accounts[0];

    expect($account->format)->toBe(BankAccountFormat::World)
        ->and($account->accountNumber)->toBe('AB1234567')
        ->and($account->recipientName)->toBe('Example s.r.o.')
        ->and($account->bankName)->toBe('Example Bank Ltd')
        ->and($account->iban)->toBeNull()
        ->and($account->bankCode)->toBeNull();
});

it('refuses a World account without an account number, recipient name or bank name on that field', function (string $field): void {
    $this->actingAs(Canary::admin());

    $test = Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankWorld([$field => null])]))
        ->call('save');

    expect(bankErrorKeys($test, $field))->toHaveCount(1);
})->with(['account_number', 'recipient_name', 'bank_name']);

it('refuses a second account in the same currency with a form error on its currency field', function (): void {
    $this->actingAs(Canary::admin());

    $test = Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([
            bankEurope1(),
            bankEurope1(['label' => 'Second CZK', 'iban' => fictionalIban('CZ', '6')]),
        ]));

    [, $second] = array_keys($test->get('data.bank.accounts'));
    $test->call('save');

    // Laravel's distinct rule marks every duplicate, so the first item's currency is flagged as well.
    expect(bankErrorKeys($test, 'currency'))->toContain("data.bank.accounts.{$second}.currency");

    app()->forgetScopedInstances();

    expect(app(BankAccountSettings::class)->accounts)->toBe([]);
});

it('refuses two accounts in the same currency written outside the form, names the currency and changes nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = DB::table('settings')->where('group', 'bank')->pluck('payload', 'name')->all();

    $settings = app(BankAccountSettings::class);
    $settings->fillFromFormState(['accounts' => [
        bankEurope1(['currency' => 'czk']),
        bankEurope1(['label' => 'Second CZK', 'currency' => 'CZK', 'iban' => fictionalIban('CZ', '6')]),
    ]]);

    try {
        $settings->save();
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect(implode(' ', array_merge(...array_values($exception->errors()))))->toContain('CZK');
    }

    expect(DB::table('settings')->where('group', 'bank')->pluck('payload', 'name')->all())->toBe($before);
});

it('refuses an invalid IBAN written outside the form and changes nothing', function (): void {
    $this->actingAs(Canary::admin());
    $iban = fictionalIban('CZ', '5');

    $settings = app(BankAccountSettings::class);
    $settings->fillFromFormState(['accounts' => [bankEurope1(['iban' => substr($iban, 0, -1).(((int) substr($iban, -1) + 1) % 10)])]]);

    expect(fn () => $settings->save())->toThrow(ValidationException::class);

    app()->forgetScopedInstances();

    expect(app(BankAccountSettings::class)->accounts)->toBe([]);
});

it('stores a lower-case BIC in upper case and refuses a malformed BIC', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankEurope1(['bic' => ' abcdczpp '])]))
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(app(BankAccountSettings::class)->accounts[0]->bic)->toBe('ABCDCZPP');

    $test = Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankEurope1(['bic' => 'NOTABIC'])]))
        ->call('save');

    expect(bankErrorKeys($test, 'bic'))->toHaveCount(1);
});

it('refuses a malformed BIC written outside the form', function (): void {
    $this->actingAs(Canary::admin());

    $settings = app(BankAccountSettings::class);
    $settings->fillFromFormState(['accounts' => [bankEurope1(['bic' => '12'])]]);

    expect(fn () => $settings->save())->toThrow(ValidationException::class);
});

it('loads a stored payload without the hidden keys into bank accounts', function (): void {
    $this->actingAs(Canary::admin());
    DB::table('settings')->where('group', 'bank')->where('name', 'accounts')->update(['payload' => json_encode([
        ['format' => 'europe_2', 'label' => 'Legacy EUR', 'currency' => 'EUR', 'iban' => fictionalIban('DE')],
        ['format' => 'world', 'label' => 'Legacy USD', 'currency' => 'USD', 'account_number' => 'AB1234567'],
    ], JSON_THROW_ON_ERROR)]);
    app()->forgetScopedInstances();

    $accounts = app(BankAccountSettings::class)->accounts;

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->accountNumber)->toBeNull()
        ->and($accounts[0]->recipientName)->toBeNull()
        ->and($accounts[1]->iban)->toBeNull()
        ->and($accounts[1]->accountNumber)->toBe('AB1234567');
});

it('writes every key for every account and null for the fields the format hides', function (): void {
    $this->actingAs(Canary::admin());

    $settings = app(BankAccountSettings::class);
    $settings->fillFromFormState(['accounts' => [
        bankWorld(['iban' => fictionalIban('DE'), 'bank_code' => '4321']),
        ['format' => 'europe_2', 'label' => 'Example EUR', 'currency' => 'EUR', 'iban' => fictionalIban('DE'), 'account_number' => '1234567', 'bank_name' => 'Ignored'],
    ]]);
    $settings->save();

    $stored = json_decode((string) DB::table('settings')->where('group', 'bank')->where('name', 'accounts')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
    $keys = ['format', 'label', 'currency', 'bic', 'account_number', 'bank_code', 'bank_name', 'iban', 'recipient_name', 'bank_address'];

    expect(array_keys($stored[0]))->toEqualCanonicalizing($keys)
        ->and(array_keys($stored[1]))->toEqualCanonicalizing($keys)
        ->and($stored[0]['iban'])->toBeNull()
        ->and($stored[0]['bank_code'])->toBeNull()
        ->and($stored[1]['account_number'])->toBeNull()
        ->and($stored[1]['bank_name'])->toBeNull();
});

it('finds the account of a currency and returns null for a currency without one', function (): void {
    $this->actingAs(Canary::admin());

    $settings = app(BankAccountSettings::class);
    $settings->fillFromFormState(['accounts' => [
        bankEurope1(),
        ['format' => 'europe_2', 'label' => 'Example EUR', 'currency' => 'EUR', 'iban' => fictionalIban('DE')],
    ]]);
    $settings->save();
    app()->forgetScopedInstances();

    $fresh = app(BankAccountSettings::class);

    expect($fresh->forCurrency('EUR')?->label)->toBe('Example EUR')
        ->and($fresh->forCurrency('eur')?->label)->toBe('Example EUR')
        ->and($fresh->forCurrency('CZK')?->label)->toBe('Example CZK')
        ->and($fresh->forCurrency('USD'))->toBeNull();
});

it('saves three accounts of different formats together and shows them in order', function (): void {
    $this->actingAs(Canary::admin());
    $eur = ['format' => 'europe_2', 'label' => 'Example EUR', 'currency' => 'EUR', 'iban' => fictionalIban('DE')];

    Livewire::test(SettingsPage::class)
        ->fillForm(bankFormState([bankEurope1(), $eur, bankWorld()]))
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(array_map(fn (BankAccount $account): string => $account->currency, app(BankAccountSettings::class)->accounts))
        ->toBe(['CZK', 'EUR', 'USD']);

    app()->forgetScopedInstances();

    $shown = array_values(Livewire::test(SettingsPage::class)->get('data.bank.accounts'));

    expect(array_column($shown, 'currency'))->toBe(['CZK', 'EUR', 'USD'])
        ->and(array_column($shown, 'format'))->toBe(['europe_1', 'europe_2', 'world']);
});
