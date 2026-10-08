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
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\RawSql;

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

it('demotes the old primary and promotes the new contact when make primary is ticked', function (): void {
    $client = Client::factory()->create();
    $old = app(CreateContact::class)->handle($client, contactForm(['name' => 'Example old primary']));

    contactsTab($client)
        ->callAction(TestAction::make('create')->table(), contactForm(['name' => 'Example new primary', 'make_primary' => true]))
        ->assertHasNoFormErrors();

    $new = Contact::query()->where('name', 'Example new primary')->firstOrFail();

    expect($old->refresh()->is_primary)->toBeFalse()
        ->and($new->is_primary)->toBeTrue()
        ->and(Contact::query()->where('client_id', $client->id)->where('is_primary', true)->count())->toBe(1);
});

it('keeps the current primary when make primary is not ticked', function (): void {
    $client = Client::factory()->create();
    $old = app(CreateContact::class)->handle($client, contactForm());

    contactsTab($client)
        ->callAction(TestAction::make('create')->table(), contactForm(['name' => 'Example plain contact', 'make_primary' => false]))
        ->assertHasNoFormErrors();

    expect($old->refresh()->is_primary)->toBeTrue()
        ->and(Contact::query()->where('name', 'Example plain contact')->firstOrFail()->is_primary)->toBeFalse();
});

it('takes the primary flag over through the Action and leaves the contacts of other clients alone', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $otherPrimary = app(CreateContact::class)->handle($other, contactForm());
    $old = app(CreateContact::class)->handle($client, contactForm());
    $new = app(CreateContact::class)->handle($client, contactForm(), makePrimary: true);

    expect($old->refresh()->is_primary)->toBeFalse()
        ->and($new->is_primary)->toBeTrue()
        ->and($otherPrimary->refresh()->is_primary)->toBeTrue()
        ->and(Contact::query()->where('is_primary', true)->count())->toBe(2);
});

it('makes the first contact primary whether or not make primary is asked for', function (): void {
    $withFlag = app(CreateContact::class)->handle(Client::factory()->create(), contactForm(), makePrimary: true);
    $without = app(CreateContact::class)->handle(Client::factory()->create(), contactForm());

    expect($withFlag->is_primary)->toBeTrue()
        ->and($without->is_primary)->toBeTrue();
});

it('refuses a second primary row of the same client in the database with 23505', function (): void {
    $client = Client::factory()->create();
    app(CreateContact::class)->handle($client, contactForm());

    RawSql::expectSqlState('23505', static fn () => DB::table('contacts')->insert([
        'client_id' => $client->id,
        'name' => 'Example second primary',
        'is_primary' => true,
    ]));

    // The index is per client: another client may have its own primary.
    RawSql::expectAllowed(static fn () => DB::table('contacts')->insert([
        'client_id' => Client::factory()->create()->id,
        'name' => 'Example other primary',
        'is_primary' => true,
    ]));

    // Several non-primary rows of one client are fine.
    RawSql::expectAllowed(static fn () => DB::table('contacts')->insert([
        ['client_id' => $client->id, 'name' => 'Example plain one', 'is_primary' => false],
        ['client_id' => $client->id, 'name' => 'Example plain two', 'is_primary' => false],
    ]));
});

it('lets several contacts of one client be billing contacts', function (): void {
    $client = Client::factory()->create();

    foreach (['Example billing one', 'Example billing two', 'Example billing three'] as $name) {
        contactsTab($client)
            ->callAction(TestAction::make('create')->table(), contactForm(['name' => $name, 'is_billing' => true]))
            ->assertHasNoFormErrors();
    }

    expect(Contact::query()->where('client_id', $client->id)->where('is_billing', true)->count())->toBe(3)
        ->and(Contact::query()->where('client_id', $client->id)->where('is_primary', true)->count())->toBe(1);
});

it('does not let a crafted payload set the primary flag or the client', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    app(CreateContact::class)->handle($client, contactForm());

    // A form never carries the flag, so a crafted modal payload drops it.
    contactsTab($client)
        ->callAction(TestAction::make('create')->table(), contactForm(['name' => 'Example crafted', 'is_primary' => true, 'client_id' => $other->id]))
        ->assertHasNoFormErrors();

    $crafted = Contact::query()->where('name', 'Example crafted')->firstOrFail();

    expect($crafted->is_primary)->toBeFalse()
        ->and($crafted->client_id)->toBe($client->id);

    // The Action ignores the flag in its input as well.
    $viaAction = app(CreateContact::class)->handle($client, [...contactForm(['name' => 'Example crafted two']), 'is_primary' => true]);

    expect($viaAction->is_primary)->toBeFalse()
        ->and(Contact::query()->where('client_id', $client->id)->where('is_primary', true)->count())->toBe(1);

    // And the model refuses mass assignment of both columns.
    expect(static fn () => $client->contacts()->create(['name' => 'Example mass', 'is_primary' => true]))->toThrow(MassAssignmentException::class)
        ->and(static fn () => Contact::query()->create(['name' => 'Example mass', 'client_id' => $other->id]))->toThrow(MassAssignmentException::class);
});
