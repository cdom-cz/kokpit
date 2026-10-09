<?php

declare(strict_types=1);

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Tests\Support\AuditDeclaration;
use Tests\Support\Probes\ActivityProbe;

/*
 * No application model can log activity without a reviewed allowlist (D-06):
 * it uses the wrapper trait, does not override the options, declares a
 * non-empty #[LoggedAttributes] and lists no wildcard, path, hidden attribute
 * or sensitive name. Rule (d), real columns, needs the database and lives in
 * tests/Feature/Operations/ActivityAllowlistColumnsTest.php. This file never
 * touches the database.
 */

/** A parent that declares an allowlist; a subclass must declare its own. */
#[LoggedAttributes(['title'])]
abstract class AllowlistedParentFixture extends KokpitModel
{
    use LogsAllowlistedActivity;
}

/**
 * Allowlist problems of a model instance (the self-checks use anonymous classes).
 *
 * @return list<string>
 */
function allowlistProblemsOf(object $model): array
{
    return AuditDeclaration::problems($model::class);
}

it('lists the application models that log activity explicitly', function (): void {
    // Invoices add themselves here, with their allowlist, when their plan creates them.
    expect(AuditDeclaration::loggingModels())->toBe([Client::class, Contact::class, Project::class, ProjectBilling::class, Task::class, TaskBilling::class, TimeEntry::class]);
});

it('finds a logging model when it scans the probe directory, so the scan cannot pass vacuously', function (): void {
    expect(AuditDeclaration::loggingModels(base_path('tests/Support/Probes'), 'Tests\\Support\\Probes\\'))
        ->toContain(ActivityProbe::class);
});

it('passes the reviewed probe model', function (): void {
    expect(AuditDeclaration::problems(ActivityProbe::class))->toBe([]);
});

it('requires every logging application model to be free of allowlist problems', function (): void {
    $problems = [];

    foreach (AuditDeclaration::loggingModels() as $class) {
        $problems = [...$problems, ...AuditDeclaration::problems($class)];
    }

    expect($problems)->toBe([]);
});

it('reports a model that uses the package trait without the wrapper', function (): void {
    $model = new #[LoggedAttributes(['title'])] class extends KokpitModel
    {
        use LogsActivity;

        public function getActivitylogOptions(): LogOptions
        {
            return LogOptions::defaults()->logOnly(['title']);
        }
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain('without the LogsAllowlistedActivity wrapper');
});

it('reports a model that overrides the logging options', function (): void {
    $model = new #[LoggedAttributes(['title'])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;

        public function getActivitylogOptions(): LogOptions
        {
            return LogOptions::defaults()->logAll();
        }
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain('overrides getActivitylogOptions()');
});

it('reports a model without an allowlist attribute', function (): void {
    $model = new class extends KokpitModel
    {
        use LogsAllowlistedActivity;
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain('declares no #[LoggedAttributes]');
});

it('reports an attribute that exists only on a parent class, because attributes are not inherited', function (): void {
    $child = new class extends AllowlistedParentFixture {};

    expect(allowlistProblemsOf($child))->toHaveCount(1)
        ->and(allowlistProblemsOf($child)[0])->toContain('declares no #[LoggedAttributes]');
});

it('reports an empty allowlist', function (): void {
    $model = new #[LoggedAttributes([])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain('empty #[LoggedAttributes]');
});

it('reports a wildcard', function (): void {
    $model = new #[LoggedAttributes(['*'])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain("allowlists '*'")->toContain('wildcard');
});

it('reports a dotted relation path', function (): void {
    $model = new #[LoggedAttributes(['client.name'])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain("'client.name'")->toContain('dotted relation path');
});

it('reports a JSON path', function (): void {
    $model = new #[LoggedAttributes(['meta->x'])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain("'meta->x'")->toContain('JSON path');
});

it('reports an attribute that is hidden on the model', function (): void {
    $model = new #[LoggedAttributes(['title', 'internal_note'])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;

        /** @var list<string> */
        protected $hidden = ['internal_note'];
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain("'internal_note'")->toContain('hidden');
});

it('reports every sensitive name', function (string $name): void {
    $problems = AuditDeclaration::attributeProblems($name);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('sensitive');
})->with(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'stripe_secret', 'access_token', 'api_token']);

it('accepts ordinary business attribute names', function (string $name): void {
    expect(AuditDeclaration::attributeProblems($name))->toBe([]);
})->with(['title', 'status', 'due_date', 'billing_state', 'tokens_per_hour']);

it('reports a sensitive name on a model, next to the wildcard and path rules', function (): void {
    $model = new #[LoggedAttributes(['title', 'password'])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;
    };

    expect(allowlistProblemsOf($model))->toHaveCount(1)
        ->and(allowlistProblemsOf($model)[0])->toContain("'password'")->toContain('sensitive');
});
