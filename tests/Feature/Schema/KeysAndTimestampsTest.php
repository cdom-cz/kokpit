<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Uuids;

it('gives a factory user a version 7 uuid id that find() resolves', function () {
    $user = User::factory()->create();

    expect($user->id)->toBeString()->toMatch(Uuids::V7_PATTERN)
        ->and(User::find($user->id)?->is($user))->toBeTrue();
});

it('generates 1000 unique version 7 uuids in one tight loop', function () {
    $ids = [];
    for ($i = 0; $i < 1000; $i++) {
        $ids[] = (string) Str::uuid7();
    }

    expect(array_unique($ids))->toHaveCount(1000);
    foreach ($ids as $id) {
        expect($id)->toMatch(Uuids::V7_PATTERN);
    }
});

it('gives a raw insert without an id a version 7 id from the column default', function () {
    DB::insert(
        'INSERT INTO users (name, email, password, created_at, updated_at) VALUES (?, ?, ?, now(), now())',
        ['Raw Insert', exampleEmail(), 'not-a-real-hash'],
    );

    $id = DB::table('users')->value('id');

    expect($id)->toBeString()->toMatch(Uuids::V7_PATTERN);
});

it('rejects an explicit NULL id with SQLSTATE 23502 and keeps the transaction usable', function () {
    $sqlState = null;

    try {
        DB::transaction(fn () => DB::insert(
            'INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES (NULL, ?, ?, ?, now(), now())',
            ['Null Id', exampleEmail(), 'not-a-real-hash'],
        ));
    } catch (QueryException $e) {
        $sqlState = $e->errorInfo[0] ?? null;
    }

    expect($sqlState)->toBe('23502')
        ->and(DB::table('users')->count())->toBe(0);
});

it('sorts rows created at least 2 ms apart by id in creation order', function () {
    $created = [];
    for ($i = 0; $i < 3; $i++) {
        $created[] = User::factory()->create()->id;
        usleep(2000);
    }

    expect(User::query()->orderBy('id')->pluck('id')->all())->toBe($created);
});

it('round-trips a NULL timestamptz as null', function () {
    $user = User::factory()->unverified()->create();

    expect(User::findOrFail($user->id)->email_verified_at)->toBeNull();
});

it('round-trips an instant written in UTC unchanged', function () {
    $instant = CarbonImmutable::parse('2026-01-05 23:30:00', 'UTC');
    $user = User::factory()->create(['email_verified_at' => $instant]);

    $read = User::findOrFail($user->id)->email_verified_at;

    expect($read?->getTimestamp())->toBe($instant->getTimestamp())
        ->and($read?->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-01-05 23:30:00');
});

it('refuses to mass assign client_id (D-01)', function () {
    expect(fn () => User::create([
        'name' => 'Client Probe',
        'email' => exampleEmail(),
        'password' => 'not-a-real-secret',
        'client_id' => (string) Str::uuid7(),
    ]))->toThrow(MassAssignmentException::class);
});
