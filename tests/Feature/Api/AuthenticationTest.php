<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;

/*
 * Token authentication of the REST API (AP-01, AP-02 read part). Real Sanctum
 * tokens through the guard path; every value is fictional.
 */

it('lets an Admin token with time:read read one time entry', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $entry = ApiFixtures::entry($admin, [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);
    $token = ApiFixtures::token($admin, ['time:read']);

    $response = $this->withToken($token)->getJson('/api/v1/time-entries/'.$entry->id);

    $response->assertOk()
        ->assertJsonPath('data.id', $entry->id)
        ->assertJsonPath('data.client_id', $client->id)
        ->assertJsonPath('data.duration_seconds', 5400)
        ->assertJsonPath('data.started_at', '2026-10-12T08:00:00+00:00')
        ->assertJsonPath('data.ended_at', '2026-10-12T09:30:00+00:00')
        ->assertJsonPath('data.billing_state', 'unbilled')
        ->assertJsonPath('data.billable', true);

    expect(array_keys($response->json('data')))->toBe([
        'id', 'client_id', 'project_id', 'project_key', 'task_id', 'task_reference', 'description',
        'started_at', 'ended_at', 'duration_seconds', 'billable', 'billing_state', 'billed_at',
        'created_at', 'updated_at',
    ]);
});

it('refuses a request without a token with 401 JSON', function (): void {
    $entry = ApiFixtures::entry(ApiFixtures::admin(), [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    $this->getJson('/api/v1/time-entries/'.$entry->id)
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});

/*
 * Refusal paths: every one is a clean 401, 403 or 404, never a 500.
 */

/**
 * The time entry the refusal tests ask for, as a path.
 */
function apiEntryPath(?User $admin = null): string
{
    $entry = ApiFixtures::entry($admin ?? ApiFixtures::admin(), [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    return '/api/v1/time-entries/'.$entry->id;
}

it('answers 401 JSON to a bearer that is malformed or was never issued', function (string $shape): void {
    $path = apiEntryPath();
    $bearer = match ($shape) {
        'non-uuid id part' => 'abc|def',
        'empty id part' => '|x',
        'no pipe' => 'abc',
        'empty bearer' => '',
        'valid uuid, wrong secret' => (string) Str::uuid7().'|'.Str::random(40),
        'well-formed, never issued' => (string) Str::uuid7().'|'.hash('sha256', 'never issued'),
    };

    $this->withToken($bearer)->getJson($path)->assertUnauthorized()->assertJsonStructure(['message']);
})->with([
    'non-uuid id part',
    'empty id part',
    'no pipe',
    'empty bearer',
    'valid uuid, wrong secret',
    'well-formed, never issued',
]);

it('answers 401 to a revoked token on the next request', function (): void {
    $admin = ApiFixtures::admin();
    $path = apiEntryPath($admin);
    $token = ApiFixtures::token($admin, ['time:read']);

    $this->withToken($token)->getJson($path)->assertOk();

    $admin->tokens()->delete();
    Auth::forgetGuards();

    $this->withToken($token)->getJson($path)->assertUnauthorized();
});

it('honours the expiry of a token to the second', function (): void {
    $this->freezeTime();
    $admin = ApiFixtures::admin();
    $path = apiEntryPath($admin);

    $expired = ApiFixtures::token($admin, ['time:read'], Carbon::now()->subSecond());
    $valid = ApiFixtures::token($admin, ['time:read'], Carbon::now()->addSecond());

    $this->withToken($expired)->getJson($path)->assertUnauthorized();
    $this->withToken($valid)->getJson($path)->assertOk();
});

it('answers 401 to the token of a user who is not an active Admin', function (string $holder): void {
    $path = apiEntryPath();
    $user = match ($holder) {
        'Partner' => Canary::partnerFor(Canary::twoClients()[0]),
        'user without a role' => Canary::userWithoutRole(null),
        'deactivated Admin' => tap(Canary::admin(), static fn (User $admin) => $admin->forceFill(['deactivated_at' => now()])->save()),
    };

    $this->withToken(ApiFixtures::token($user, ['time:read', 'time:write']))->getJson($path)->assertUnauthorized();
})->with(['Partner', 'user without a role', 'deactivated Admin']);

it('does not accept the panel session of an Admin as an API credential', function (): void {
    $admin = ApiFixtures::admin();
    $path = apiEntryPath($admin);

    $this->actingAs($admin)->getJson($path)->assertUnauthorized();
});

it('refuses a token without the needed ability with 403 and opens it with the ability', function (): void {
    $admin = ApiFixtures::admin();
    $path = apiEntryPath($admin);

    $this->withToken(ApiFixtures::token($admin, ['time:write']))->getJson($path)->assertForbidden();

    // The guard keeps the user of the first call for the rest of the test; a real client is a new request.
    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($admin, ['time:read', 'time:write']))->getJson($path)->assertOk();
});

it('answers 404 to an id that is not a uuid and to an unknown uuid, naming no class', function (): void {
    // The debug page of a failed request lists class names in its trace; production never shows it.
    config(['app.debug' => false]);
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->getJson('/api/v1/time-entries/not-a-uuid')->assertNotFound();

    $response = $this->withToken($token)->getJson('/api/v1/time-entries/'.Str::uuid7())->assertNotFound();

    expect($response->json('message'))->toBe('Záznam neexistuje.')
        ->and($response->getContent())->not->toContain('App\\');
});

it('records the use of a token in last_used_at', function (): void {
    $admin = ApiFixtures::admin();
    $path = apiEntryPath($admin);
    $token = ApiFixtures::token($admin, ['time:read']);

    expect($admin->tokens()->firstOrFail()->last_used_at)->toBeNull();

    $this->withToken($token)->getJson($path)->assertOk();

    expect($admin->tokens()->firstOrFail()->last_used_at)->not->toBeNull();
});
