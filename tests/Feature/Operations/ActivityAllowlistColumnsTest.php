<?php

declare(strict_types=1);

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuditDeclaration;
use Tests\Support\Probes\ActivityProbe;

/*
 * Rule (d) of the activity allowlist (D-06): every allowlisted attribute is a
 * real column of the model's table. The other rules need no database and live
 * in tests/Arch/ActivityAllowlistTest.php.
 */

beforeEach(function (): void {
    ActivityProbe::provision();
});

afterEach(function (): void {
    ActivityProbe::restoreMorphMap();
});

it('finds only real columns on the probe model', function (): void {
    $columns = Schema::getColumnListing((new ActivityProbe)->getTable());

    expect($columns)->toContain('title', 'status')
        ->and(AuditDeclaration::problems(ActivityProbe::class, $columns))->toBe([]);
});

it('finds only real columns on every application model that logs activity', function (): void {
    $problems = [];

    foreach (AuditDeclaration::loggingModels() as $class) {
        $columns = Schema::getColumnListing((new $class)->getTable());

        expect($columns)->not->toBe([], "the table of {$class} has no columns");

        $problems = [...$problems, ...AuditDeclaration::problems($class, $columns)];
    }

    expect($problems)->toBe([]);
});

it('reports an allowlisted attribute that is not a column of the table', function (): void {
    $model = new #[LoggedAttributes(['title', 'missing_column'])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;

        protected $table = 'activity_probes';
    };
    $columns = Schema::getColumnListing($model->getTable());

    expect(AuditDeclaration::problems($model::class, $columns))->toHaveCount(1)
        ->and(AuditDeclaration::problems($model::class, $columns)[0])
        ->toContain("'missing_column'")->toContain('not a column');
});

it('skips the column rule when no column list is given', function (): void {
    $model = new #[LoggedAttributes(['title', 'missing_column'])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;

        protected $table = 'activity_probes';
    };

    expect(AuditDeclaration::problems($model::class))->toBe([]);
});
