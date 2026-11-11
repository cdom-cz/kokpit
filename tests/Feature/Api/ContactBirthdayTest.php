<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use App\Domain\Identity\ApiAbility;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;

/*
 * GET /api/v1/contacts/birthdays: the contacts with a birthday on a day, under the contacts:read ability. Time is frozen
 * at Wednesday 14 October 2026, 10:00 in Prague. Every name and address is fictional.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
});

/**
 * A bearer token of a fresh Admin with the given abilities (the contact read ability by default).
 *
 * @param  list<string>  $abilities
 */
function birthdayApiToken(array $abilities = ['contacts:read']): string
{
    return ApiFixtures::token(ApiFixtures::admin(), $abilities);
}

/**
 * A contact of the client with the given birthday, created as a system run because no one is signed in.
 */
function birthdayApiContact(Client $client, string $name, int $day, int $month, ?int $year = null): Contact
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Contact => app(CreateContact::class)->handle($client, [
        'name' => $name,
        'email' => exampleEmail(),
        'position' => 'Example position',
        'birth_day' => $day,
        'birth_month' => $month,
        'birth_year' => $year,
    ]));
}

const BIRTHDAY_KEYS = ['id', 'client_id', 'client_name', 'name', 'email', 'phone', 'position', 'is_primary', 'is_billing', 'birth_day', 'birth_month', 'birth_year', 'age', 'created_at', 'updated_at'];

it('answers the contacts of today of non-archived clients, by name, with the whole contact and the age', function (): void {
    $clientA = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create(['name' => 'Example Client Alpha']));
    $clientB = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create(['name' => 'Example Client Beta']));
    $archived = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create());

    $beta = birthdayApiContact($clientB, 'Example Beta birthday', 14, 10);
    $alpha = birthdayApiContact($clientA, 'Example Alpha birthday', 14, 10, 1985);
    birthdayApiContact($clientA, 'Example Gamma tomorrow', 15, 10, 1990);
    birthdayApiContact($archived, 'Example Delta archived', 14, 10, 1980);
    app(PartnerContext::class)->runAsSystem(static fn () => $archived->delete());

    $response = $this->withToken(birthdayApiToken())->getJson('/api/v1/contacts/birthdays')->assertOk();

    $data = $response->json('data');

    expect(array_column($data, 'name'))->toBe(['Example Alpha birthday', 'Example Beta birthday'])
        ->and(array_keys($data[0]))->toBe(BIRTHDAY_KEYS)
        ->and(array_keys($data[1]))->toBe(BIRTHDAY_KEYS)
        ->and($data[0]['id'])->toBe($alpha->id)
        ->and($data[0]['client_id'])->toBe($clientA->id)
        ->and($data[0]['client_name'])->toBe('Example Client Alpha')
        ->and($data[0]['email'])->toBe($alpha->email)
        ->and($data[0]['position'])->toBe('Example position')
        ->and($data[0]['is_primary'])->toBeTrue()
        ->and($data[0]['is_billing'])->toBeFalse()
        ->and([$data[0]['birth_day'], $data[0]['birth_month'], $data[0]['birth_year']])->toBe([14, 10, 1985])
        ->and($data[0]['age'])->toBe(41)
        ->and($data[1]['id'])->toBe($beta->id)
        ->and($data[1]['client_name'])->toBe('Example Client Beta')
        ->and($data[1]['birth_year'])->toBeNull()
        ->and($data[1]['age'])->toBeNull()
        ->and($data[0]['phone'])->toBeNull()
        ->and($response->json('meta.date'))->toBe('2026-10-14');
});

it('answers another day when date is given, and the 29 February contact on 28 February of a non-leap year', function (): void {
    $client = ApiFixtures::client();
    $tomorrow = birthdayApiContact($client, 'Example born on the 15th', 15, 10, 1990);
    $leapling = birthdayApiContact($client, 'Example leap day contact', 29, 2, 2000);
    $token = birthdayApiToken();

    $next = $this->withToken($token)->getJson('/api/v1/contacts/birthdays?date=2026-10-15')->assertOk();

    Auth::forgetGuards();

    $leap = $this->withToken($token)->getJson('/api/v1/contacts/birthdays?date=2027-02-28')->assertOk();

    Auth::forgetGuards();

    $leapYear = $this->withToken($token)->getJson('/api/v1/contacts/birthdays?date=2028-02-28')->assertOk();

    expect(array_column($next->json('data'), 'id'))->toBe([$tomorrow->id])
        ->and($next->json('data.0.age'))->toBe(36)
        ->and($next->json('meta.date'))->toBe('2026-10-15')
        ->and(array_column($leap->json('data'), 'id'))->toBe([$leapling->id])
        ->and($leap->json('data.0.age'))->toBe(27)
        ->and($leap->json('meta.date'))->toBe('2027-02-28')
        ->and($leapYear->json('data'))->toBe([]);
});

it('answers 200 with an empty list and the date on a day without birthdays', function (): void {
    birthdayApiContact(ApiFixtures::client(), 'Example born on another day', 3, 3, 1985);

    $this->withToken(birthdayApiToken())->getJson('/api/v1/contacts/birthdays')
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['date' => '2026-10-14']]);
});

it('refuses a malformed date with 422 under date', function (string $query): void {
    $this->withToken(birthdayApiToken())->getJson('/api/v1/contacts/birthdays?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors('date');
})->with([
    'czech form' => ['date=14.10.2026'],
    'overflowed day' => ['date=2026-02-30'],
    'array' => ['date[]=x'],
    'before the range' => ['date=1899-12-31'],
    'after the range' => ['date=2101-01-01'],
]);

it('refuses a token without contacts:read with 403, and a time or finance token never reaches the contacts', function (): void {
    birthdayApiContact(ApiFixtures::client(), 'Example secret contact', 14, 10, 1985);

    $others = array_values(array_diff(ApiAbility::values(), ['contacts:read']));
    $response = $this->withToken(birthdayApiToken($others))->getJson('/api/v1/contacts/birthdays')->assertForbidden();

    expect((string) $response->getContent())->not->toContain('Example secret contact');
});

it('lets a contacts:read token read nothing of time or finance', function (): void {
    $token = birthdayApiToken();

    $this->withToken($token)->getJson('/api/v1/time-entries')->assertForbidden();

    Auth::forgetGuards();

    $this->withToken($token)->getJson('/api/v1/transactions')->assertForbidden();
});

it('refuses a Partner token with 401 and a guest with 401', function (): void {
    $client = ApiFixtures::client();
    birthdayApiContact($client, 'Example private contact', 14, 10, 1985);
    $partner = Canary::partnerFor($client->id);

    $response = $this->withToken(ApiFixtures::token($partner, ApiAbility::values()))->getJson('/api/v1/contacts/birthdays')->assertUnauthorized();

    expect((string) $response->getContent())->not->toContain('Example private contact');

    Auth::forgetGuards();

    $this->getJson('/api/v1/contacts/birthdays')->assertUnauthorized();
});
