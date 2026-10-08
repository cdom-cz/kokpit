<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Billing\BillingSource;
use App\Domain\Tasks\Billing\TaskBillingResolver;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
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
