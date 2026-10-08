<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin-only billing overrides of a task (TA-06, D-12 to D-14): billing type,
 * fixed price, hourly rate override and estimate live in task_billing, never in
 * the Partner-readable tasks table. Every name, key and amount is fictional.
 */

/**
 * A project of a client with the given currency, written through the domain Action.
 */
function taskBillingProject(string $key = 'BIL', string $currency = 'CZK', bool $visible = false): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(['currency' => $currency]), [
        'name' => 'Example billing project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => $visible,
    ]);
}

/**
 * A task of the project, created as the signed-in Admin.
 */
function taskBillingTask(Project $project): Task
{
    return app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example billing task']);
}

/**
 * The stored billing row of the task, read without the relation cache.
 */
function taskBillingRow(Task $task): ?TaskBilling
{
    return TaskBilling::query()->where('task_id', $task->getKey())->first();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('stores a fixed price set on the edit page in task_billing and leaves the tasks row alone', function (): void {
    $task = taskBillingTask(taskBillingProject());
    $before = Task::query()->whereKey($task->getKey())->first()?->getAttributes();

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->set('data.billing_type', 'fixed_price')
        ->set('data.fixed_price', '12500,50')
        ->call('save')
        ->assertHasNoFormErrors();

    $row = taskBillingRow($task);

    expect($row)->not->toBeNull()
        ->and($row?->billing_type->value)->toBe('fixed_price')
        ->and($row?->fixed_price_minor)->toBe(1250050)
        ->and($row?->fixed_price_currency)->toBe('CZK')
        ->and($row?->hourly_rate_minor)->toBeNull()
        ->and($row?->estimate_seconds)->toBeNull();

    $after = Task::query()->whereKey($task->getKey())->first()?->getAttributes() ?? [];
    unset($before['updated_at'], $after['updated_at']);

    expect($after)->toBe($before);
});

it('shows the stored billing on the edit page again and removes the row when everything is back to inherit', function (): void {
    $task = taskBillingTask(taskBillingProject());

    app(UpdateTask::class)->handle($this->admin, $task, [
        'billing_type' => 'fixed_price',
        'fixed_price' => '99,90',
        'estimate_hours' => '1,5',
    ]);

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->assertSet('data.billing_type', 'fixed_price')
        ->assertSet('data.fixed_price', '99,90')
        ->assertSet('data.estimate_hours', '1,5')
        ->set('data.billing_type', 'inherit')
        ->set('data.fixed_price', '')
        ->set('data.estimate_hours', '')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(taskBillingRow($task))->toBeNull()
        ->and(TaskBilling::query()->count())->toBe(0);
});

it('keeps the row while any override is left, the note included', function (): void {
    $task = taskBillingTask(taskBillingProject());

    app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => 'non_billable', 'internal_note' => 'Example note']);
    app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => 'inherit']);

    expect(taskBillingRow($task)?->internal_note)->toBe('Example note');

    app(UpdateTask::class)->handle($this->admin, $task, ['internal_note' => '  ']);

    expect(taskBillingRow($task))->toBeNull();
});

it('shows a Partner no billing row, not even on a task of the own visible project', function (): void {
    $project = taskBillingProject('SEE', 'CZK', true);
    $task = taskBillingTask($project);
    app(UpdateTask::class)->handle($this->admin, $task, [
        'billing_type' => 'fixed_price',
        'fixed_price' => '1000',
        'internal_note' => Canary::canary('task_billing'),
    ]);

    expect(TaskBilling::query()->count())->toBe(1);

    $this->actingAs(Canary::partnerFor($project->client_id));

    expect(Task::query()->whereKey($task->getKey())->exists())->toBeTrue()
        ->and(TaskBilling::query()->count())->toBe(0)
        ->and(Task::query()->findOrFail($task->getKey())->billing)->toBeNull();
});
