<?php

declare(strict_types=1);

use App\Domain\Audit\ActivitySource;
use App\Domain\Audit\ActivitySourceLabel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\Probes\ActivityProbe;
use Tests\Support\Probes\ActivityProbeJob;
use Tests\Support\RawSql;

/*
 * The source label of every activity row (D-08): web, console, job or webhook,
 * set on the server in the log action. Work without a signed-in user is logged
 * with a null causer.
 */

beforeEach(function (): void {
    ActivityProbe::provision();
});

afterEach(function (): void {
    ActivityProbe::restoreMorphMap();
});

/**
 * Makes the application read as a web request: the test runner is always a
 * console process, so the console detector is replaced.
 */
function simulateWebRequest(): void
{
    app()->instance(ActivitySource::class, new ActivitySource(fn (): bool => false));
}

/**
 * The raw activity row written for the created event of the probe with this title.
 */
function createdRowOfProbe(string $title): object
{
    $probe = ActivityProbe::query()->where('title', $title)->firstOrFail();

    return DB::table('activity_log')
        ->where('subject_id', $probe->getKey())
        ->where('event', 'created')
        ->firstOrFail();
}

it('labels a write by a signed-in user in a web request as web with that user as causer', function (): void {
    simulateWebRequest();
    $admin = Canary::admin();
    $this->actingAs($admin);

    ActivityProbe::query()->create(['title' => 'web write']);

    $row = createdRowOfProbe('web write');

    expect($row->source)->toBe('web')
        ->and($row->causer_id)->toBe($admin->getKey());
});

it('labels a console command write without a user as console with a null causer', function (): void {
    Artisan::command('kokpit-test:touch-probe', function (): void {
        ActivityProbe::query()->create(['title' => 'console write']);
    });

    $this->artisan('kokpit-test:touch-probe')->assertSuccessful();

    $row = createdRowOfProbe('console write');

    expect($row->source)->toBe('console')
        ->and($row->causer_id)->toBeNull()
        ->and($row->causer_type)->toBeNull();
});

it('labels a queued job write as job with a null causer and returns to console afterwards', function (): void {
    ActivityProbeJob::dispatch();
    ActivityProbe::query()->create(['title' => 'after the job']);

    $job = createdRowOfProbe('job plain');

    expect($job->source)->toBe('job')
        ->and($job->causer_id)->toBeNull()
        ->and(createdRowOfProbe('after the job')->source)->toBe('console');
});

it('leaves the job state when a job throws, so the next write is console again', function (): void {
    expect(fn () => ActivityProbeJob::dispatch(ActivityProbeJob::MODE_FAIL))
        ->toThrow(RuntimeException::class);

    ActivityProbe::query()->create(['title' => 'after the failure']);

    expect(createdRowOfProbe('job before failure')->source)->toBe('job')
        ->and(createdRowOfProbe('after the failure')->source)->toBe('console')
        ->and(app(ActivitySource::class)->current())->toBe(ActivitySourceLabel::Console);
});

it('labels a write inside the webhook wrapper as webhook and returns to job afterwards', function (): void {
    ActivityProbeJob::dispatch(ActivityProbeJob::MODE_WEBHOOK);

    expect(createdRowOfProbe('job before')->source)->toBe('job')
        ->and(createdRowOfProbe('job inside')->source)->toBe('webhook')
        ->and(createdRowOfProbe('job after')->source)->toBe('job');
});

it('restores nested wrapper labels in order, also when the callback throws', function (): void {
    $source = app(ActivitySource::class);
    $seen = [];

    $source->as(ActivitySourceLabel::Webhook, function () use ($source, &$seen): void {
        $seen[] = $source->current();
        $source->as(ActivitySourceLabel::Job, function () use ($source, &$seen): void {
            $seen[] = $source->current();
        });
        $seen[] = $source->current();
    });
    $seen[] = $source->current();

    expect($seen)->toBe([
        ActivitySourceLabel::Webhook,
        ActivitySourceLabel::Job,
        ActivitySourceLabel::Webhook,
        ActivitySourceLabel::Console,
    ]);

    try {
        $source->as(ActivitySourceLabel::Webhook, function () use ($source): void {
            $source->as(ActivitySourceLabel::Job, fn () => throw new RuntimeException('Fictional failure'));
        });
    } catch (RuntimeException) {
        // expected: only the restore afterwards matters
    }

    expect($source->current())->toBe(ActivitySourceLabel::Console);
});

it('never lets the job depth go below zero', function (): void {
    $source = app(ActivitySource::class);

    $source->leaveJob();
    $source->leaveJob();
    $source->enterJob();

    expect($source->current())->toBe(ActivitySourceLabel::Job);

    $source->leaveJob();

    expect($source->current())->toBe(ActivitySourceLabel::Console);
});

it('gives a manual activity log call a source as well', function (): void {
    simulateWebRequest();

    activity()->log('Fictional manual entry');

    $row = DB::table('activity_log')->where('description', 'Fictional manual entry')->first();

    expect($row?->source)->toBe('web');
});

it('rejects an unknown source value with a check violation', function (): void {
    RawSql::expectSqlState('23514', function (): void {
        DB::table('activity_log')->insert(['description' => 'Fictional bad source', 'source' => 'cron']);
    });
});

it('accepts each of the four labels and a row without a label', function (): void {
    foreach ([...array_column(ActivitySourceLabel::cases(), 'value'), null] as $value) {
        RawSql::expectAllowed(function () use ($value): void {
            DB::table('activity_log')->insert(['description' => 'Fictional label row', 'source' => $value]);
        });
    }

    expect(DB::table('activity_log')->count())->toBe(5);
});

it('writes a non-null source on every row of every path above', function (): void {
    Artisan::command('kokpit-test:touch-probe-again', function (): void {
        ActivityProbe::query()->create(['title' => 'console again']);
    });

    $this->artisan('kokpit-test:touch-probe-again')->assertSuccessful();
    ActivityProbeJob::dispatch(ActivityProbeJob::MODE_WEBHOOK);
    activity()->log('Fictional manual entry');
    simulateWebRequest();
    $this->actingAs(Canary::admin());
    ActivityProbe::query()->create(['title' => 'web again']);

    expect(DB::table('activity_log')->count())->toBeGreaterThanOrEqual(5)
        ->and(DB::table('activity_log')->whereNull('source')->count())->toBe(0);
});
