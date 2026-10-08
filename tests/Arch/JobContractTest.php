<?php

declare(strict_types=1);

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use Illuminate\Foundation\Bus\Dispatchable;
use Tests\Support\JobDeclaration;
use Tests\Support\Probes\ActivityProbeJob;
use Tests\Support\Probes\FailingProbeJob;
use Tests\Support\Probes\SettingsReadingProbeJob;

/*
 * No background job can skip the job contract (D-10): it extends KokpitJob (retry
 * defaults, system context) and declares #[Idempotent] with a written explanation.
 * This test reads class files only and never touches the database.
 */

/**
 * The application jobs expected under app/. Empty in this phase; plan 03-16
 * adds its heartbeat job here, so adding a job is a visible, reviewed change.
 *
 * @var list<class-string<KokpitJob>>
 */
const EXPECTED_APPLICATION_JOBS = [];

/** A concrete job that a self-check subclasses, to prove the declaration is not inherited. */
#[Idempotent(how: 'Does nothing, so a second run changes nothing')]
class JobContractParentJob extends KokpitJob
{
    public function handle(): void {}
}

it('finds the classes of the application, so the scan cannot pass vacuously', function (): void {
    $classes = JobDeclaration::classesIn(app_path(), 'App');

    expect($classes)->toContain(KokpitJob::class)
        ->and(count($classes))->toBeGreaterThan(20);
});

it('finds the probe jobs of the test suite, so the scan reads real job classes', function (): void {
    $probes = JobDeclaration::classesIn(base_path('tests/Support/Probes'), 'Tests\\Support\\Probes');

    expect(JobDeclaration::concreteJobs($probes))->toContain(FailingProbeJob::class, SettingsReadingProbeJob::class)
        ->and(JobDeclaration::dispatchables($probes))->toContain(FailingProbeJob::class, ActivityProbeJob::class);
});

it('has exactly the expected application jobs under app/', function (): void {
    $jobs = JobDeclaration::concreteJobs(JobDeclaration::classesIn(app_path(), 'App'));

    expect($jobs)->toEqualCanonicalizing(EXPECTED_APPLICATION_JOBS);
});

it('makes every dispatchable class under app/ extend KokpitJob', function (): void {
    $classes = JobDeclaration::classesIn(app_path(), 'App');
    $outsiders = array_values(array_filter(
        JobDeclaration::dispatchables($classes),
        static fn (string $class): bool => ! is_subclass_of($class, KokpitJob::class),
    ));

    expect($outsiders)->toBe([]);
});

it('gives every concrete job under app/ a non-empty #[Idempotent] explanation', function (): void {
    $problems = [];

    foreach (JobDeclaration::concreteJobs(JobDeclaration::classesIn(app_path(), 'App')) as $class) {
        $problems = [...$problems, ...JobDeclaration::problems($class)];
    }

    expect($problems)->toBe([]);
});

it('reports a job without the declaration, with a blank one, and a dispatchable class outside the base', function (): void {
    $missing = new class extends KokpitJob
    {
        public function handle(): void {}
    };
    $blank = new #[Idempotent(how: '  ')] class extends KokpitJob
    {
        public function handle(): void {}
    };
    $fine = new #[Idempotent(how: 'Unique constraint on the natural key; the job checks the row first')] class extends KokpitJob
    {
        public function handle(): void {}
    };
    $outsider = new class
    {
        use Dispatchable;
    };
    $inherited = new class extends JobContractParentJob {};

    expect(JobDeclaration::problems($missing::class))->toHaveCount(1)
        ->and(JobDeclaration::problems($missing::class)[0])->toContain('without #[Idempotent]')
        ->and(JobDeclaration::problems($blank::class))->toHaveCount(1)
        ->and(JobDeclaration::problems($blank::class)[0])->toContain('without an explanation')
        ->and(JobDeclaration::problems($fine::class))->toBe([])
        ->and(JobDeclaration::problems($outsider::class))->toHaveCount(1)
        ->and(JobDeclaration::problems($outsider::class)[0])->toContain('does not extend KokpitJob')
        ->and(JobDeclaration::problems($inherited::class))->toHaveCount(1);
});

it('accepts the probe jobs that extend the base and flags the probe that does not', function (): void {
    expect(JobDeclaration::problems(FailingProbeJob::class))->toBe([])
        ->and(JobDeclaration::problems(SettingsReadingProbeJob::class))->toBe([])
        ->and(JobDeclaration::problems(ActivityProbeJob::class))->toHaveCount(1);
});
