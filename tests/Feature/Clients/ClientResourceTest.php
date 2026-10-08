<?php

declare(strict_types=1);

use App\Domain\Clients\Enums\ClientStage;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
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
