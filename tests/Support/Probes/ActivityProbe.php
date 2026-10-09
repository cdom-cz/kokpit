<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Shared\Database\MorphMap;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A test-only model that logs activity through the allowlist. Only title and
 * status may reach the log; secret_note and api_token are the canary columns
 * the tests search for. Its table is created inside each test (PostgreSQL DDL
 * is transactional, so RefreshDatabase rolls it back) and its alias is merged
 * into the morph map at runtime by the test only, so it never reaches the
 * production map.
 *
 * @property string $id
 * @property string $title
 * @property string $status
 * @property string|null $secret_note
 * @property string|null $api_token
 */
#[LoggedAttributes(['title', 'status'])]
final class ActivityProbe extends KokpitModel
{
    use LogsAllowlistedActivity;

    public const string ALIAS = 'activity_probe';

    protected $table = 'activity_probes';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['api_token'];

    /**
     * Creates the probe table inside the test transaction and merges the probe
     * alias into the morph map.
     */
    public static function provision(): void
    {
        Schema::create('activity_probes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('title');
            $table->string('status')->default('open');
            $table->text('secret_note')->nullable();
            $table->string('api_token')->nullable();
            $table->timestampsTz();
        });
        Relation::morphMap([self::ALIAS => self::class], merge: true);
    }

    /**
     * Restores the production morph map after a test used provision().
     */
    public static function restoreMorphMap(): void
    {
        Relation::morphMap(MorphMap::MAP, merge: false);
    }
}
