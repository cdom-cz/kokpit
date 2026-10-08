<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\RawSql;

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
    return app(CreateProject::class)->handle(Client::factory()->create(['currency' => $currency, 'hourly_rate' => Money::ofMinor(0, $currency)]), [
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
 * The messages of the validation error of the update, keyed by field, or an empty array.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function taskBillingErrors(Task $task, array $data): array
{
    try {
        app(UpdateTask::class)->handle(test()->admin, $task, $data);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
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

it('refuses an amount with too many decimals, a grouping character or a minus sign on the field, and stores nothing', function (string $field, string $text): void {
    $task = taskBillingTask(taskBillingProject());

    $errors = taskBillingErrors($task, ['billing_type' => 'hourly', $field => $text]);

    expect(array_keys($errors))->toBe([$field])
        ->and(taskBillingRow($task))->toBeNull();
})->with([
    'rate with three decimals' => ['hourly_rate', '1,234'],
    'rate with a grouping space' => ['hourly_rate', '1 000'],
    'rate negative' => ['hourly_rate', '-5'],
    'price with three decimals' => ['fixed_price', '1,234'],
    'price with a grouping space' => ['fixed_price', '1 000'],
    'price negative' => ['fixed_price', '-5'],
]);

it('rounds nothing: the decimals a currency allows are kept to the minor unit', function (): void {
    $czk = taskBillingTask(taskBillingProject('CZK', 'CZK'));
    $jpy = taskBillingTask(taskBillingProject('JPY', 'JPY'));

    app(UpdateTask::class)->handle($this->admin, $czk, ['hourly_rate' => '850,05']);

    expect(taskBillingRow($czk)?->hourly_rate_minor)->toBe(85005)
        ->and(taskBillingErrors($jpy, ['hourly_rate' => '850,5']))->toHaveKey('hourly_rate')
        ->and(taskBillingRow($jpy))->toBeNull();

    app(UpdateTask::class)->handle($this->admin, $jpy, ['hourly_rate' => '850']);

    expect(taskBillingRow($jpy)?->hourly_rate_minor)->toBe(850)
        ->and(taskBillingRow($jpy)?->hourly_rate_currency)->toBe('JPY');
});

it('stores a fixed price of 0 and an estimate of 0 hours, and converts hours to seconds', function (): void {
    $task = taskBillingTask(taskBillingProject());

    app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => 'fixed_price', 'fixed_price' => '0', 'estimate_hours' => '0']);

    $row = taskBillingRow($task);

    expect($row?->fixed_price_minor)->toBe(0)
        ->and($row?->fixed_price_currency)->toBe('CZK')
        ->and($row?->estimate_seconds)->toBe(0);

    app(UpdateTask::class)->handle($this->admin, $task, ['estimate_hours' => '1,5']);

    expect(taskBillingRow($task)?->estimate_seconds)->toBe(5400)
        ->and(taskBillingRow($task)?->fixed_price_minor)->toBe(0);
});

it('refuses an estimate above the integer column range or with a bad shape on the estimate field', function (string $text): void {
    $task = taskBillingTask(taskBillingProject());

    expect(array_keys(taskBillingErrors($task, ['estimate_hours' => $text])))->toBe(['estimate_hours'])
        ->and(taskBillingRow($task))->toBeNull();
})->with([
    'above the column range' => ['600000'],
    'too many decimals' => ['1,234'],
    'negative' => ['-1'],
    'text' => ['abc'],
]);

it('requires a price for billing type fixed price, as a field error and as a database check', function (): void {
    $task = taskBillingTask(taskBillingProject());

    expect(array_keys(taskBillingErrors($task, ['billing_type' => 'fixed_price', 'fixed_price' => ''])))->toBe(['fixed_price'])
        ->and(array_keys(taskBillingErrors($task, ['billing_type' => 'fixed_price'])))->toBe(['fixed_price'])
        ->and(taskBillingRow($task))->toBeNull();

    RawSql::expectSqlState('23514', static fn () => DB::table('task_billing')->insert([
        'task_id' => $task->getKey(),
        'billing_type' => 'fixed_price',
    ]));
});

it('refuses a price that is later emptied while the type stays fixed price', function (): void {
    $task = taskBillingTask(taskBillingProject());
    app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => 'fixed_price', 'fixed_price' => '10']);

    expect(array_keys(taskBillingErrors($task, ['fixed_price' => ''])))->toBe(['fixed_price'])
        ->and(taskBillingRow($task)?->fixed_price_minor)->toBe(1000);
});

it('creates an override row for non-billable without any value', function (): void {
    $task = taskBillingTask(taskBillingProject());

    app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => 'non_billable']);

    $row = taskBillingRow($task);

    expect($row?->billing_type->value)->toBe('non_billable')
        ->and($row?->hourly_rate_minor)->toBeNull()
        ->and($row?->fixed_price_minor)->toBeNull()
        ->and($row?->estimate_seconds)->toBeNull();
});

it('lets the type and the rate inherit independently: a rate with type inherit is an override', function (): void {
    $task = taskBillingTask(taskBillingProject());

    app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => 'inherit', 'hourly_rate' => '700']);

    $row = taskBillingRow($task);

    expect($row?->billing_type->value)->toBe('inherit')
        ->and($row?->hourly_rate_minor)->toBe(70000)
        ->and($row?->fixed_price_minor)->toBeNull();
});

it('leaves the stored billing alone when the data names no billing key, and the type when it is null', function (): void {
    $task = taskBillingTask(taskBillingProject());
    app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => 'non_billable', 'estimate_hours' => '2']);

    app(UpdateTask::class)->handle($this->admin, $task, ['title' => 'Example renamed task']);
    app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => null, 'estimate_hours' => '3']);

    $row = taskBillingRow($task);

    expect($row?->billing_type->value)->toBe('non_billable')
        ->and($row?->estimate_seconds)->toBe(10800);
});

it('refuses an unknown billing type on the billing type field', function (mixed $value): void {
    $task = taskBillingTask(taskBillingProject());

    expect(array_keys(taskBillingErrors($task, ['billing_type' => $value])))->toBe(['billing_type'])
        ->and(taskBillingRow($task))->toBeNull();
})->with([
    'unknown value' => ['free'],
    'empty text' => [''],
    'not a text' => [['hourly']],
]);

it('refuses a Partner that calls UpdateTask with billing keys', function (): void {
    $project = taskBillingProject('PRT', 'CZK', true);
    $task = taskBillingTask($project);
    $partner = Canary::partnerFor($project->client_id);

    expect(fn () => app(UpdateTask::class)->handle($partner, $task, ['billing_type' => 'non_billable']))
        ->toThrow(AuthorizationException::class)
        ->and(taskBillingRow($task))->toBeNull();
});

it('refuses a malformed billing row in the database', function (): void {
    $task = taskBillingTask(taskBillingProject());
    $insert = static fn (array $values): Closure => static fn () => DB::table('task_billing')->insert([
        'task_id' => $task->getKey(),
        ...$values,
    ]);

    RawSql::expectSqlState('23514', $insert(['billing_type' => 'free']));
    RawSql::expectSqlState('23514', $insert(['hourly_rate_minor' => 100]));
    RawSql::expectSqlState('23514', $insert(['fixed_price_minor' => -1, 'fixed_price_currency' => 'CZK']));
    RawSql::expectSqlState('23514', $insert(['hourly_rate_minor' => 1, 'hourly_rate_currency' => 'czk']));
    RawSql::expectSqlState('23514', $insert(['hourly_rate_minor' => 1, 'hourly_rate_currency' => 'CZK', 'fixed_price_minor' => 1, 'fixed_price_currency' => 'EUR']));
    RawSql::expectSqlState('23514', $insert(['estimate_seconds' => -1]));
    RawSql::expectSqlState('23503', static fn () => DB::table('task_billing')->insert(['task_id' => (string) Str::uuid()]));

    RawSql::expectAllowed($insert(['billing_type' => 'fixed_price', 'fixed_price_minor' => 0, 'fixed_price_currency' => 'CZK', 'estimate_seconds' => 0]));
    RawSql::expectSqlState('23505', $insert([]));
});
