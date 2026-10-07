<?php

declare(strict_types=1);

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Database\MorphMap;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\Tag;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Probes\PackageProbe;
use Tests\Support\Probes\ProbeNotification;
use Tests\Support\Uuids;

/**
 * Creates the probe table inside the test transaction and merges the probe
 * alias into the morph map, then returns one probe row.
 */
function probeHost(): PackageProbe
{
    Schema::create('package_probes', function (Blueprint $table) {
        $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
        $table->string('name');
        $table->timestampsTz();
    });
    Relation::morphMap([PackageProbe::ALIAS => PackageProbe::class], merge: true);

    return PackageProbe::create(['name' => 'Fictional probe host']);
}

afterEach(function () {
    // The probe alias is test-only: restore the production morph map.
    Relation::morphMap(MorphMap::MAP, merge: false);
});

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

it('stores media for a host model with a version 7 id and the host alias', function () {
    config(['media-library.disk_name' => 'local']);
    Storage::fake('local');
    $host = probeHost();

    $media = $host->addMediaFromString('Fictional probe file content')
        ->usingFileName('probe-note.txt')
        ->toMediaCollection('probe');

    $row = DB::table('media')->first();

    expect($media)->toBeInstanceOf(Media::class)
        ->and(DB::table('media')->count())->toBe(1)
        ->and($row)->not->toBeNull()
        ->and($row->id)->toMatch(Uuids::V7_PATTERN)
        ->and($media->getKey())->toBe($row->id)
        ->and($row->model_type)->toBe(PackageProbe::ALIAS)
        ->and($row->model_id)->toBe($host->id)
        ->and($host->fresh()?->getFirstMedia('probe')?->is($media))->toBeTrue()
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeTrue();
});

it('stores a tag with a version 7 id and a taggables row with uuid keys and the host alias', function () {
    $host = probeHost();

    $host->attachTag('fictional-topic');

    $tag = DB::table('tags')->first();
    $pivot = DB::table('taggables')->first();

    expect(DB::table('tags')->count())->toBe(1)
        ->and($tag)->not->toBeNull()
        ->and($tag->id)->toMatch(Uuids::V7_PATTERN)
        ->and($pivot)->not->toBeNull()
        ->and($pivot->tag_id)->toBe($tag->id)
        ->and($pivot->taggable_type)->toBe(PackageProbe::ALIAS)
        ->and($pivot->taggable_id)->toBe($host->id)
        ->and($host->fresh()?->tags->first())->toBeInstanceOf(Tag::class);
});

it('removes the taggables row when its tag is deleted', function () {
    $host = probeHost();
    $host->attachTag('fictional-topic');

    Tag::query()->firstOrFail()->delete();

    expect(DB::table('taggables')->count())->toBe(0);
});
