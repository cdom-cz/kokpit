<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateClient as CreateClientAction;
use App\Domain\Clients\Actions\UpdateClient as UpdateClientAction;
use App\Domain\Clients\Enums\ClientStage;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin client screens (CL-01, D-09, D-10). Livewire page tests as the Admin;
 * every name and value is fictional and no company number passes the CZ checksum.
 */

/**
 * A complete, valid client form state; overrides replace single fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clientForm(array $overrides = []): array
{
    return [
        'country' => 'CZ',
        'company_number' => '12345678',
        'name' => 'Example client '.mb_strtolower(Str::random(6)),
        'tax_number' => 'CZ12345678',
        'street' => 'Example street 1',
        'city' => 'Example town',
        'postal_code' => '100 00',
        'stage' => 'active',
        'currency' => 'CZK',
        'hourly_rate' => '1500,50',
        'payment_terms_days' => 14,
        'invoice_email' => 'billing@example.com',
        'invoice_language' => 'cs',
        'online_payment_enabled' => true,
        ...$overrides,
    ];
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('creates a client with every field and stores the rate as an exact Money pair', function (): void {
    $state = clientForm(['name' => 'Example client full']);

    Livewire::test(CreateClient::class)
        ->fillForm($state)
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Example client full')->firstOrFail();

    expect($client->country)->toBe('CZ')
        ->and($client->company_number)->toBe('12345678')
        ->and($client->tax_number)->toBe('CZ12345678')
        ->and($client->street)->toBe('Example street 1')
        ->and($client->city)->toBe('Example town')
        ->and($client->postal_code)->toBe('100 00')
        ->and($client->stage)->toBe(ClientStage::Active)
        ->and($client->currency)->toBe('CZK')
        ->and($client->hourly_rate_minor)->toBe(150050)
        ->and($client->hourly_rate_currency)->toBe('CZK')
        ->and($client->hourly_rate?->minor)->toBe(150050)
        ->and($client->payment_terms_days)->toBe(14)
        ->and($client->invoice_email)->toBe('billing@example.com')
        ->and($client->invoice_language)->toBe(InvoiceLanguage::Czech)
        ->and($client->online_payment_enabled)->toBeTrue();
});

it('shows the new client in the list', function (): void {
    $client = Client::factory()->create(['name' => 'Example client listed']);

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$client])
        ->searchTable('Example client listed')
        ->assertCanSeeTableRecords([$client]);
});

it('loads the rate as decimal-comma text and edits the stage and the invoice language', function (): void {
    $client = Client::factory()->create([
        'currency' => 'EUR',
        'hourly_rate' => Money::ofMinor(8550, 'EUR'),
        'stage' => 'lead',
        'invoice_language' => 'cs',
    ]);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertFormSet(['hourly_rate' => '85,50', 'currency' => 'EUR'])
        ->fillForm(['stage' => 'paused', 'invoice_language' => 'en'])
        ->call('save')
        ->assertHasNoFormErrors();

    $client->refresh();

    expect($client->stage)->toBe(ClientStage::Paused)
        ->and($client->invoice_language)->toBe(InvoiceLanguage::English)
        ->and($client->hourly_rate?->minor)->toBe(8550)
        ->and($client->hourly_rate?->currency)->toBe('EUR');
});

it('gives a Partner 403 on every client route', function (): void {
    $client = Client::factory()->create();
    $partner = Canary::partnerFor($client->id);

    $this->actingAs($partner);

    $this->get(ClientResource::getUrl('index'))->assertForbidden();
    $this->get(ClientResource::getUrl('create'))->assertForbidden();
    $this->get(ClientResource::getUrl('edit', ['record' => $client->id]))->assertForbidden();
});

it('is not globally searchable', function (): void {
    expect(ClientResource::canGloballySearch())->toBeFalse();
});

it('refuses a malformed rate as a field error on hourly_rate and creates no client', function (string $rate): void {
    $before = Client::query()->count();

    Livewire::test(CreateClient::class)
        ->fillForm(clientForm(['hourly_rate' => $rate]))
        ->call('create')
        ->assertHasFormErrors(['hourly_rate']);

    expect(Client::query()->count())->toBe($before);
})->with([
    'too many decimals' => '1,234',
    'grouping space' => '1 500',
    'grouping comma and point' => '1,500.00',
    'negative' => '-5',
    'empty' => '',
    'text' => 'abc',
]);

it('stores the typed amount exactly in the minor units of the currency', function (string $currency, string $typed, int $minor): void {
    Livewire::test(CreateClient::class)
        ->fillForm(clientForm(['name' => 'Example client exact', 'currency' => $currency, 'hourly_rate' => $typed]))
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Example client exact')->firstOrFail();

    expect($client->hourly_rate_minor)->toBe($minor)
        ->and($client->hourly_rate_currency)->toBe($currency);
})->with([
    'CZK comma' => ['CZK', '1500,05', 150005],
    'CZK point' => ['CZK', '0.1', 10],
    'CZK whole' => ['CZK', '1500', 150000],
    'JPY has no minor unit' => ['JPY', '1500', 1500],
]);

it('refuses a fraction on a currency without minor unit', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(clientForm(['currency' => 'JPY', 'hourly_rate' => '1500,5']))
        ->call('create')
        ->assertHasFormErrors(['hourly_rate']);
});

it('accepts payment terms of 0 and 365 days and refuses -1 and 366', function (int $days, bool $valid): void {
    $name = 'Example client terms '.$days;

    $test = Livewire::test(CreateClient::class)
        ->fillForm(clientForm(['name' => $name, 'payment_terms_days' => $days]))
        ->call('create');

    if ($valid) {
        $test->assertHasNoFormErrors();
        expect(Client::query()->where('name', $name)->firstOrFail()->payment_terms_days)->toBe($days);

        return;
    }

    $test->assertHasFormErrors(['payment_terms_days']);
    expect(Client::query()->where('name', $name)->exists())->toBeFalse();
})->with([
    'zero' => [0, true],
    'upper bound' => [365, true],
    'below' => [-1, false],
    'above' => [366, false],
]);

it('refuses an invalid e-mail, a lower-case or three-letter country and an empty name as field errors', function (array $override, string $field): void {
    Livewire::test(CreateClient::class)
        ->fillForm(clientForm($override))
        ->call('create')
        ->assertHasFormErrors([$field]);
})->with([
    'e-mail' => [['invoice_email' => 'not-an-email'], 'invoice_email'],
    'lower-case country' => [['country' => 'cz'], 'country'],
    'three-letter country' => [['country' => 'CZE'], 'country'],
    'empty name' => [['name' => ''], 'name'],
]);

it('accepts an empty invoice e-mail and no optional billing data', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(clientForm([
            'name' => 'Example client minimal',
            'company_number' => null,
            'tax_number' => null,
            'street' => null,
            'city' => null,
            'postal_code' => null,
            'invoice_email' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Example client minimal')->firstOrFail();

    expect($client->company_number)->toBeNull()
        ->and($client->invoice_email)->toBeNull();
});

it('saves a foreign client with a free-text company number', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(clientForm([
            'name' => 'Example client abroad',
            'country' => 'DE',
            'company_number' => 'HRB 000 example',
            'currency' => 'EUR',
            'hourly_rate' => '90',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Example client abroad')->firstOrFail();

    expect($client->country)->toBe('DE')
        ->and($client->company_number)->toBe('HRB 000 example')
        ->and($client->hourly_rate_minor)->toBe(9000);
});

it('applies the same rate rule when a client is edited', function (string $rate): void {
    $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['hourly_rate' => $rate])
        ->call('save')
        ->assertHasFormErrors(['hourly_rate']);

    expect($client->refresh()->hourly_rate_minor)->toBe(100000);
})->with(['1,234', '1 500', '-5', '']);

it('keeps the rate in step with a changed currency on edit', function (): void {
    $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['currency' => 'EUR', 'hourly_rate' => '45,5'])
        ->call('save')
        ->assertHasNoFormErrors();

    $client->refresh();

    expect($client->currency)->toBe('EUR')
        ->and($client->hourly_rate_minor)->toBe(4550)
        ->and($client->hourly_rate_currency)->toBe('EUR');
});

it('refuses a crafted payload in the Actions the same way as the form', function (): void {
    $valid = [
        'name' => 'Example client direct',
        'country' => 'cz',
        'stage' => 'active',
        'currency' => 'czk',
        'hourly_rate' => '10,5',
        'payment_terms_days' => '30',
        'invoice_language' => 'en',
    ];

    $client = app(CreateClientAction::class)->handle($valid);

    // Country and currency are upper-cased by the Action.
    expect($client->country)->toBe('CZ')
        ->and($client->currency)->toBe('CZK')
        ->and($client->hourly_rate_minor)->toBe(1050);

    $bad = ['stage' => 'archived', 'invoice_language' => 'de', 'payment_terms_days' => '366', 'hourly_rate' => '1,234'];

    foreach ($bad as $key => $value) {
        expect(fn () => app(UpdateClientAction::class)->handle($client, [...$valid, $key => $value]))
            ->toThrow(ValidationException::class);
    }

    expect($client->refresh()->hourly_rate_minor)->toBe(1050);
});

it('offers the four Czech stage labels and filters the list by stage', function (): void {
    $lead = Client::factory()->create(['stage' => 'lead']);
    $active = Client::factory()->create(['stage' => 'active']);
    $paused = Client::factory()->create(['stage' => 'paused']);
    $ended = Client::factory()->create(['stage' => 'ended']);

    Livewire::test(CreateClient::class)
        ->assertFormFieldExists('stage', fn (Select $field): bool => array_values($field->getOptions()) === ['Zájemce', 'Aktivní', 'Pozastavený', 'Ukončený']);

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$lead, $active, $paused, $ended])
        ->filterTable('stage', 'lead')
        ->assertCanSeeTableRecords([$lead])
        ->assertCanNotSeeTableRecords([$active, $paused, $ended])
        ->filterTable('stage', 'ended')
        ->assertCanSeeTableRecords([$ended])
        ->assertCanNotSeeTableRecords([$lead, $active, $paused]);
});
