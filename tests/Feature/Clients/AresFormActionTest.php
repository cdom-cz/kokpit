<?php

declare(strict_types=1);

use App\Filament\Resources\ClientResource\Pages\CreateClient;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\FictionalCompanyId;

/*
 * The ARES button on the client form (CL-04, D-08, D-09). Livewire page tests as the
 * Admin. Every response is an invented payload with Example names; the company
 * numbers come from FictionalCompanyId; Http::preventStrayRequests() (TestCase)
 * turns any missing fake into a failure instead of a call to the live registry.
 */

/**
 * An invented ARES payload for the number; overrides replace top-level keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aresPayload(string $number, array $overrides = []): array
{
    return [
        'ico' => $number,
        'obchodniJmeno' => 'Example Registry Ltd.',
        'dic' => 'CZ'.$number,
        'sidlo' => [
            'kodStatu' => 'CZ',
            'nazevObce' => 'Example Town',
            'nazevCastiObce' => 'Example Quarter',
            'nazevUlice' => 'Example Street',
            'cisloDomovni' => 12,
            'cisloOrientacni' => 3,
            'psc' => 1100,
            'textovaAdresa' => 'Example Street 12/3, Example Quarter, 01100 Example Town',
        ],
        ...$overrides,
    ];
}

/**
 * The terms and contact values the lookup must never touch.
 *
 * @return array<string, mixed>
 */
function untouchedClientFields(): array
{
    return [
        'currency' => 'EUR',
        'hourly_rate' => '85,50',
        'payment_terms_days' => 30,
        'invoice_language' => 'en',
        'invoice_email' => 'billing@example.com',
        'online_payment_enabled' => true,
        'stage' => 'lead',
    ];
}

/**
 * Asserts the form state still holds the untouched terms; selects keep enum cases in state.
 *
 * @param  array<string, mixed>  $state
 */
function expectUntouchedTerms(array $state): void
{
    foreach (untouchedClientFields() as $field => $expected) {
        $actual = $state[$field];
        $actual = $actual instanceof BackedEnum ? $actual->value : $actual;

        expect($actual)->toEqual($expected);
    }
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('fills only the registry fields, marks what changed and leaves the terms alone', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response(aresPayload($number))]);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'country' => 'CZ',
            'company_number' => $number,
            'name' => 'Typed name',
            'street' => 'Typed street',
            ...untouchedClientFields(),
        ])
        ->callAction(TestAction::make('ares')->schemaComponent('company_number'))
        ->assertHasNoFormErrors()
        ->assertSchemaStateSet([
            'name' => 'Example Registry Ltd.',
            'company_number' => $number,
            'tax_number' => 'CZ'.$number,
            'street' => 'Example Street 12/3',
            'city' => 'Example Town',
            'postal_code' => '01100',
            'country' => 'CZ',
        ])
        ->assertSchemaStateSet(function (array $state): void {
            expectUntouchedTerms($state);
            expect($state['ares_changed'])->toEqualCanonicalizing(['name', 'tax_number', 'street', 'city', 'postal_code']);
        });

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), rtrim((string) config('services.ares.base_url'), '/').'/')
        && basename($request->url()) === $number);
});

it('keeps a typed tax number when ARES returns none', function (): void {
    $number = FictionalCompanyId::valid();
    $payload = aresPayload($number);
    unset($payload['dic']);
    Http::fake(['*' => Http::response($payload)]);

    Livewire::test(CreateClient::class)
        ->fillForm(['country' => 'CZ', 'company_number' => $number, 'tax_number' => 'CZ00000000'])
        ->callAction(TestAction::make('ares')->schemaComponent('company_number'))
        ->assertHasNoFormErrors()
        ->assertSchemaStateSet(['tax_number' => 'CZ00000000', 'name' => 'Example Registry Ltd.'])
        ->assertSchemaStateSet(function (array $state): void {
            expect($state['ares_changed'])->not->toContain('tax_number');
        });
});

it('shows a Czech field error and changes nothing when the number fails the checksum', function (): void {
    Http::fake();

    Livewire::test(CreateClient::class)
        ->fillForm(['country' => 'CZ', 'company_number' => '12345678', 'name' => 'Typed name', ...untouchedClientFields()])
        ->callAction(TestAction::make('ares')->schemaComponent('company_number'))
        ->assertHasFormErrors(['company_number'])
        ->assertSchemaStateSet(['name' => 'Typed name', 'company_number' => '12345678'])
        ->assertSchemaStateSet(function (array $state): void {
            expectUntouchedTerms($state);
            expect($state['ares_changed'])->toBe([]);
        });

    Http::assertNothingSent();
});
