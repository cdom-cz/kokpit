<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Notifications\PartnerUploadedFilesNotification;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\CreatePartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskDocumentsRelationManager;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The Partner side of uploads (DO-01, DO-03, D-05): files handed over while raising a task, the explicit
 * attach and delete grants, and the one Admin bell per submission. Every name and content is fictional.
 */

/**
 * A client-visible project of the client, written as system through the domain Action.
 *
 * @param  array<string, mixed>  $attributes
 */
function puProject(string $clientId, array $attributes = []): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
        ...$attributes,
    ]));
}

/**
 * A task of the project created by the actor, written as system.
 */
function puTask(User $actor, Project $project): Task
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): Task => app(CreateTask::class)->handle($actor, $project, ['title' => Canary::canary('task')]),
    );
}

/**
 * A fictional upload that Livewire's test upload can send.
 */
function puUpload(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, implode(' ', ['Fictional', 'file', 'content', Str::random(12)]));
}

/**
 * Sends the files to the create-task form one by one, like the browser on the private bucket does.
 *
 * @param  list<UploadedFile>  $files
 */
function puAttachToForm(Testable $form, array $files): Testable
{
    foreach ($files as $file) {
        $form->upload('data.files.'.Str::uuid()->toString(), [$file]);
    }

    return $form;
}

/**
 * The Admin bells about Partner uploads, read as system.
 *
 * @return list<DatabaseNotification>
 */
function puBells(User $user): array
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->id)
        ->where('type', PartnerUploadedFilesNotification::class)
        ->orderBy('created_at')
        ->get()
        ->all();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->partner = Canary::partnerFor($this->clientA);
    $this->projectA = puProject($this->clientA);
});

it('lets a Partner raise a task with two files, shows them on the task tab and tells the Admin once', function (): void {
    $this->actingAs($this->partner);
    $title = Canary::canary('raised');
    $names = [implode('-', ['first', 'example']).'.txt', implode('-', ['second', 'example']).'.txt'];

    $form = Livewire::test(CreatePartnerTask::class)
        ->fillForm(['project_id' => $this->projectA->id, 'title' => $title]);

    puAttachToForm($form, [puUpload($names[0]), puUpload($names[1])])
        ->call('create')
        ->assertHasNoFormErrors();

    $task = app(PartnerContext::class)->runAsSystem(static fn (): Task => Task::query()->where('title', $title)->firstOrFail());
    $documents = app(PartnerContext::class)->runAsSystem(static fn () => Document::query()->orderBy('name')->get());

    expect($task->requester_id)->toBe($this->partner->id)
        ->and($documents)->toHaveCount(2)
        ->and($documents->pluck('name')->all())->toBe($names)
        ->and($documents->every(fn (Document $d): bool => $d->owner_type === 'task' && $d->owner_id === $task->id))->toBeTrue()
        ->and($documents->every(fn (Document $d): bool => $d->visible_to_client === true && $d->uploaded_by_id === $this->partner->id))->toBeTrue();

    // Visible to the Partner at once, on the task's Soubory tab.
    $ownTask = Task::query()->where('reference', $task->reference)->firstOrFail();

    Livewire::test(PartnerTaskDocumentsRelationManager::class, ['ownerRecord' => $ownTask, 'pageClass' => ViewPartnerTask::class])
        ->assertCanSeeTableRecords($documents)
        ->assertSee($names[0])
        ->assertSee($names[1]);

    // One bell for the Admin with the Partner, the reference and the count, and no file name.
    $bells = puBells($this->admin);

    expect($bells)->toHaveCount(1);

    $data = json_encode($bells[0]->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($data)->toContain($this->partner->name)
        ->toContain($task->reference)
        ->toContain('2')
        ->toContain('/admin/tasks/'.$task->reference)
        ->not->toContain($names[0])
        ->not->toContain($names[1])
        ->and(puBells($this->partner))->toBe([]);
});

it('creates a task without files as before and sends no upload bell', function (): void {
    $this->actingAs($this->partner);

    Livewire::test(CreatePartnerTask::class)
        ->fillForm(['project_id' => $this->projectA->id, 'title' => Canary::canary('plain')])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(app(PartnerContext::class)->runAsSystem(static fn (): int => Task::query()->count()))->toBe(1)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Document::query()->count()))->toBe(0)
        ->and(puBells($this->admin))->toBe([]);
});

it('creates neither the task nor a file nor a bell when the second file cannot be stored', function (): void {
    $this->actingAs($this->partner);
    $created = 0;

    Media::creating(static function () use (&$created): void {
        if (++$created === 2) {
            throw new RuntimeException('forced media failure');
        }
    });

    $form = Livewire::test(CreatePartnerTask::class)
        ->fillForm(['project_id' => $this->projectA->id, 'title' => Canary::canary('rolled')]);

    puAttachToForm($form, [puUpload('one-example.txt'), puUpload('two-example.txt')]);

    expect(fn () => $form->call('create'))->toThrow(RuntimeException::class, 'forced media failure');

    $context = app(PartnerContext::class);

    expect($context->runAsSystem(static fn (): int => Task::query()->withTrashed()->count()))->toBe(0)
        ->and($context->runAsSystem(static fn (): int => Document::query()->count()))->toBe(0)
        ->and($context->runAsSystem(static fn (): int => Media::query()->count()))->toBe(0)
        ->and(puBells($this->admin))->toBe([]);
});
