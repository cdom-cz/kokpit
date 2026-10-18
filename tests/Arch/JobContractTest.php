<?php

declare(strict_types=1);

use App\Domain\Api\Jobs\PurgeExpiredIdempotencyKeys;
use App\Domain\Documents\Jobs\BuildDocumentArchive;
use App\Domain\Documents\Jobs\PruneExpiredDocumentArchives;
use App\Domain\Documents\Jobs\RemoveDocumentObjects;
use App\Domain\ExchangeRates\Jobs\BackfillCnbRates;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Operations\Jobs\Middleware\RunsAsSystem;
use App\Domain\Operations\Jobs\RecordWorkerHeartbeat;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Jobs\DeactivatePaymentLinks;
use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Payments\Jobs\PruneProcessedWebhookCalls;
use App\Domain\Reports\Jobs\BuildReportExport;
use App\Domain\Reports\Jobs\PruneExpiredReportExports;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\TimeTracking\Jobs\NotifyLongRunningTimers;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Tests\Support\JobDeclaration;
use Tests\Support\Probes\ActivityProbeJob;
use Tests\Support\Probes\FailingProbeJob;
use Tests\Support\Probes\SettingsReadingProbeJob;
use Tests\Support\Probes\UndeclaredWebhookProbeJob;

/*
 * No background job can skip the job contract (D-10): it extends KokpitJob (retry
 * defaults, system context) and declares #[Idempotent] with a written explanation.
 * This test reads class files only and never touches the database.
 */

/**
 * The application jobs expected under app/. Adding a job is a visible, reviewed
 * change: plan 03-16 added the worker heartbeat job, later phases add theirs (plan 08-12 the export prune,
 * plan 08-16 the export build, plan 08-18 the rates history backfill, plan 09-17 the document archive build, plan 09-27 the queued removal of document objects, plan 10-24 the document e-mail, plan 11-07 the Stripe payment link, plan 11-10 its remote deactivation and sweep).
 *
 * @var list<class-string<KokpitJob>>
 */
const EXPECTED_APPLICATION_JOBS = [RecordWorkerHeartbeat::class, NotifyLongRunningTimers::class, PurgeExpiredIdempotencyKeys::class, PruneExpiredReportExports::class, BuildReportExport::class, BackfillCnbRates::class, BuildDocumentArchive::class, PruneExpiredDocumentArchives::class, RemoveDocumentObjects::class, SendInvoiceEmail::class, CreatePaymentLink::class, DeactivatePaymentLinks::class, PruneProcessedWebhookCalls::class];

/**
 * The queued classes that extend the webhook package's ProcessWebhookJob instead of KokpitJob (plan 11-04, R11-04-7).
 * The package forces that parent, so each one carries the job contract by hand and is admitted only while
 * JobDeclaration::webhookJobProblems() finds nothing wrong with it.
 *
 * @var list<class-string<ProcessWebhookJob>>
 */
const EXPECTED_WEBHOOK_JOBS = [ProcessStripeWebhook::class];

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
        static fn (string $class): bool => ! is_subclass_of($class, KokpitJob::class)
            && ! (in_array($class, EXPECTED_WEBHOOK_JOBS, true) && JobDeclaration::webhookJobProblems($class) === []),
    ));

    expect($outsiders)->toBe([]);
});

it('has exactly the expected webhook jobs under app/, each carrying the job contract by hand', function (): void {
    $jobs = JobDeclaration::webhookJobs(JobDeclaration::classesIn(app_path(), 'App'));
    $problems = [];

    foreach ($jobs as $class) {
        $problems = [...$problems, ...JobDeclaration::webhookJobProblems($class)];
    }

    expect($jobs)->toEqualCanonicalizing(EXPECTED_WEBHOOK_JOBS)
        ->and($problems)->toBe([]);
});

it('reports a webhook job without #[Idempotent] and without the system context, and one without a retry attribute', function (): void {
    $problems = JobDeclaration::webhookJobProblems(UndeclaredWebhookProbeJob::class);
    $untimed = new #[Idempotent(how: 'Does nothing, so a second run changes nothing')] #[Tries(3)] #[Backoff(10, 60, 300)] class(new WebhookCall) extends ProcessWebhookJob
    {
        /** @return array<int, object> */
        public function middleware(): array
        {
            return [new RunsAsSystem];
        }
    };
    $outsider = new class extends KokpitJob
    {
        public function handle(): void {}
    };

    expect($problems)->toHaveCount(2)
        ->and($problems[0])->toContain('without #[Idempotent]')
        ->and($problems[1])->toContain('without RunsAsSystem')
        ->and(JobDeclaration::webhookJobProblems($untimed::class))->toHaveCount(1)
        ->and(JobDeclaration::webhookJobProblems($untimed::class)[0])->toContain('without #[Timeout]')
        ->and(JobDeclaration::webhookJobProblems($outsider::class))->toHaveCount(1)
        ->and(JobDeclaration::webhookJobProblems($outsider::class)[0])->toContain('is not a ProcessWebhookJob')
        ->and(JobDeclaration::webhookJobProblems(ProcessStripeWebhook::class))->toBe([]);
});

it('does not admit the undeclared webhook probe through the expected list', function (): void {
    expect(EXPECTED_WEBHOOK_JOBS)->not->toContain(UndeclaredWebhookProbeJob::class)
        ->and(JobDeclaration::webhookJobs(JobDeclaration::classesIn(base_path('tests/Support/Probes'), 'Tests\\Support\\Probes')))->toContain(UndeclaredWebhookProbeJob::class);
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

it('declares the worker heartbeat job as idempotent with a written explanation', function (): void {
    $attributes = (new ReflectionClass(RecordWorkerHeartbeat::class))->getAttributes(Idempotent::class);

    expect($attributes)->toHaveCount(1)
        ->and(trim($attributes[0]->newInstance()->how))->not->toBe('')
        ->and(is_subclass_of(RecordWorkerHeartbeat::class, KokpitJob::class))->toBeTrue();
});
