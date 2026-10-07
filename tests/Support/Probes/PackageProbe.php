<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Shared\Models\KokpitModel;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;

/**
 * A test-only host model for media and tags. Its table is created inside each
 * test (PostgreSQL DDL is transactional, so RefreshDatabase rolls it back) and
 * its alias is merged into the morph map at runtime by the test only, so it
 * never reaches the production map.
 *
 * @property string $id
 * @property string $name
 */
final class PackageProbe extends KokpitModel implements HasMedia
{
    use HasTags, InteractsWithMedia;

    public const string ALIAS = 'package_probe';

    protected $table = 'package_probes';

    protected $guarded = [];
}
