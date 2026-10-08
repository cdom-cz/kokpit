<?php

declare(strict_types=1);

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Indicators\FailedJobsIndicator;
use Illuminate\Support\Str;
use Tests\Support\Canary;

/*
 * The real health indicators of the System page (D-12, D-13), measured against the
 * fixed thresholds in config/kokpit.php.
 */

/**
 * Logs one failed job through the queue failer, the way a worker does after the last attempt.
 */
function logFailedJob(): void
{
    app('queue.failer')->log(
        'redis',
        'fictional-queue',
        json_encode(['uuid' => (string) Str::uuid(), 'displayName' => 'Fictional\\Job'], JSON_THROW_ON_ERROR),
        new RuntimeException('Fictional failure'),
    );
}

it('reports Ok with a count of zero when no job has failed', function (): void {
    $result = (new FailedJobsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('0')
        ->and((new FailedJobsIndicator)->slot())->toBe(HealthSlot::FailedJobs);
});

it('reports Warning with the count once a job has failed for good', function (): void {
    logFailedJob();

    $result = (new FailedJobsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->value)->toBe('1');
});

it('takes the warning threshold from the configuration', function (): void {
    config(['kokpit.health.failed_jobs_warning_at' => 2]);
    logFailedJob();

    expect((new FailedJobsIndicator)->check()->status)->toBe(HealthStatus::Ok);

    logFailedJob();

    expect((new FailedJobsIndicator)->check()->status)->toBe(HealthStatus::Warning)
        ->and((new FailedJobsIndicator)->check()->value)->toBe('2');
});

it('is the indicator the bound registry runs for the failed-jobs slot', function (): void {
    expect(app(HealthIndicatorRegistry::class)->indicators()[HealthSlot::FailedJobs->value])
        ->toBeInstanceOf(FailedJobsIndicator::class);
});

it('shows the Admin the failed-jobs slot as Warning with the count after a job failed', function (): void {
    logFailedJob();

    $html = (string) $this->actingAs(Canary::admin())->get('/admin/system')->assertOk()->getContent();

    expect($html)->toContain(__('enums.health_slot.failed_jobs'))
        ->toContain(__('enums.health_status.warning'))
        ->toContain('fi-color-warning')
        ->toContain(__('kokpit.system.failed_jobs.some'));
});
