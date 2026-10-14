<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\IssueApiToken;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\ApiTokenExpiry;
use App\Domain\Identity\ApiTokenStatus;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;

/*
 * The domain rules of issuing an API token (AP-01, D-01, D-02, U-12): who may issue,
 * what a valid token request is, when a limited token ends, and what the audit row holds.
 * Every value is fictional.
 */

it('refuses a Partner and leaves no token behind', function (): void {
    $partner = Canary::partnerFor(ApiFixtures::client()->id);

    expect(fn () => app(IssueApiToken::class)->handle($partner, 'Example tool', [ApiAbility::TimeRead], ApiTokenExpiry::None))
        ->toThrow(AuthorizationException::class)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('refuses a user without a role and a deactivated Admin', function (): void {
    $roleless = Canary::userWithoutRole(null);
    $deactivated = ApiFixtures::admin();
    $deactivated->forceFill(['deactivated_at' => now()])->save();

    foreach ([$roleless, $deactivated] as $actor) {
        expect(fn () => app(IssueApiToken::class)->handle($actor, 'Example tool', [ApiAbility::TimeRead], ApiTokenExpiry::None))
            ->toThrow(AuthorizationException::class);
    }

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('reports an empty or too long name as the field error name', function (string $name): void {
    $admin = ApiFixtures::admin();

    try {
        app(IssueApiToken::class)->handle($admin, $name, [ApiAbility::TimeRead], ApiTokenExpiry::None);
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['name']);
    }

    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'blank' => '   ',
    '81 characters' => fn (): string => str_repeat('a', 81),
]);

it('reports no ability as the field error abilities and keeps a name of 80 characters valid', function (): void {
    $admin = ApiFixtures::admin();

    expect(fn () => app(IssueApiToken::class)->handle($admin, 'Example tool', [], ApiTokenExpiry::None))
        ->toThrow(ValidationException::class);

    $issued = app(IssueApiToken::class)->handle($admin, ' '.str_repeat('a', 80).' ', [ApiAbility::TimeRead, ApiAbility::TimeRead], ApiTokenExpiry::None);

    expect($issued->accessToken->name)->toBe(str_repeat('a', 80))
        ->and($issued->accessToken->abilities)->toBe(['time:read']);
});

it('ends a limited token at the last second of the chosen Prague day', function (string $now, ApiTokenExpiry $expiry, string $expected): void {
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    $admin = ApiFixtures::admin();

    $issued = app(IssueApiToken::class)->handle($admin, 'Example tool', [ApiAbility::TimeRead], $expiry);

    expect($issued->accessToken->refresh()->expires_at?->utc()->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    '30 days in winter time' => ['2026-10-14 10:00:00', ApiTokenExpiry::Days30, '2026-11-13 22:59:59'],
    '90 days across the clock change' => ['2026-10-14 10:00:00', ApiTokenExpiry::Days90, '2027-01-12 22:59:59'],
    '30 days in summer time' => ['2026-06-01 10:00:00', ApiTokenExpiry::Days30, '2026-07-01 21:59:59'],
    '365 days' => ['2026-10-14 10:00:00', ApiTokenExpiry::Days365, '2027-10-14 21:59:59'],
    'the Prague day after UTC midnight' => ['2026-10-14 23:30:00', ApiTokenExpiry::Days30, '2026-11-14 22:59:59'],
]);

it('gives an unlimited token no expiry', function (): void {
    $issued = app(IssueApiToken::class)->handle(ApiFixtures::admin(), 'Example tool', [ApiAbility::TimeWrite], ApiTokenExpiry::None);

    expect($issued->accessToken->refresh()->expires_at)->toBeNull();
});

it('writes one activity row with the name and abilities and neither the token nor its hash', function (): void {
    $admin = ApiFixtures::admin();

    $issued = app(IssueApiToken::class)->handle($admin, 'Example tool', [ApiAbility::TimeRead, ApiAbility::TimeWrite], ApiTokenExpiry::Days30);

    $row = Activity::query()->sole();
    $encoded = json_encode($row->getAttributes(), JSON_THROW_ON_ERROR);

    expect($row->event)->toBe('api_token.created')
        ->and($row->causer_id)->toBe($admin->getKey())
        ->and($row->properties->toArray())->toBe(['name' => 'Example tool', 'abilities' => ['time:read', 'time:write']])
        ->and($encoded)->not->toContain($issued->plainTextToken)
        ->and($encoded)->not->toContain(explode('|', $issued->plainTextToken)[1])
        ->and($encoded)->not->toContain((string) $issued->accessToken->refresh()->token);
});

it('computes the status of a token from its expiry', function (): void {
    $now = CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC');

    expect(ApiTokenStatus::for(null, $now))->toBe(ApiTokenStatus::Active)
        ->and(ApiTokenStatus::for($now->addDays(8), $now))->toBe(ApiTokenStatus::Active)
        ->and(ApiTokenStatus::for($now->addDays(7), $now))->toBe(ApiTokenStatus::ExpiringSoon)
        ->and(ApiTokenStatus::for($now->addSecond(), $now))->toBe(ApiTokenStatus::ExpiringSoon)
        ->and(ApiTokenStatus::for($now, $now))->toBe(ApiTokenStatus::Expired)
        ->and(ApiTokenStatus::for($now->subDay(), $now))->toBe(ApiTokenStatus::Expired);
});
