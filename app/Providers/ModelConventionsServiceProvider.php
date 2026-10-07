<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Shared\Database\MorphMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Ramsey\Uuid\Uuid;

/**
 * Enforces the data conventions at runtime: an enforced morph map, uuid morph
 * columns for future migrations and loud failures for discarded attributes.
 */
final class ModelConventionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Also requires the map, so an unmapped class throws ClassMorphViolationException.
        Relation::enforceMorphMap(MorphMap::MAP);

        // Safety net for morphs() calls in later published migrations; the schema test is the real guard.
        Schema::morphUsingUuids();

        // Sanctum tokens get UUID v7 keys; the base model would cast the key to an integer.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // The framework generates database notification ids (and queue job uuids)
        // with Str::uuid(), which is version 4. Make every generated uuid version 7.
        Str::createUuidsUsing(static fn () => Uuid::uuid7());

        // A non-fillable attribute fails loudly in development and tests.
        // Lazy-loading prevention stays off: package role checks load relations lazily.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
