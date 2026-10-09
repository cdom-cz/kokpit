<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Billing\BillableDefault;
use Database\Factories\ProjectFactory;
use Tests\Support\Canary;

/*
 * The D-03 billable default: true unless the billing resolver says the task is
 * non-billable, including by inheritance from the parent task. A project has no
 * non-billable value and a fixed-price project stays billable. Every name and
 * amount is fictional.
 */

/**
 * A project of a fresh client, written through the domain Action so the billing
 * resolver finds its billing row.
 *
 * @param  array<string, mixed>  $projectData
 */
function billableDefaultProject(array $projectData = []): Project
{
    $client = Client::factory()->create([
        'currency' => 'CZK',
        'hourly_rate' => Money::fromMajor('800', 'CZK'),
    ]);

    return app(CreateProject::class)->handle($client, [
        'name' => 'Example billable project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
        ...$projectData,
    ]);
}

/**
 * A task or subtask of the project; the billing keys go through UpdateTask like on the edit page.
 *
 * @param  array<string, mixed>  $billing
 */
function billableDefaultTask(Project $project, array $billing = [], ?Task $parent = null): Task
{
    $admin = test()->admin;
    $task = app(CreateTask::class)->handle($admin, $project, ['title' => 'Example billable task'], $parent);

    if ($billing !== []) {
        $task = app(UpdateTask::class)->handle($admin, $task, $billing);
    }

    return $task->refresh();
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('is billable without a task', function (): void {
    expect(app(BillableDefault::class)->for(null))->toBeTrue();
});

it('is billable for an hourly task', function (): void {
    $task = billableDefaultTask(billableDefaultProject());

    expect(app(BillableDefault::class)->for($task))->toBeTrue();
});

it('is not billable for a task whose own billing row is non_billable', function (): void {
    $task = billableDefaultTask(billableDefaultProject(), ['billing_type' => 'non_billable']);

    expect(app(BillableDefault::class)->for($task))->toBeFalse();
});

it('is not billable for a subtask without a row under a non_billable parent', function (): void {
    $project = billableDefaultProject();
    $parent = billableDefaultTask($project, ['billing_type' => 'non_billable']);
    $subtask = billableDefaultTask($project, [], $parent);

    expect(app(BillableDefault::class)->for($subtask))->toBeFalse();
});

it('is billable for a subtask with its own hourly row under a non_billable parent', function (): void {
    $project = billableDefaultProject();
    $parent = billableDefaultTask($project, ['billing_type' => 'non_billable']);
    $subtask = billableDefaultTask($project, ['billing_type' => 'hourly', 'hourly_rate' => '700'], $parent);

    expect(app(BillableDefault::class)->for($subtask))->toBeTrue();
});

it('is billable for a task in a fixed-price project with no task row', function (): void {
    $project = billableDefaultProject(['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
    $task = billableDefaultTask($project);

    expect(app(BillableDefault::class)->for($task))->toBeTrue();
});
