<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Actions\UpdateContact;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * The birth date of a contact (CL-02): day and month with an optional year, entered in the "Kontakty" tab of the client
 * detail, refused by the Action and by the contacts_birth* CHECKs when impossible. Time is frozen at 14 October 2026;
 * every name and address is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
});

function birthdayTab(Client $client): Testable
{
    return Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * The form state of the create modal with a birthday.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function birthdayForm(array $overrides = []): array
{
    return [
        'name' => 'Example birthday contact '.Str::lower(Str::random(6)),
        'email' => exampleEmail(),
        'phone' => null,
        'position' => null,
        'is_billing' => false,
        'birth_day' => 14,
        'birth_month' => 3,
        'birth_year' => 1985,
        ...$overrides,
    ];
}

/**
 * A raw contacts row with the given birthday columns, for the CHECK tests.
 *
 * @param  array<string, int|null>  $birthday
 * @return array<string, mixed>
 */
function birthdayRow(Client $client, array $birthday): array
{
    return [
        'client_id' => $client->id,
        'name' => 'Example raw birthday contact',
        'is_primary' => false,
        ...['birth_day' => null, 'birth_month' => null, 'birth_year' => null],
        ...$birthday,
    ];
}

it('stores the birth date with a year from the Kontakty tab and shows it as 14. 3. 1985', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['name' => 'Example birthday contact']))
        ->assertHasNoFormErrors();

    $contact = Contact::query()->where('name', 'Example birthday contact')->firstOrFail();

    expect($contact->birth_day)->toBe(14)
        ->and($contact->birth_month)->toBe(3)
        ->and($contact->birth_year)->toBe(1985)
        ->and($contact->birthdayLabel())->toBe('14. 3. 1985');

    birthdayTab($client)->assertSee('14. 3. 1985');
});

it('stores a birthday without a year and shows it as 14. 3.', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['name' => 'Example yearless contact', 'birth_year' => null]))
        ->assertHasNoFormErrors();

    $contact = Contact::query()->where('name', 'Example yearless contact')->firstOrFail();

    expect($contact->birth_day)->toBe(14)
        ->and($contact->birth_month)->toBe(3)
        ->and($contact->birth_year)->toBeNull()
        ->and($contact->birthdayLabel())->toBe('14. 3.');

    birthdayTab($client)->assertSee('14. 3.');
});

it('edits the birthday and clears it again to nulls', function (): void {
    $client = Client::factory()->create();
    $contact = app(CreateContact::class)->handle($client, birthdayForm());

    birthdayTab($client)
        ->callAction(TestAction::make('edit')->table($contact), birthdayForm(['name' => $contact->name, 'birth_day' => 2, 'birth_month' => 11, 'birth_year' => null]))
        ->assertHasNoFormErrors();

    $contact->refresh();

    expect($contact->birthdayLabel())->toBe('2. 11.');

    birthdayTab($client)
        ->callAction(TestAction::make('edit')->table($contact), birthdayForm(['name' => $contact->name, 'birth_day' => null, 'birth_month' => null, 'birth_year' => null]))
        ->assertHasNoFormErrors();

    $contact->refresh();

    expect($contact->birth_day)->toBeNull()
        ->and($contact->birth_month)->toBeNull()
        ->and($contact->birth_year)->toBeNull()
        ->and($contact->hasBirthday())->toBeFalse()
        ->and($contact->birthdayLabel())->toBeNull();
});

it('refuses a day without a month as a field error and writes nothing', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['birth_month' => null, 'birth_year' => null]))
        ->assertHasFormErrors(['birth_month']);

    expect(Contact::query()->count())->toBe(0);
});

it('refuses a month without a day as a field error and writes nothing', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['birth_day' => null, 'birth_year' => null]))
        ->assertHasFormErrors(['birth_day']);

    expect(Contact::query()->count())->toBe(0);
});

it('refuses a year without a day and month as a field error and writes nothing', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['birth_day' => null, 'birth_month' => null]))
        ->assertHasFormErrors(['birth_year']);

    expect(Contact::query()->count())->toBe(0);
});

it('refuses 31 April as a field error and writes nothing', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['birth_day' => 31, 'birth_month' => 4, 'birth_year' => null]))
        ->assertHasFormErrors(['birth_day']);

    expect(Contact::query()->count())->toBe(0);
});

it('refuses 29 February of a non-leap year as a field error and writes nothing', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2023]))
        ->assertHasFormErrors(['birth_day']);

    expect(Contact::query()->count())->toBe(0);
});

it('refuses a year before 1900 as a field error and writes nothing', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['birth_year' => 1899]))
        ->assertHasFormErrors(['birth_year']);

    expect(Contact::query()->count())->toBe(0);
});

it('refuses a birth date after today in Prague as a field error and writes nothing', function (): void {
    $client = Client::factory()->create();

    birthdayTab($client)
        ->callAction(TestAction::make('create')->table(), birthdayForm(['birth_day' => 15, 'birth_month' => 10, 'birth_year' => 2026]))
        ->assertHasFormErrors(['birth_year']);

    expect(Contact::query()->count())->toBe(0);
});

it('accepts today as the birth date and refuses tomorrow through the Action', function (): void {
    $client = Client::factory()->create();

    $today = app(CreateContact::class)->handle($client, birthdayForm(['birth_day' => 14, 'birth_month' => 10, 'birth_year' => 2026]));

    expect($today->birthdayLabel())->toBe('14. 10. 2026')
        ->and(static fn () => app(CreateContact::class)->handle($client, birthdayForm(['birth_day' => 15, 'birth_month' => 10, 'birth_year' => 2026])))
        ->toThrow(ValidationException::class);
});

it('reports a crafted non-numeric birthday as a validation error', function (): void {
    $client = Client::factory()->create();

    foreach (['abc', '-3', 14.5, ['14'], '12345'] as $value) {
        expect(static fn () => app(CreateContact::class)->handle($client, birthdayForm(['birth_day' => $value, 'birth_year' => null])))
            ->toThrow(ValidationException::class);
    }

    expect(static fn () => app(CreateContact::class)->handle($client, birthdayForm(['birth_month' => 13, 'birth_year' => null])))
        ->toThrow(ValidationException::class)
        ->and(Contact::query()->count())->toBe(0);
});

it('accepts 29 February without a year and 29 February of a leap year', function (): void {
    $client = Client::factory()->create();

    $withoutYear = app(CreateContact::class)->handle($client, birthdayForm(['birth_day' => 29, 'birth_month' => 2, 'birth_year' => null]));
    $leap = app(CreateContact::class)->handle($client, birthdayForm(['birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2024]));

    expect($withoutYear->birthdayLabel())->toBe('29. 2.')
        ->and($leap->birthdayLabel())->toBe('29. 2. 2024');
});

it('takes a birthday given as digit strings, as a select and a text field deliver them', function (): void {
    $client = Client::factory()->create();

    $contact = app(CreateContact::class)->handle($client, birthdayForm(['birth_day' => '14', 'birth_month' => '3', 'birth_year' => '1985']));

    expect($contact->birth_day)->toBe(14)
        ->and($contact->birth_month)->toBe(3)
        ->and($contact->birth_year)->toBe(1985);
});

it('clears the birthday when an update carries no birthday keys', function (): void {
    $client = Client::factory()->create();
    $contact = app(CreateContact::class)->handle($client, birthdayForm());

    // As with the e-mail and the phone, a missing key means null.
    app(UpdateContact::class)->handle($contact, ['name' => $contact->name]);

    expect($contact->refresh()->hasBirthday())->toBeFalse();
});

it('refuses every impossible birthday in the database with 23514', function (): void {
    $client = Client::factory()->create();

    $rows = [
        'day without month' => ['birth_day' => 14],
        'month without day' => ['birth_month' => 3],
        'year without day and month' => ['birth_year' => 1985],
        'month 13' => ['birth_day' => 1, 'birth_month' => 13],
        'month 0' => ['birth_day' => 1, 'birth_month' => 0],
        'day 0' => ['birth_day' => 0, 'birth_month' => 1],
        'day 32' => ['birth_day' => 32, 'birth_month' => 1],
        '31 April' => ['birth_day' => 31, 'birth_month' => 4],
        '30 February' => ['birth_day' => 30, 'birth_month' => 2],
        '29 February 2023' => ['birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2023],
        '29 February 1900' => ['birth_day' => 29, 'birth_month' => 2, 'birth_year' => 1900],
        'year 1899' => ['birth_day' => 1, 'birth_month' => 1, 'birth_year' => 1899],
        'year 2101' => ['birth_day' => 1, 'birth_month' => 1, 'birth_year' => 2101],
    ];

    foreach ($rows as $birthday) {
        RawSql::expectSqlState('23514', static fn () => DB::table('contacts')->insert(birthdayRow($client, $birthday)));
    }
});

it('accepts the possible birthdays in the database', function (): void {
    $client = Client::factory()->create();

    $rows = [
        'no birthday' => [],
        'day and month' => ['birth_day' => 14, 'birth_month' => 3],
        'full date' => ['birth_day' => 14, 'birth_month' => 3, 'birth_year' => 1985],
        '29 February without a year' => ['birth_day' => 29, 'birth_month' => 2],
        '29 February 2000' => ['birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2000],
        '29 February 2024' => ['birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2024],
        '30 April' => ['birth_day' => 30, 'birth_month' => 4],
        '31 December' => ['birth_day' => 31, 'birth_month' => 12, 'birth_year' => 1900],
    ];

    foreach ($rows as $birthday) {
        RawSql::expectAllowed(static fn () => DB::table('contacts')->insert(birthdayRow($client, $birthday)));
    }
});

it('computes the age turned in the year of a day and none without a year', function (): void {
    $client = Client::factory()->create();
    $withYear = app(CreateContact::class)->handle($client, birthdayForm());
    $without = app(CreateContact::class)->handle($client, birthdayForm(['birth_year' => null]));
    $day = CarbonImmutable::parse('2026-03-14', 'Europe/Prague');

    expect($withYear->ageOn($day))->toBe(41)
        ->and($without->ageOn($day))->toBeNull();
});

it('writes a change of the birth month to the activity log', function (): void {
    $client = Client::factory()->create();
    $contact = app(CreateContact::class)->handle($client, birthdayForm());
    $contact->activitiesAsSubject()->delete();

    birthdayTab($client)
        ->callAction(TestAction::make('edit')->table($contact), birthdayForm(['name' => $contact->name, 'birth_month' => 4]))
        ->assertHasNoFormErrors();

    $rows = $contact->activitiesAsSubject()->get();
    $changes = $rows->first()?->attribute_changes;

    expect($rows)->toHaveCount(1)
        ->and(array_keys($changes?->get('attributes') ?? []))->toContain('birth_month')
        ->and($changes?->get('attributes')['birth_month'])->toBe(4);
});

it('names the birth attributes of a contact in Czech in the change history', function (): void {
    foreach (['birth_day', 'birth_month', 'birth_year'] as $attribute) {
        expect(__("kokpit.activity.attributes.contact.{$attribute}"))->not->toBe("kokpit.activity.attributes.contact.{$attribute}");
    }
});
