<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Actions\RenameDocument;
use App\Domain\Documents\Actions\SetDocumentVisibility;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Notifications\PartnerUploadedFilesNotification;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\CreatePartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskDocumentsRelationManager;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

/**
 * Re-points who requested a task, a test arrangement written as system.
 */
function puRequestedBy(Task $task, User $requester): Task
{
    app(PartnerContext::class)->runAsSystem(static function () use ($task, $requester): void {
        Task::query()->whereKey($task->id)->firstOrFail()->forceFill(['requester_id' => $requester->id])->save();
    });

    return Task::query()->withTrashed()->whereKey($task->id)->firstOrFail();
}

/**
 * A comment written through the domain Action as the author, then flagged internal when asked (a test arrangement:
 * the Action itself never lets a Partner write an internal comment).
 */
function puComment(User $author, Task $task, bool $internal = false): TaskComment
{
    test()->actingAs($author);

    $comment = app(AddTaskComment::class)->handle($author, $task, '<p>Example remark</p>');

    if ($internal) {
        app(PartnerContext::class)->runAsSystem(static function () use ($comment): void {
            TaskComment::query()->whereKey($comment->id)->firstOrFail()->forceFill(['is_internal' => true])->save();
        });
    }

    return $comment;
}

/**
 * The owners a Partner may not attach files to, by name. Each is built for the Partner of client A.
 *
 * @return array<string, Model|null>
 */
function puForbiddenOwners(object $c): array
{
    $other = Canary::partnerFor($c->clientA);
    $partnerB = Canary::partnerFor($c->clientB);
    $projectB = puProject($c->clientB);
    $hidden = puProject($c->clientA, ['client_visible' => false]);

    $done = puTask($c->partner, $c->projectA);
    test()->actingAs($c->admin);
    app(UpdateTask::class)->handle($c->admin, $done, ['status' => ProjectStatus::Done->value]);

    $archived = puTask($c->partner, $c->projectA);
    app(PartnerContext::class)->runAsSystem(static fn (): ?bool => Task::query()->whereKey($archived->id)->firstOrFail()->delete());

    $adminsTask = puTask($c->admin, $c->projectA);

    return [
        'task requested by the Admin' => $adminsTask,
        'task of another Partner of the client' => puTask($other, $c->projectA),
        'task of another client' => puTask($partnerB, $projectB),
        'task of a project that is not client-visible' => puRequestedBy(puTask($c->admin, $hidden), $c->partner),
        'done task' => $done,
        'archived task' => Task::query()->withTrashed()->whereKey($archived->id)->firstOrFail(),
        'project' => $c->projectA,
        'client' => app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::query()->findOrFail($c->clientA)),
        'no owner' => null,
        'internal comment of the Partner' => puComment($c->partner, puTask($c->partner, $c->projectA), true),
        'comment of the Admin' => puComment($c->admin, puTask($c->partner, $c->projectA)),
        'comment of another Partner' => puComment($other, puTask($other, $c->projectA)),
    ];
}

it('refuses a Partner every owner D-05 does not allow, stores nothing and sends no bell', function (): void {
    $owners = puForbiddenOwners($this);
    $this->actingAs($this->partner);

    // The owners that were NOT refused, by name: an empty list is the proof.
    $accepted = [];

    foreach ($owners as $label => $owner) {
        try {
            app(AttachDocuments::class)->handle($this->partner, $owner, [DocumentFixtures::upload()]);
            $accepted[] = $label;
        } catch (AuthorizationException) {
            // Refused, as D-05 demands.
        }
    }

    expect($accepted)->toBe([]);

    expect(app(PartnerContext::class)->runAsSystem(static fn (): int => Document::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(puBells($this->admin))->toBe([]);
});

it('stores a Partner file on the own open task visible even when the Partner asks for hidden', function (): void {
    $task = puTask($this->partner, $this->projectA);
    $this->actingAs($this->partner);

    $documents = app(AttachDocuments::class)->handle($this->partner, $task, [DocumentFixtures::upload()], false);

    expect($documents->every(fn (Document $d): bool => $d->visible_to_client === true && $d->uploaded_by_id === $this->partner->id))->toBeTrue()
        ->and(puBells($this->admin))->toHaveCount(1);
});

it('stores a Partner file on the own non-internal comment visible and names the task in the bell', function (): void {
    $task = puTask($this->partner, $this->projectA);
    $comment = puComment($this->partner, $task);

    $documents = app(AttachDocuments::class)->handle($this->partner, $comment, [DocumentFixtures::upload(), DocumentFixtures::upload()], false);
    $stored = app(PartnerContext::class)->runAsSystem(static fn () => Document::query()->get());

    expect($documents)->toHaveCount(2)
        ->and($stored->every(fn (Document $d): bool => $d->owner_type === 'task_comment' && $d->owner_id === $comment->id && $d->visible_to_client === true))->toBeTrue();

    $bells = puBells($this->admin);

    expect($bells)->toHaveCount(1)
        ->and(json_encode($bells[0]->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->toContain($task->reference);
});

it('refuses the uploading Partner a rename and a visibility change', function (): void {
    $task = puTask($this->partner, $this->projectA);
    $this->actingAs($this->partner);
    $document = app(AttachDocuments::class)->handle($this->partner, $task, [DocumentFixtures::upload()])->firstOrFail();

    expect(fn () => app(RenameDocument::class)->handle($this->partner, $document, 'renamed-example.txt', null))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetDocumentVisibility::class)->handle($this->partner, $document, false))
        ->toThrow(AuthorizationException::class);

    $fresh = app(PartnerContext::class)->runAsSystem(static fn (): Document => Document::query()->findOrFail($document->id));

    expect($fresh->name)->toBe($document->name)->and($fresh->visible_to_client)->toBeTrue();
});

it('applies the size limit to a Partner exactly as to the Admin', function (): void {
    $task = puTask($this->partner, $this->projectA);
    $tooBig = static fn (): UploadedFile => UploadedFile::fake()->create('big-example.txt', (int) config('kokpit.documents.max_size_kb') + 1);

    $this->actingAs($this->admin);
    $adminError = null;

    try {
        app(AttachDocuments::class)->handle($this->admin, $task, [$tooBig()]);
    } catch (ValidationException $e) {
        $adminError = $e->errors();
    }

    $this->actingAs($this->partner);
    $partnerError = null;

    try {
        app(AttachDocuments::class)->handle($this->partner, $task, [$tooBig()]);
    } catch (ValidationException $e) {
        $partnerError = $e->errors();
    }

    expect($adminError)->not->toBeNull()
        ->and($partnerError)->toBe($adminError)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Document::query()->count()))->toBe(0)
        ->and(puBells($this->admin))->toBe([]);
});

/**
 * The Soubory tab of a task as the signed-in viewer sees it (the task is read through the scoped query).
 */
function puTab(User $viewer, Task $task): Testable
{
    test()->actingAs($viewer);

    $seen = Task::query()->where('reference', $task->reference)->firstOrFail();

    return Livewire::test(PartnerTaskDocumentsRelationManager::class, ['ownerRecord' => $seen, 'pageClass' => ViewPartnerTask::class]);
}

/**
 * The media rows of a document, read as system because Media is closed to Partners.
 *
 * @return list<string>
 */
function puMedia(string $documentId): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => Media::query()->where('model_id', $documentId)->pluck('id')->map(strval(...))->all(),
    );
}

it('lets a Partner delete an own file on an open task after the confirmation, object included', function (): void {
    $task = puTask($this->partner, $this->projectA);
    $this->actingAs($this->partner);
    $document = app(AttachDocuments::class)->handle($this->partner, $task, [DocumentFixtures::upload('own-example.txt')])->firstOrFail();

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    $tab = puTab($this->partner, $task)
        ->assertActionExists(TestAction::make('delete')->table($document), static fn (Action $action): bool => $action->isConfirmationRequired())
        ->mountAction(TestAction::make('delete')->table($document));

    // Opening the dialog deletes nothing and the dialog names the file.
    expect((string) $tab->instance()->getMountedAction()?->getModalDescription())->toBe(__('documents.actions.delete_description', ['name' => 'own-example.txt']))
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): bool => Document::query()->whereKey($document->id)->exists()))->toBeTrue();

    $tab->callMountedAction();

    expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => Document::query()->whereKey($document->id)->exists()))->toBeFalse()
        ->and(puMedia($document->id))->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $tab->assertNotified(__('documents.notifications.deleted'));
});

it('offers a Partner the delete only for the own file of an open task', function (): void {
    $task = puTask($this->partner, $this->projectA);
    $other = Canary::partnerFor($this->clientA);

    $this->actingAs($this->admin);
    $adminFile = app(AttachDocuments::class)->handle($this->admin, $task, [DocumentFixtures::upload('admin-example.txt')])->firstOrFail();
    $otherFile = app(AttachDocuments::class)->handle($this->admin, $task, [DocumentFixtures::upload('other-example.txt')])->firstOrFail();
    app(PartnerContext::class)->runAsSystem(static function () use ($otherFile, $other): void {
        Document::query()->whereKey($otherFile->id)->firstOrFail()->forceFill(['uploaded_by_id' => $other->id])->save();
    });

    $this->actingAs($this->partner);
    $own = app(AttachDocuments::class)->handle($this->partner, $task, [DocumentFixtures::upload('mine-example.txt')])->firstOrFail();

    puTab($this->partner, $task)
        ->assertActionVisible(TestAction::make('delete')->table($own))
        ->assertActionHidden(TestAction::make('delete')->table($adminFile))
        ->assertActionHidden(TestAction::make('delete')->table($otherFile));

    // A forged call is refused by the Action as well, and nothing is deleted.
    foreach ([$adminFile, $otherFile] as $foreign) {
        expect(fn () => app(DeleteDocument::class)->handle($this->partner, $foreign))->toThrow(AuthorizationException::class);
    }

    expect(app(PartnerContext::class)->runAsSystem(static fn (): int => Document::query()->count()))->toBe(3);
});

it('takes the delete away from the Partner once the task is done or archived, and keeps it for the Admin', function (): void {
    $task = puTask($this->partner, $this->projectA);
    $this->actingAs($this->partner);
    $first = app(AttachDocuments::class)->handle($this->partner, $task, [DocumentFixtures::upload('first-example.txt')])->firstOrFail();
    $second = app(AttachDocuments::class)->handle($this->partner, $task, [DocumentFixtures::upload('second-example.txt')])->firstOrFail();

    puTab($this->partner, $task)->assertActionVisible(TestAction::make('delete')->table($first));

    $this->actingAs($this->admin);
    app(UpdateTask::class)->handle($this->admin, $task, ['status' => ProjectStatus::Done->value]);

    puTab($this->partner, $task)->assertActionHidden(TestAction::make('delete')->table($first));
    expect(fn () => app(DeleteDocument::class)->handle($this->partner, $first))->toThrow(AuthorizationException::class);

    // An archived task is gone for the Partner altogether, and its files cannot be deleted either.
    app(PartnerContext::class)->runAsSystem(static fn (): ?bool => Task::query()->whereKey($task->id)->firstOrFail()->delete());
    $this->actingAs($this->partner);
    expect(fn () => app(DeleteDocument::class)->handle($this->partner, $second))->toThrow(AuthorizationException::class);

    // The Admin deletes anything, a done or archived task included.
    $this->actingAs($this->admin);
    app(DeleteDocument::class)->handle($this->admin, $first);
    app(DeleteDocument::class)->handle($this->admin, $second);

    expect(app(PartnerContext::class)->runAsSystem(static fn (): int => Document::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('lets the Admin delete the file of a Partner on a done task', function (): void {
    $task = puTask($this->partner, $this->projectA);
    $this->actingAs($this->partner);
    $document = app(AttachDocuments::class)->handle($this->partner, $task, [DocumentFixtures::upload('finished-example.txt')])->firstOrFail();

    $this->actingAs($this->admin);
    app(UpdateTask::class)->handle($this->admin, $task, ['status' => ProjectStatus::Done->value]);
    app(DeleteDocument::class)->handle($this->admin, $document);

    expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => Document::query()->whereKey($document->id)->exists()))->toBeFalse()
        ->and(puMedia($document->id))->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
