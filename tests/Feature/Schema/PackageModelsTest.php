<?php

declare(strict_types=1);

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Probes\ProbeNotification;
use Tests\Support\Uuids;

it('creates Sanctum tokens as <uuid>|<secret> and resolves the owner through findToken', function () {
    $user = User::factory()->create();

    $plain = $user->createToken('probe')->plainTextToken;
    [$prefix, $secret] = explode('|', $plain, 2);

    $token = PersonalAccessToken::findToken($plain);

    expect($prefix)->toMatch(Uuids::V7_PATTERN)
        ->and($secret)->not->toBe('')
        ->and($token)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($token?->getKey())->toBe($prefix)
        ->and($token?->tokenable)->toBeInstanceOf(User::class)
        ->and($token?->tokenable?->is($user))->toBeTrue();
});

it('stores the user alias and uuid keys for a token', function () {
    $user = User::factory()->create();
    $user->createToken('probe');

    $row = DB::table('personal_access_tokens')->first();

    expect(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and($row)->not->toBeNull()
        ->and($row->id)->toMatch(Uuids::V7_PATTERN)
        ->and($row->tokenable_type)->toBe('user')
        ->and($row->tokenable_id)->toBe($user->id);
});

it('rejects a token whose secret does not match', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('probe')->plainTextToken;
    [$prefix] = explode('|', $plain, 2);

    expect(PersonalAccessToken::findToken($prefix.'|'.str_repeat('x', 40)))->toBeNull();
});

it('stores one database notification with a version 7 id and the user alias', function () {
    $user = User::factory()->create();

    $user->notify(new ProbeNotification);

    $row = DB::table('notifications')->first();

    expect(DB::table('notifications')->count())->toBe(1)
        ->and($row)->not->toBeNull()
        ->and($row->id)->toMatch(Uuids::V7_PATTERN)
        ->and($row->notifiable_type)->toBe('user')
        ->and($row->notifiable_id)->toBe($user->id)
        ->and($user->notifications()->count())->toBe(1);
});
