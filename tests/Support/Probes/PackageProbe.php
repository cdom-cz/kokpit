<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Shared\Database\MorphMap;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Creates the probe table inside the test transaction, merges the probe
     * alias into the morph map and returns one probe row.
     */
    public static function provision(): self
    {
        Schema::create('package_probes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('name');
            $table->timestampsTz();
        });
        Relation::morphMap([self::ALIAS => self::class], merge: true);

        return self::create(['name' => 'Fictional probe host']);
    }

    /**
     * Restores the production morph map after a test used provision().
     */
    public static function restoreMorphMap(): void
    {
        Relation::morphMap(MorphMap::MAP, merge: false);
    }
}
