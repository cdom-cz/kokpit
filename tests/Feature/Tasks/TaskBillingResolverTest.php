<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Billing\BillingSource;
use App\Domain\Tasks\Billing\EffectiveBilling;
use App\Domain\Tasks\Billing\TaskBillingResolver;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The effective billing of a task, resolved at read time (TA-06, D-14): the task's
 * own value, else the parent task's, else the project's, else for the hourly rate
 * the client's. Every name, key and amount is fictional.
 */

/**
 * A project of a client with the given hourly rate, written through the domain Action.
 *
 * @param  array<string, mixed>  $projectData
 */
function taskBillingResolverProject(string $clientRate = '800', array $projectData = []): Project
{
    $client = Client::factory()->create([
        'currency' => 'CZK',
        'hourly_rate' => Money::fromMajor($clientRate, 'CZK'),
    ]);

    return app(CreateProject::class)->handle($client, [
        'name' => 'Example resolver project',
        'key' => 'ABC',
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
        ...$projectData,
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('shows a subtask without overrides the project rate on its page, marked as inherited from the project', function (): void {
    $project = taskBillingResolverProject();
    $parent = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);
    $subtask = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example subtask'], $parent);

    $billing = app(TaskBillingResolver::class)->resolve($subtask);

    expect($subtask->reference)->toBe('ABC-2')
        ->and($billing->type)->toBe('hourly')
        ->and($billing->typeSource)->toBe(BillingSource::Project)
        ->and($billing->hourlyRate?->equals(Money::ofMinor(90000, 'CZK')))->toBeTrue()
        ->and($billing->hourlyRateSource)->toBe(BillingSource::Project)
        ->and($billing->isBillable())->toBeTrue();

    Livewire::test(ViewTask::class, ['record' => $subtask->reference])
        ->assertSee('Platná fakturace')
        ->assertSee(Money::ofMinor(90000, 'CZK')->format('cs'))
        ->assertSee('zdroj: Projekt');
});

/**
 * A task or subtask of the project; the billing keys go through UpdateTask like on the edit page.
 *
 * @param  array<string, mixed>  $billing
 */
function taskBillingResolverTask(Project $project, array $billing = [], ?Task $parent = null): Task
{
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example resolver task'], $parent);

    if ($billing !== []) {
        $task = app(UpdateTask::class)->handle(test()->admin, $task, $billing);
    }

    return $task->refresh();
}

/**
 * The effective billing of a task loaded fresh, so no relation cache of the test leaks in.
 */
function taskBillingResolverFor(Task $task): EffectiveBilling
{
    return app(TaskBillingResolver::class)->resolve(Task::query()->findOrFail($task->getKey()));
}

describe('hourly rate', function (): void {
    it('prefers the own rate of the task over the parent, the project and the client', function (): void {
        $project = taskBillingResolverProject();
        $parent = taskBillingResolverTask($project, ['hourly_rate' => '950']);
        $subtask = taskBillingResolverTask($project, ['hourly_rate' => '1000'], $parent);

        $billing = taskBillingResolverFor($subtask);

        expect($billing->hourlyRate?->equals(Money::ofMinor(100000, 'CZK')))->toBeTrue()
            ->and($billing->hourlyRateSource)->toBe(BillingSource::Task);
    });

    it('takes the rate of the parent task when the subtask has none', function (): void {
        $project = taskBillingResolverProject();
        $parent = taskBillingResolverTask($project, ['hourly_rate' => '950']);
        $subtask = taskBillingResolverTask($project, [], $parent);

        $billing = taskBillingResolverFor($subtask);

        expect($billing->hourlyRate?->equals(Money::ofMinor(95000, 'CZK')))->toBeTrue()
            ->and($billing->hourlyRateSource)->toBe(BillingSource::ParentTask);
    });

    it('falls back to the rate of the client when neither the tasks nor the project hold one', function (): void {
        $project = taskBillingResolverProject('800', ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $task = taskBillingResolverTask($project);

        $billing = taskBillingResolverFor($task);

        expect($billing->hourlyRate?->equals(Money::ofMinor(80000, 'CZK')))->toBeTrue()
            ->and($billing->hourlyRateSource)->toBe(BillingSource::Client);
    });

    it('keeps a rate of zero as a value instead of deferring to the next level', function (): void {
        $project = taskBillingResolverProject();
        $task = taskBillingResolverTask($project, ['hourly_rate' => '0']);

        $billing = taskBillingResolverFor($task);

        expect($billing->hourlyRate?->isZero())->toBeTrue()
            ->and($billing->hourlyRateSource)->toBe(BillingSource::Task);
    });
});

describe('billing type', function (): void {
    it('inherits non-billable from the parent task for a subtask without a row', function (): void {
        $project = taskBillingResolverProject();
        $parent = taskBillingResolverTask($project, ['billing_type' => 'non_billable']);
        $subtask = taskBillingResolverTask($project, [], $parent);

        $billing = taskBillingResolverFor($subtask);

        expect($billing->type)->toBe('non_billable')
            ->and($billing->typeSource)->toBe(BillingSource::ParentTask)
            ->and($billing->typeSource->value)->toBe('parent_task')
            ->and($billing->isBillable())->toBeFalse();
    });

    it('lets a subtask of its own type hourly win over a non-billable parent', function (): void {
        $project = taskBillingResolverProject();
        $parent = taskBillingResolverTask($project, ['billing_type' => 'non_billable']);
        $subtask = taskBillingResolverTask($project, ['billing_type' => 'hourly'], $parent);

        $billing = taskBillingResolverFor($subtask);

        expect($billing->type)->toBe('hourly')
            ->and($billing->typeSource)->toBe(BillingSource::Task)
            ->and($billing->isBillable())->toBeTrue();
    });

    it('resolves a non-billable task to non-billable whatever the project says', function (): void {
        $hourly = taskBillingResolverTask(taskBillingResolverProject(), ['billing_type' => 'non_billable']);
        $fixed = taskBillingResolverTask(
            taskBillingResolverProject('800', ['key' => 'FIX', 'billing_type' => 'fixed_price', 'fixed_price' => '5000']),
            ['billing_type' => 'non_billable'],
        );

        expect(taskBillingResolverFor($hourly)->type)->toBe('non_billable')
            ->and(taskBillingResolverFor($fixed)->type)->toBe('non_billable')
            ->and(taskBillingResolverFor($fixed)->typeSource)->toBe(BillingSource::Task);
    });

    it('takes type and price from a fixed-price project for a task that inherits', function (): void {
        $project = taskBillingResolverProject('800', ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $task = taskBillingResolverTask($project);

        $billing = taskBillingResolverFor($task);

        expect($billing->type)->toBe('fixed_price')
            ->and($billing->typeSource)->toBe(BillingSource::Project)
            ->and($billing->fixedPrice?->equals(Money::ofMinor(500000, 'CZK')))->toBeTrue()
            ->and($billing->fixedPriceSource)->toBe(BillingSource::Project)
            ->and($billing->isBillable())->toBeTrue();
    });

    it('takes the type from the next level and the rate from the task when the task row is inherit with only a rate', function (): void {
        $project = taskBillingResolverProject();
        $task = taskBillingResolverTask($project, ['billing_type' => 'inherit', 'hourly_rate' => '1100']);

        $billing = taskBillingResolverFor($task);

        expect($billing->type)->toBe('hourly')
            ->and($billing->typeSource)->toBe(BillingSource::Project)
            ->and($billing->hourlyRate?->equals(Money::ofMinor(110000, 'CZK')))->toBeTrue()
            ->and($billing->hourlyRateSource)->toBe(BillingSource::Task);
    });

    it('prefers the own fixed price of a task over the price of the project', function (): void {
        $project = taskBillingResolverProject('800', ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $task = taskBillingResolverTask($project, ['billing_type' => 'fixed_price', 'fixed_price' => '1200,50']);

        $billing = taskBillingResolverFor($task);

        expect($billing->fixedPrice?->equals(Money::ofMinor(120050, 'CZK')))->toBeTrue()
            ->and($billing->fixedPriceSource)->toBe(BillingSource::Task);
    });

    it('has no fixed price while no level holds one', function (): void {
        $task = taskBillingResolverTask(taskBillingResolverProject());

        $billing = taskBillingResolverFor($task);

        expect($billing->fixedPrice)->toBeNull()
            ->and($billing->fixedPriceSource)->toBeNull();
    });
});

describe('estimate', function (): void {
    it('inherits literally: own, then parent, then project, then nothing', function (): void {
        $project = taskBillingResolverProject('800', ['estimate_hours' => '3']);
        $bare = taskBillingResolverProject('800', ['key' => 'BAR', 'name' => 'Example project without estimate']);

        $parent = taskBillingResolverTask($project, ['estimate_hours' => '2']);
        $own = taskBillingResolverTask($project, ['estimate_hours' => '1'], $parent);
        $fromParent = taskBillingResolverTask($project, [], $parent);
        $fromProject = taskBillingResolverTask($project);
        $nothing = taskBillingResolverTask($bare);

        expect(taskBillingResolverFor($own)->estimateSeconds)->toBe(3600)
            ->and(taskBillingResolverFor($own)->estimateSource)->toBe(BillingSource::Task)
            ->and(taskBillingResolverFor($fromParent)->estimateSeconds)->toBe(7200)
            ->and(taskBillingResolverFor($fromParent)->estimateSource)->toBe(BillingSource::ParentTask)
            ->and(taskBillingResolverFor($fromProject)->estimateSeconds)->toBe(10800)
            ->and(taskBillingResolverFor($fromProject)->estimateSource)->toBe(BillingSource::Project)
            ->and(taskBillingResolverFor($nothing)->estimateSeconds)->toBeNull()
            ->and(taskBillingResolverFor($nothing)->estimateSource)->toBeNull();
    });

    it('keeps an estimate of zero as a value', function (): void {
        $project = taskBillingResolverProject('800', ['estimate_hours' => '3']);
        $task = taskBillingResolverTask($project, ['estimate_hours' => '0']);

        expect(taskBillingResolverFor($task)->estimateSeconds)->toBe(0)
            ->and(taskBillingResolverFor($task)->estimateSource)->toBe(BillingSource::Task);
    });
});

describe('context and data', function (): void {
    it('resolves nothing for a Partner, so the rate of the visible client row cannot leak', function (): void {
        $project = taskBillingResolverProject('800', ['client_visible' => true]);
        $task = taskBillingResolverTask($project);
        $clientId = $project->client_id;

        $this->actingAs(Canary::partnerFor($clientId));

        expect(fn () => app(TaskBillingResolver::class)->resolve(Task::query()->findOrFail($task->getKey())))
            ->toThrow(AuthorizationException::class);
    });

    it('shows a Partner no effective billing on the Admin task page', function (): void {
        $project = taskBillingResolverProject('800', ['client_visible' => true]);
        taskBillingResolverTask($project);

        $this->actingAs(Canary::partnerFor($project->client_id));

        $this->get('/admin/tasks/ABC-1')->assertForbidden();
    });

    it('resolves in a system run without a signed-in user', function (): void {
        $project = taskBillingResolverProject();
        $task = taskBillingResolverTask($project);

        auth()->logout();

        $billing = app(PartnerContext::class)->runAsSystem(
            static fn (): EffectiveBilling => app(TaskBillingResolver::class)->resolve(Task::query()->findOrFail($task->getKey())),
        );

        expect($billing->hourlyRate?->equals(Money::ofMinor(90000, 'CZK')))->toBeTrue();
    });

    it('refuses a call without an Admin and outside a system run', function (): void {
        $task = taskBillingResolverTask(taskBillingResolverProject());
        $loaded = Task::query()->findOrFail($task->getKey());

        auth()->logout();

        expect(fn () => app(TaskBillingResolver::class)->resolve($loaded))
            ->toThrow(AuthorizationException::class);
    });

    it('still resolves the billing of a task of an archived project', function (): void {
        $project = taskBillingResolverProject();
        $task = taskBillingResolverTask($project);
        $project->delete();

        $billing = taskBillingResolverFor($task);

        expect($billing->hourlyRate?->equals(Money::ofMinor(90000, 'CZK')))->toBeTrue()
            ->and($billing->hourlyRateSource)->toBe(BillingSource::Project);
    });

    it('fails loudly when the project has no billing row instead of guessing a type', function (): void {
        $project = taskBillingResolverProject();
        $task = taskBillingResolverTask($project);
        DB::table('project_billing')->where('project_id', $project->getKey())->delete();

        expect(fn () => taskBillingResolverFor($task))->toThrow(LogicException::class);
    });

    it('works on a task whose relations are already loaded without reading them again', function (): void {
        $project = taskBillingResolverProject();
        $task = taskBillingResolverTask($project, ['hourly_rate' => '1000']);
        $loaded = Task::query()->with(['billing', 'parent.billing', 'project.billing', 'project.client'])->findOrFail($task->getKey());

        DB::enableQueryLog();
        $billing = app(TaskBillingResolver::class)->resolve($loaded);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toBe([])
            ->and($billing->hourlyRate?->equals(Money::ofMinor(100000, 'CZK')))->toBeTrue();
    });
});
