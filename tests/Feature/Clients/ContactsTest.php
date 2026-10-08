<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Actions\DeleteContact;
use App\Domain\Clients\Actions\SetPrimaryContact;
use App\Domain\Clients\Actions\UpdateContact;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Filament\Support\ActivityPresenter;
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

/**
 * Three contacts of one client; the first one created is the primary.
 *
 * @return array{0: Client, 1: Contact, 2: Contact, 3: Contact}
 */
function clientWithThreeContacts(): array
{
    $client = Client::factory()->create();

    return [
        $client,
        app(CreateContact::class)->handle($client, contactForm(['name' => 'Example primary'])),
        app(CreateContact::class)->handle($client, contactForm(['name' => 'Example second'])),
        app(CreateContact::class)->handle($client, contactForm(['name' => 'Example third'])),
    ];
}

it('moves the primary flag to another contact and leaves exactly one primary', function (): void {
    [$client, $primary, $second, $third] = clientWithThreeContacts();

    app(SetPrimaryContact::class)->handle($second);

    expect($primary->refresh()->is_primary)->toBeFalse()
        ->and($second->refresh()->is_primary)->toBeTrue()
        ->and($third->refresh()->is_primary)->toBeFalse()
        ->and(Contact::query()->where('client_id', $client->id)->where('is_primary', true)->count())->toBe(1);
});

it('changes nothing when the contact is already the primary', function (): void {
    [$client, $primary] = clientWithThreeContacts();
    $before = $primary->refresh()->updated_at;

    app(SetPrimaryContact::class)->handle($primary);

    expect($primary->refresh()->is_primary)->toBeTrue()
        ->and($primary->updated_at?->equalTo($before))->toBeTrue()
        ->and(Contact::query()->where('client_id', $client->id)->where('is_primary', true)->count())->toBe(1);
});

it('does not touch the primary of another client when it moves the flag', function (): void {
    [, , $second] = clientWithThreeContacts();
    $other = app(CreateContact::class)->handle(Client::factory()->create(), contactForm());

    app(SetPrimaryContact::class)->handle($second);

    expect($other->refresh()->is_primary)->toBeTrue()
        ->and(Contact::query()->where('is_primary', true)->count())->toBe(2);
});

it('locks the client row while it moves the primary flag', function (): void {
    [, , $second] = clientWithThreeContacts();
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = mb_strtolower($query->sql);
    });

    app(SetPrimaryContact::class)->handle($second);

    $locks = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'from "clients"') && str_contains($sql, 'for update'));

    expect($locks)->not->toBeEmpty();
});

it('moves the primary flag from the Kontakty tab and hides the action on the primary row', function (): void {
    [$client, $primary, $second] = clientWithThreeContacts();

    contactsTab($client)
        ->assertActionHidden(TestAction::make('setPrimary')->table($primary))
        ->assertActionVisible(TestAction::make('setPrimary')->table($second))
        ->callAction(TestAction::make('setPrimary')->table($second));

    expect($primary->refresh()->is_primary)->toBeFalse()
        ->and($second->refresh()->is_primary)->toBeTrue();
});

it('refuses to delete the primary while other contacts exist and deletes nothing', function (): void {
    [$client, $primary] = clientWithThreeContacts();

    expect(static fn () => app(DeleteContact::class)->handle($primary))
        ->toThrow(ValidationException::class, __('kokpit.contacts.errors.primary_delete'))
        ->and(Contact::query()->where('client_id', $client->id)->count())->toBe(3);

    contactsTab($client)
        ->callAction(TestAction::make('delete')->table($primary))
        ->assertNotified(__('kokpit.contacts.errors.primary_delete'));

    expect(Contact::query()->where('client_id', $client->id)->count())->toBe(3)
        ->and($primary->refresh()->is_primary)->toBeTrue();
});

it('deletes a contact that is not the primary and keeps the primary', function (): void {
    [$client, $primary, $second] = clientWithThreeContacts();

    contactsTab($client)->callAction(TestAction::make('delete')->table($second));

    expect(Contact::query()->whereKey($second->id)->exists())->toBeFalse()
        ->and($primary->refresh()->is_primary)->toBeTrue()
        ->and(Contact::query()->where('client_id', $client->id)->count())->toBe(2);
});

it('lets the primary go once it is the only contact, and the next contact becomes the primary', function (): void {
    $client = Client::factory()->create();
    $only = app(CreateContact::class)->handle($client, contactForm());

    contactsTab($client)->callAction(TestAction::make('delete')->table($only));

    expect(Contact::query()->where('client_id', $client->id)->count())->toBe(0);

    $next = app(CreateContact::class)->handle($client, contactForm());

    expect($next->is_primary)->toBeTrue();
});

it('lets the primary be deleted after it handed the flag to another contact', function (): void {
    [$client, $primary, $second, $third] = clientWithThreeContacts();

    app(SetPrimaryContact::class)->handle($second);
    app(DeleteContact::class)->handle($primary);

    expect(Contact::query()->whereKey($primary->id)->exists())->toBeFalse()
        ->and($second->refresh()->is_primary)->toBeTrue()
        ->and($third->refresh()->is_primary)->toBeFalse();
});

it('edits name, e-mail, phone, position and billing flag and never the primary flag', function (): void {
    [$client, $primary, $second] = clientWithThreeContacts();

    $changes = contactForm(['name' => 'Example renamed', 'position' => 'Example new position', 'is_billing' => true]);

    contactsTab($client)
        ->callAction(TestAction::make('edit')->table($second), $changes)
        ->assertHasNoFormErrors();

    $second->refresh();

    expect($second->name)->toBe('Example renamed')
        ->and($second->email)->toBe($changes['email'])
        ->and($second->phone)->toBe($changes['phone'])
        ->and($second->position)->toBe('Example new position')
        ->and($second->is_billing)->toBeTrue()
        ->and($second->is_primary)->toBeFalse()
        ->and($primary->refresh()->is_primary)->toBeTrue();
});

it('has no primary field in the edit modal', function (): void {
    [$client, , $second] = clientWithThreeContacts();

    contactsTab($client)
        ->mountAction(TestAction::make('edit')->table($second))
        ->assertSchemaComponentExists('name', 'mountedActionSchema0')
        ->assertSchemaComponentDoesNotExist('make_primary', 'mountedActionSchema0')
        ->assertSchemaComponentDoesNotExist('is_primary', 'mountedActionSchema0');
});

it('ignores a primary flag in the payload of UpdateContact and of the edit modal', function (): void {
    [$client, $primary, $second] = clientWithThreeContacts();

    $updated = app(UpdateContact::class)->handle($second, [...contactForm(['name' => 'Example crafted edit']), 'is_primary' => true, 'client_id' => Client::factory()->create()->id]);

    expect($updated->is_primary)->toBeFalse()
        ->and($updated->client_id)->toBe($client->id)
        ->and($primary->refresh()->is_primary)->toBeTrue();

    contactsTab($client)
        ->callAction(TestAction::make('edit')->table($second), contactForm(['name' => 'Example crafted modal', 'is_primary' => true, 'make_primary' => true]))
        ->assertHasNoFormErrors();

    expect($second->refresh()->name)->toBe('Example crafted modal')
        ->and($second->is_primary)->toBeFalse()
        ->and(Contact::query()->where('client_id', $client->id)->where('is_primary', true)->count())->toBe(1);
});

it('reports the problems of an edited contact as field errors and changes nothing', function (): void {
    [$client, , $second] = clientWithThreeContacts();

    contactsTab($client)
        ->callAction(TestAction::make('edit')->table($second), contactForm(['name' => '', 'email' => 'not-an-address']))
        ->assertHasFormErrors(['name', 'email']);

    expect($second->refresh()->name)->toBe('Example second');

    expect(static fn () => app(UpdateContact::class)->handle($second, ['name' => '  ']))->toThrow(ValidationException::class);
});

it('keeps the invoice e-mail of the client apart from the contact flags', function (): void {
    [$client, $primary, $second] = clientWithThreeContacts();
    $invoiceEmail = exampleEmail();
    $client->forceFill(['invoice_email' => $invoiceEmail])->save();

    app(SetPrimaryContact::class)->handle($second);
    app(UpdateContact::class)->handle($primary, contactForm(['is_billing' => true]));
    app(UpdateContact::class)->handle($second, contactForm(['is_billing' => false]));
    app(DeleteContact::class)->handle($primary);

    expect($client->refresh()->invoice_email)->toBe($invoiceEmail);

    $before = Contact::query()->where('client_id', $client->id)->orderBy('name')->get()->map(static fn (Contact $contact): array => $contact->only(['name', 'email', 'is_primary', 'is_billing']))->all();

    $client->forceFill(['invoice_email' => exampleEmail()])->save();

    $after = Contact::query()->where('client_id', $client->id)->orderBy('name')->get()->map(static fn (Contact $contact): array => $contact->only(['name', 'email', 'is_primary', 'is_billing']))->all();

    expect($after)->toBe($before);
});

it('shows the name of the primary contact in the client list', function (): void {
    $client = Client::factory()->create();
    app(CreateContact::class)->handle($client, contactForm(['name' => 'Example list primary']));
    app(CreateContact::class)->handle($client, contactForm(['name' => 'Example list second']));
    $withoutContacts = Client::factory()->create();

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$client, $withoutContacts])
        ->assertTableColumnStateSet('primary_contact_name', 'Example list primary', $client)
        ->assertTableColumnStateSet('primary_contact_name', null, $withoutContacts);
});

it('names the primary contact of a page of 10 clients without a contact query per client', function (): void {
    $clients = [];

    foreach (range(1, 10) as $index) {
        $clients[$index] = Client::factory()->create();
        app(CreateContact::class)->handle($clients[$index], contactForm(['name' => "Example listed contact {$index}"]));
    }

    $component = Livewire::test(ListClients::class);
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = mb_strtolower($query->sql);
    });

    $component->call('$refresh')
        ->assertTableColumnStateSet('primary_contact_name', 'Example listed contact 7', $clients[7]);

    // The contact lookup is a subquery of the client query; a lazy relation load would start from contacts.
    $contactQueries = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'from "contacts"') && ! str_contains($sql, 'from "clients"'));

    expect($statements)->not->toBeEmpty()
        ->and($contactQueries)->toBe([]);
});

it('logs a change of the e-mail of a contact under the log name contact listing email', function (): void {
    [$client, , $second] = clientWithThreeContacts();
    $second->activitiesAsSubject()->delete();
    $newEmail = exampleEmail('changed');

    contactsTab($client)
        ->callAction(TestAction::make('edit')->table($second), contactForm(['name' => $second->name, 'email' => $newEmail]))
        ->assertHasNoFormErrors();

    $rows = $second->activitiesAsSubject()->get();
    $changes = $rows->first()?->attribute_changes;

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->log_name)->toBe('contact')
        ->and(array_keys($changes?->get('attributes') ?? []))->toContain('email')
        ->and($changes?->get('attributes')['email'])->toBe($newEmail);
});

it('logs both sides of a primary switch', function (): void {
    [, $primary, $second] = clientWithThreeContacts();
    $primary->activitiesAsSubject()->delete();
    $second->activitiesAsSubject()->delete();

    app(SetPrimaryContact::class)->handle($second);

    expect($primary->activitiesAsSubject()->count())->toBe(1)
        ->and($second->activitiesAsSubject()->count())->toBe(1)
        ->and($second->activitiesAsSubject()->firstOrFail()->attribute_changes->get('attributes'))->toBe(['is_primary' => true]);
});

it('names the contact subject and its attributes in Czech in the change history', function (): void {
    expect(ActivityPresenter::subjectLabel('contact'))->toBe('Kontakt');

    foreach (['client_id', 'name', 'email', 'phone', 'position', 'is_primary', 'is_billing'] as $attribute) {
        expect(__("kokpit.activity.attributes.contact.{$attribute}"))->not->toBe("kokpit.activity.attributes.contact.{$attribute}");
    }
});
