<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Notifications\PartnerUploadedFilesNotification;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * Files on the comments of a task, for the Admin and for a Partner (DO-01, D-02, D-04, D-05, D-20): a separate
 * upload field in the comment form, the comment and its files saved together or not at all, the files listed
 * under each comment with a checked download. Every name and content is fictional.
 */

/**
 * A client-visible project of the client, written as system through the domain Action.
 */
function caProject(string $clientId): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));
}

/**
 * A task of the project created by the actor, written as system.
 */
function caTask(User $actor, Project $project): Task
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): Task => app(CreateTask::class)->handle($actor, $project, ['title' => Canary::canary('task')]),
    );
}

/**
 * A fictional upload that Livewire's test upload can send.
 */
function caUpload(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, implode(' ', ['Fictional', 'file', 'content', Str::random(12)]));
}

/**
 * Puts the files into the comment form one at a time, like the browser on the private bucket does.
 *
 * @param  list<UploadedFile>  $files
 */
function caAttach(Testable $thread, array $files): Testable
{
    foreach ($files as $file) {
        $thread->upload('commentData.files.'.Str::uuid()->toString(), [$file]);
    }

    return $thread;
}

/**
 * The comment thread of the Admin's task page.
 */
function caAdminThread(Task $task): Testable
{
    return Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class]);
}

/**
 * The comment thread of the Partner's task page; the task is read through the scoped query, like the page does.
 */
function caPartnerThread(User $partner, Task $task): Testable
{
    test()->actingAs($partner);

    $seen = Task::query()->where('reference', $task->reference)->firstOrFail();

    return Livewire::test(PartnerTaskCommentsRelationManager::class, ['ownerRecord' => $seen, 'pageClass' => ViewPartnerTask::class]);
}

/**
 * Every document, read as system.
 *
 * @return Collection<int, Document>
 */
function caDocuments(): Collection
{
    return app(PartnerContext::class)->runAsSystem(static fn () => Document::query()->orderBy('name')->get());
}

/**
 * The number of comments, read as system.
 */
function caCommentCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => TaskComment::query()->count());
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->partner = Canary::partnerFor($this->clientA);
    $this->project = caProject($this->clientA);
    $this->task = caTask($this->admin, $this->project);
});

it('saves the Admin comment with its file, lists the file under the comment and hands out a signed download', function (): void {
    $this->actingAs($this->admin);
    $name = implode('-', ['spec', 'example']).'.txt';

    $thread = caAdminThread($this->task)->set('commentData.body', '<p>Example remark with a file</p>');

    caAttach($thread, [caUpload($name)])
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = app(PartnerContext::class)->runAsSystem(static fn (): TaskComment => TaskComment::query()->firstOrFail());
    $documents = caDocuments();

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->owner_type)->toBe('task_comment')
        ->and($documents[0]->owner_id)->toBe($comment->id)
        ->and($documents[0]->visible_to_client)->toBeTrue()
        ->and($documents[0]->uploaded_by_id)->toBe($this->admin->id);

    // The file name is under the comment, and the page never carries a signed link.
    $html = $thread->html();

    expect($html)->toContain($name)
        ->and($html)->not->toContain('signature=');

    // The link is minted at click time and the answer is a redirect to the signed file route.
    $thread->call('downloadCommentDocument', $documents[0]->id)
        ->assertRedirectContains('signature=')
        ->assertRedirectContains($documents[0]->id);
});

it('saves neither the comment nor the file when the media write fails', function (): void {
    $this->actingAs($this->admin);

    Media::creating(static function (): void {
        throw new RuntimeException('forced media failure');
    });

    $thread = caAdminThread($this->task)->set('commentData.body', '<p>Example remark that must not stay</p>');

    caAttach($thread, [caUpload('lost-example.txt')]);

    expect(fn () => $thread->call('addComment'))->toThrow(RuntimeException::class, 'forced media failure');

    expect(caCommentCount())->toBe(0)
        ->and(caDocuments())->toHaveCount(0)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Media::query()->count()))->toBe(0);
});

it('saves a comment without files as before', function (): void {
    $this->actingAs($this->admin);

    caAdminThread($this->task)
        ->set('commentData.body', '<p>Example plain remark</p>')
        ->call('addComment')
        ->assertHasNoErrors();

    expect(caCommentCount())->toBe(1)->and(caDocuments())->toHaveCount(0);
});

/**
 * The upload bells of the user, read as system.
 *
 * @return list<DatabaseNotification>
 */
function caBells(User $user): array
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->id)
        ->where('type', PartnerUploadedFilesNotification::class)
        ->orderBy('created_at')
        ->get()
        ->all();
}

/**
 * A comment of the author on the task, written through the domain Action, with one file stored through the
 * upload Action (visible or hidden by the owner's own rule).
 *
 * @return array{0: TaskComment, 1: Document}
 */
function caCommentWithFile(User $author, Task $task, string $fileName, bool $internal = false): array
{
    test()->actingAs($author);

    $comment = app(AddTaskComment::class)->handle($author, $task, '<p>Example remark</p>', $internal);
    $document = app(AttachDocuments::class)->handle($author, $comment, [DocumentFixtures::upload($fileName)])->firstOrFail();

    return [$comment, $document];
}

it('lets a Partner comment with a file: not internal, visible, uploaded by the Partner, one bell for the Admin', function (): void {
    $task = caTask($this->partner, $this->project);
    $name = implode('-', ['partner', 'example']).'.txt';

    $thread = caPartnerThread($this->partner, $task)->set('commentData.body', '<p>Example remark from the client</p>');

    caAttach($thread, [caUpload($name)])
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = app(PartnerContext::class)->runAsSystem(static fn (): TaskComment => TaskComment::query()->firstOrFail());
    $documents = caDocuments();

    expect($comment->is_internal)->toBeFalse()
        ->and($comment->author_id)->toBe($this->partner->id)
        ->and($documents)->toHaveCount(1)
        ->and($documents[0]->owner_id)->toBe($comment->id)
        ->and($documents[0]->visible_to_client)->toBeTrue()
        ->and($documents[0]->uploaded_by_id)->toBe($this->partner->id);

    // The Partner sees the file under the own comment and can fetch it.
    expect($thread->html())->toContain($name);
    $thread->call('downloadCommentDocument', $documents[0]->id)->assertRedirectContains('signature=');

    // One upload bell with the count, besides whatever the comment itself announced.
    $bells = caBells($this->admin);

    expect($bells)->toHaveCount(1)
        ->and(json_encode($bells[0]->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->toContain($task->reference)->not->toContain($name)
        ->and(caBells($this->partner))->toBe([]);
});

it('stores the file of an internal Admin comment hidden and shows a Partner neither the comment nor the file', function (): void {
    $task = caTask($this->partner, $this->project);
    $this->actingAs($this->admin);
    $secret = implode('-', ['internal', 'example']).'.txt';
    $open = implode('-', ['shared', 'example']).'.txt';

    $thread = caAdminThread($task)
        ->set('commentData.body', '<p>Example internal remark</p>')
        ->set('commentData.is_internal', true);

    caAttach($thread, [caUpload($secret)])->call('addComment')->assertHasNoErrors();

    $thread->set('commentData.body', '<p>Example shared remark</p>')->set('commentData.is_internal', false);
    caAttach($thread, [caUpload($open)])->call('addComment')->assertHasNoErrors();

    $documents = caDocuments()->keyBy('name');

    expect($documents)->toHaveCount(2)
        ->and($documents[$secret]->visible_to_client)->toBeFalse()
        ->and($documents[$open]->visible_to_client)->toBeTrue();

    // The Admin sees both files and the internal badge.
    $adminHtml = caAdminThread($task)->html();

    expect($adminHtml)->toContain($secret)->toContain($open)->toContain(__('kokpit.tasks.comments.badges.internal'));

    // The Partner sees the shared one only: neither the internal comment nor its file name is anywhere.
    $partnerThread = caPartnerThread($this->partner, $task);

    expect($partnerThread->html())->toContain($open)->not->toContain($secret)->not->toContain('internal remark');

    // A refusal ends the component's round trip, so every call gets its own thread.
    caPartnerThread($this->partner, $task)->call('downloadCommentDocument', $documents[$secret]->id)->assertNotFound();
    caPartnerThread($this->partner, $task)->call('previewCommentDocument', $documents[$secret]->id)->assertNotFound();
});

it('answers 404 to a Partner for a visible file of another task, of another client and for a malformed id', function (): void {
    $task = caTask($this->partner, $this->project);
    $otherTask = caTask($this->admin, $this->project);
    $projectB = caProject($this->clientB);
    $taskB = caTask($this->admin, $projectB);

    [, $ownTaskFile] = caCommentWithFile($this->admin, $task, 'mine-example.txt');
    [, $otherTaskFile] = caCommentWithFile($this->admin, $otherTask, 'other-task-example.txt');
    [, $otherClientFile] = caCommentWithFile($this->admin, $taskB, 'other-client-example.txt');

    expect($otherTaskFile->visible_to_client)->toBeTrue()->and($otherClientFile->visible_to_client)->toBeTrue();

    // The control: the own task's file works, so the 404s below are about the id, not the setup.
    caPartnerThread($this->partner, $task)->call('downloadCommentDocument', $ownTaskFile->id)->assertRedirectContains('signature=');

    // A refusal ends the component's round trip, so every call gets its own thread.
    foreach ([$otherTaskFile->id, $otherClientFile->id, 'not-a-uuid', '../../etc/passwd', Str::uuid()->toString()] as $forged) {
        caPartnerThread($this->partner, $task)->call('downloadCommentDocument', $forged)->assertNotFound();
        caPartnerThread($this->partner, $task)->call('previewCommentDocument', $forged)->assertNotFound();
    }
});

it('keeps the file fields off the escalation and description editors of the Partner task page', function (): void {
    $task = caTask($this->partner, $this->project);
    $this->actingAs($this->partner);

    foreach (['escalate' => 'comment', 'editDescription' => 'description'] as $action => $editor) {
        Livewire::test(ViewPartnerTask::class, ['record' => $task->reference])
            ->mountAction($action)
            ->assertSchemaComponentExists($editor, 'mountedActionSchema0')
            ->assertSchemaComponentDoesNotExist('files', 'mountedActionSchema0');
    }
});
