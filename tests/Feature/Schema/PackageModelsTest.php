<?php

declare(strict_types=1);

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Probes\PackageProbe;
use Tests\Support\Probes\ProbeNotification;
use Tests\Support\Uuids;

afterEach(function () {
    // The probe alias is test-only: restore the production morph map.
    PackageProbe::restoreMorphMap();
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
    $host = PackageProbe::provision();

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
    $host = PackageProbe::provision();

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
    $host = PackageProbe::provision();
    $host->attachTag('fictional-topic');

    Tag::query()->firstOrFail()->delete();

    expect(DB::table('taggables')->count())->toBe(0);
});

it('logs an activity with a user as subject and causer under uuid keys and the user alias', function () {
    $user = User::factory()->create();

    $activity = activity()->performedOn($user)->causedBy($user)->log('Fictional probe activity');

    $row = DB::table('activity_log')->first();

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and(DB::table('activity_log')->count())->toBe(1)
        ->and($row)->not->toBeNull()
        ->and($row->id)->toMatch(Uuids::V7_PATTERN)
        ->and($row->subject_type)->toBe('user')
        ->and($row->subject_id)->toBe($user->id)
        ->and($row->causer_type)->toBe('user')
        ->and($row->causer_id)->toBe($user->id)
        ->and($activity->subject?->is($user))->toBeTrue()
        ->and($activity->causer?->is($user))->toBeTrue();
});

it('allows an activity without a causer', function () {
    $user = User::factory()->create();

    activity()->performedOn($user)->log('Fictional system activity');

    $row = DB::table('activity_log')->first();

    expect($row)->not->toBeNull()
        ->and($row->id)->toMatch(Uuids::V7_PATTERN)
        ->and($row->causer_type)->toBeNull()
        ->and($row->causer_id)->toBeNull()
        ->and($row->subject_type)->toBe('user');
});

it('stores a webhook call with a version 7 id through the configured model', function () {
    /** @var class-string<WebhookCall> $model */
    $model = config('webhook-client.configs.0.webhook_model');
    $url = 'https://'.implode('.', ['example', 'com']).'/webhooks/probe';

    $call = $model::create([
        'name' => 'default',
        'url' => $url,
        'payload' => ['event' => 'fictional.probe'],
    ]);

    $row = DB::table('webhook_calls')->first();

    expect($model)->toBe(WebhookCall::class)
        ->and($call)->toBeInstanceOf(WebhookCall::class)
        ->and(DB::table('webhook_calls')->count())->toBe(1)
        ->and($row)->not->toBeNull()
        ->and($row->id)->toMatch(Uuids::V7_PATTERN)
        ->and($call->getKey())->toBe($row->id)
        ->and($row->url)->toBe($url)
        ->and(WebhookCall::find($row->id)?->payload)->toBe(['event' => 'fictional.probe']);
});
