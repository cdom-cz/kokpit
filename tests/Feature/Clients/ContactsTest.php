<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Contacts of a client (CL-02, D-12): the Admin-only "Kontakty" tab of the
 * client detail. Livewire relation manager tests as the Admin; every name,
 * e-mail address and phone number is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

/**
 * Mounts the contacts tab of a client as the Admin sees it on the detail page.
 */
function contactsTab(Client $client): Testable
{
    return Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * A fictional phone number assembled at runtime from fragments.
 */
function contactPhone(): string
{
    return implode(' ', ['+000', '555', (string) random_int(100, 999), (string) random_int(100, 999)]);
}

/**
 * The form state of the create modal.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function contactForm(array $overrides = []): array
{
    return [
        'name' => 'Example contact '.Str::lower(Str::random(6)),
        'email' => exampleEmail(),
        'phone' => contactPhone(),
        'position' => 'Example position',
        'is_billing' => false,
        ...$overrides,
    ];
}

it('lists the contacts relation manager among the relations of the client resource', function (): void {
    expect(ClientResource::getRelations())->toContain(ContactsRelationManager::class);
});

it('makes the first contact created through the tab the primary contact and the second one not', function (): void {
    $client = Client::factory()->create();

    contactsTab($client)
        ->assertCountTableRecords(0)
        ->callAction(TestAction::make('create')->table(), contactForm(['name' => 'Example first contact']))
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('create')->table(), contactForm(['name' => 'Example second contact', 'is_billing' => true]))
        ->assertHasNoFormErrors();

    $first = Contact::query()->where('name', 'Example first contact')->firstOrFail();
    $second = Contact::query()->where('name', 'Example second contact')->firstOrFail();

    expect($first->client_id)->toBe($client->id)
        ->and($first->is_primary)->toBeTrue()
        ->and($first->is_billing)->toBeFalse()
        ->and($second->is_primary)->toBeFalse()
        ->and($second->is_billing)->toBeTrue()
        ->and(Contact::query()->where('client_id', $client->id)->where('is_primary', true)->count())->toBe(1)
        ->and($client->primaryContact()->firstOrFail()->is($first))->toBeTrue();
});

it('shows the contacts of the client in the tab and none of another client', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $own = app(CreateContact::class)->handle($client, contactForm());
    $foreign = app(CreateContact::class)->handle($other, contactForm());

    contactsTab($client)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('reports the problems of a contact as field errors', function (): void {
    $client = Client::factory()->create();

    contactsTab($client)
        ->callAction(TestAction::make('create')->table(), contactForm(['name' => '', 'email' => 'not-an-address']))
        ->assertHasFormErrors(['name', 'email']);

    expect(Contact::query()->count())->toBe(0);
});

it('refuses a crafted Action input with a validation error and writes nothing', function (): void {
    $client = Client::factory()->create();

    expect(static fn () => app(CreateContact::class)->handle($client, ['name' => '   ', 'email' => 'nope']))
        ->toThrow(ValidationException::class)
        ->and(Contact::query()->count())->toBe(0);
});

it('gives a Partner zero contacts through the model and refuses the relation manager at boot', function (): void {
    $client = Client::factory()->create();
    app(CreateContact::class)->handle($client, contactForm());
    $partner = Canary::partnerFor($client->id);

    $this->actingAs($partner);

    expect(Contact::query()->count())->toBe(0)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Contact::query()->count()))->toBe(1)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): bool => ContactsRelationManager::canViewForRecord($client, ViewClient::class)))->toBeFalse();

    Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertForbidden();
});
