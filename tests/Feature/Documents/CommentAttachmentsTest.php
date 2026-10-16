<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
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
