<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The single upload entry point (DO-01, D-01, D-03): one documents row with exactly one media row on the
 * private disk, the owner's client and project resolved on the server. Every name and content is fictional.
 */

/**
 * A client-visible project ABC of a fresh client, written through the domain Action.
 */
function documentProject(?Client $client = null, string $key = 'ABC'): Project
{
    return app(CreateProject::class)->handle($client ?? Client::factory()->create(), [
        'name' => 'Example document project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('stores a file of a project as one document with one private object', function (): void {
    $project = documentProject();
    $file = DocumentFixtures::upload();
    $size = $file->getSize();

    $documents = app(AttachDocuments::class)->handle($this->admin, $project, [$file]);

    expect($documents)->toHaveCount(1);

    /** @var Document $document */
    $document = $documents->first();

    expect($document->owner_type)->toBe('project')
        ->and($document->owner_id)->toBe($project->getKey())
        ->and($document->client_id)->toBe($project->client_id)
        ->and($document->project_id)->toBe($project->getKey())
        ->and($document->uploaded_by_id)->toBe($this->admin->getKey())
        ->and($document->visible_to_client)->toBeTrue()
        ->and($document->mime_type)->toBe('text/plain')
        ->and($document->size_bytes)->toBe($size)
        ->and($document->extension)->toBe('txt');

    $media = app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->where('model_type', 'document')->get());

    expect($media)->toHaveCount(1)
        ->and($media->first()->model_id)->toBe($document->getKey())
        ->and($media->first()->collection_name)->toBe(Document::MEDIA_COLLECTION)
        ->and($media->first()->file_name)->toBe($document->getKey().'.txt')
        ->and(Storage::disk('local')->exists($media->first()->getPathRelativeToRoot()))->toBeTrue();

    expect(Document::query()->count())->toBe(1);
});

it('never carries user text in the stored object name', function (): void {
    $file = DocumentFixtures::upload(implode('', ['..', '/', 'Fictional Name', '.', 'TXT']));

    /** @var Document $document */
    $document = app(AttachDocuments::class)->handle($this->admin, documentProject(), [$file])->first();
    $media = app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->where('model_id', $document->getKey())->firstOrFail());

    expect($media->file_name)->toBe($document->getKey().'.txt')
        ->and($document->extension)->toBe('txt');
});

it('refuses a Partner and stores nothing', function (): void {
    $project = documentProject();
    $partner = Canary::partnerFor($project->client_id);
    $this->actingAs($partner);

    expect(fn () => app(AttachDocuments::class)->handle($partner, $project, [DocumentFixtures::upload()]))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($this->admin);

    expect(Document::query()->count())->toBe(0)
        ->and(app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->count()))->toBe(0);
});

/**
 * Attaches one fictional file and returns the single document.
 */
function attachOne(User $admin, ?Model $owner, ?bool $visible = null): Document
{
    /** @var Document */
    return app(AttachDocuments::class)->handle($admin, $owner, [DocumentFixtures::upload()], $visible)->firstOrFail();
}

it('gives a task file and a subtask file the client and project of the task and shows them by default', function (): void {
    $project = documentProject();
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);
    $subtask = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example subtask'], $task);

    foreach ([$task, $subtask] as $owner) {
        $document = attachOne($this->admin, $owner);

        expect($document->owner_type)->toBe('task')
            ->and($document->owner_id)->toBe($owner->getKey())
            ->and($document->client_id)->toBe($project->client_id)
            ->and($document->project_id)->toBe($project->getKey())
            ->and($document->visible_to_client)->toBeTrue();
    }
});

it('gives the file of a non-internal comment the project of its task and shows it by default', function (): void {
    $project = documentProject();
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example task']);
    $comment = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example comment</p>');

    $document = attachOne($this->admin, $comment);

    expect($document->owner_type)->toBe('task_comment')
        ->and($document->owner_id)->toBe($comment->getKey())
        ->and($document->client_id)->toBe($project->client_id)
        ->and($document->project_id)->toBe($project->getKey())
        ->and($document->visible_to_client)->toBeTrue();
});

it('stores the file of an internal comment hidden even when the caller asks for visible (D-20)', function (): void {
    $project = documentProject();
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example task']);
    $comment = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example internal note</p>', internal: true);

    expect(attachOne($this->admin, $comment)->visible_to_client)->toBeFalse()
        ->and(attachOne($this->admin, $comment, true)->visible_to_client)->toBeFalse();
});

it('gives a client file the client only and keeps it hidden even when the caller asks for visible (D-03)', function (): void {
    $client = Client::factory()->create();

    foreach ([null, true] as $visible) {
        $document = attachOne($this->admin, $client, $visible);

        expect($document->owner_type)->toBe('client')
            ->and($document->owner_id)->toBe($client->getKey())
            ->and($document->client_id)->toBe($client->getKey())
            ->and($document->project_id)->toBeNull()
            ->and($document->visible_to_client)->toBeFalse();
    }
});

it('stores an unattached file with no owner, client or project and hidden', function (): void {
    foreach ([null, true] as $visible) {
        $document = attachOne($this->admin, null, $visible);

        expect($document->owner_type)->toBeNull()
            ->and($document->owner_id)->toBeNull()
            ->and($document->client_id)->toBeNull()
            ->and($document->project_id)->toBeNull()
            ->and($document->visible_to_client)->toBeFalse();
    }
});

it('lets the Admin hide a project file at upload (D-03)', function (): void {
    expect(attachOne($this->admin, documentProject(), false)->visible_to_client)->toBeFalse();
});

it('refuses an owner of an unknown class and stores nothing', function (): void {
    $contact = Client::factory()->create()->contacts()->create(['name' => 'Example contact', 'email' => exampleEmail()]);

    expect(fn () => attachOne($this->admin, $contact))->toThrow(InvalidArgumentException::class);

    expect(Document::query()->count())->toBe(0);
});

it('keeps every documents setting in one config block', function (): void {
    expect(config('kokpit.documents.max_size_kb'))->toBe(10240)
        ->and(config('kokpit.documents.max_size_kb') * 1024)->toBe(config('media-library.max_file_size'))
        ->and(config('kokpit.documents.max_files_per_upload'))->toBe(10)
        ->and(config('kokpit.documents.signed_url_ttl_seconds'))->toBe(120)
        ->and(config('kokpit.documents.download_rate_limit_per_minute'))->toBe(120)
        ->and(config('kokpit.documents.archive.ttl_days'))->toBe(7)
        ->and(config('kokpit.documents.archive.max_files'))->toBe(200)
        ->and(config('kokpit.documents.archive.max_bytes'))->toBe(209715200);
});

/**
 * The field errors of a refused upload, or none when it was accepted.
 *
 * @param  array<array-key, mixed>  $files
 * @return array<string, list<string>>
 */
function uploadErrors(User $admin, ?Model $owner, array $files): array
{
    try {
        app(AttachDocuments::class)->handle($admin, $owner, $files);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * Nothing of a refused upload remains: no documents row, no media row, no stored object.
 */
function expectNothingStored(): void
{
    expect(Document::query()->count())->toBe(0)
        ->and(app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
}

it('stores a hostile file name as a clean NFC display name and lists it on its task', function (): void {
    $project = documentProject();
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example task']);
    $hostile = implode('', ['Zpra', "\u{0301}", 'va', mb_chr(7), mb_chr(0x202E), 'fdp', '.txt']);

    app(AttachDocuments::class)->handle($this->admin, $task, [DocumentFixtures::upload($hostile)]);

    $names = $task->documents()->pluck('name')->all();

    expect($names)->toBe(['Zprávafdp.txt'])
        ->and(Normalizer::isNormalized($names[0], Normalizer::FORM_C))->toBeTrue()
        ->and(preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $names[0]))->toBe(0);
});

it('refuses a forged string or nested array state under files and stores nothing', function (): void {
    $project = documentProject();
    $existing = 'livewire-tmp/'.implode('-', ['existing', 'note']).'.txt';

    foreach ([[$existing], [[$existing]], [DocumentFixtures::upload(), $existing]] as $files) {
        expect(uploadErrors($this->admin, $project, $files))->toBe(['files' => [__('documents.errors.invalid_upload')]]);
    }

    expectNothingStored();
});

it('refuses an empty list of files', function (): void {
    expect(uploadErrors($this->admin, documentProject(), []))->toBe(['files' => [__('documents.errors.empty_upload')]]);

    expectNothingStored();
});

it('lists the files of a client, project, task and comment through their documents relations', function (): void {
    $client = Client::factory()->create();
    $project = documentProject($client);
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example task']);
    $comment = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example comment</p>');

    $onClient = attachOne($this->admin, $client);
    $onProject = attachOne($this->admin, $project);
    $onTask = attachOne($this->admin, $task);
    $onComment = attachOne($this->admin, $comment);
    attachOne($this->admin, documentProject(key: 'XYZ'));

    $ids = static fn ($documents): array => $documents->pluck('id')->sort()->values()->all();

    expect($ids($client->documents))->toBe($ids(collect([$onClient, $onProject, $onTask, $onComment])))
        ->and($ids($project->documents))->toBe([$onProject->getKey()])
        ->and($ids($task->documents))->toBe([$onTask->getKey()])
        ->and($ids($comment->documents))->toBe([$onComment->getKey()]);
});

it('refuses a file over the configured size with a Czech message and stores a file exactly at the limit', function (): void {
    $project = documentProject();
    $limit = (int) config('kokpit.documents.max_size_kb') * 1024;

    expect(uploadErrors($this->admin, $project, [DocumentFixtures::upload('over-limit.txt', str_repeat('a', $limit + 1024))]))
        ->toBe(['files' => [__('documents.errors.too_large', ['max' => '10'])]])
        ->and(__('documents.errors.too_large', ['max' => '10']))->toContain('10 MB');

    expectNothingStored();

    expect(uploadErrors($this->admin, $project, [DocumentFixtures::upload('at-limit.txt', str_repeat('a', $limit))]))->toBe([])
        ->and(Document::query()->count())->toBe(1);
});

it('takes the size limit from the one config value', function (): void {
    config(['kokpit.documents.max_size_kb' => 2]);
    $project = documentProject();

    expect(uploadErrors($this->admin, $project, [DocumentFixtures::upload('small.txt', str_repeat('a', 3 * 1024))]))->toHaveKey('files')
        ->and(uploadErrors($this->admin, $project, [DocumentFixtures::upload('small.txt', str_repeat('a', 2 * 1024))]))->toBe([]);
});

it('refuses more files than the limit of one upload and stores none', function (): void {
    $files = [];

    for ($i = 0; $i < 11; $i++) {
        $files[] = DocumentFixtures::upload();
    }

    expect(uploadErrors($this->admin, documentProject(), $files))
        ->toBe(['files' => [__('documents.errors.too_many_files', ['max' => 10])]]);

    expectNothingStored();
});

it('refuses a disallowed extension in any segment of the name', function (string $name): void {
    $project = documentProject();

    expect(uploadErrors($this->admin, $project, [DocumentFixtures::upload($name)]))
        ->toBe(['files' => [__('documents.errors.extension_not_allowed', ['name' => $name])]]);

    expectNothingStored();
})->with(['report.php.pdf', 'Script.PHTML', 'archive.phar']);

it('accepts a plain document name', function (): void {
    expect(uploadErrors($this->admin, documentProject(), [DocumentFixtures::upload('smlouva.pdf')]))->toBe([])
        ->and(Document::query()->count())->toBe(1);
});

it('collects every refusal of a list before anything is written', function (): void {
    $files = [DocumentFixtures::upload('ok.txt'), DocumentFixtures::upload('bad.php'), DocumentFixtures::upload('worse.phtml')];

    expect(uploadErrors($this->admin, documentProject(), $files)['files'])->toHaveCount(2);

    expectNothingStored();
});

it('refuses to attach to an archived project, task, comment task or client and keeps the existing files (D-21)', function (): void {
    $client = Client::factory()->create();
    $project = documentProject($client);
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example task']);
    $comment = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example comment</p>');

    $before = [$project, $task, $client];
    foreach ($before as $owner) {
        attachOne($this->admin, $owner);
    }

    $stored = Storage::disk('local')->allFiles();
    expect($stored)->toHaveCount(3);

    $task->delete();
    $project->delete();
    $client->delete();

    foreach ([$project, $task, $comment, $client] as $owner) {
        expect(uploadErrors($this->admin, $owner, [DocumentFixtures::upload()]))
            ->toBe(['files' => [__('documents.errors.owner_archived')]]);
    }

    expect(Document::query()->count())->toBe(3)
        ->and(Storage::disk('local')->allFiles())->toBe($stored)
        ->and($project->documents()->count())->toBe(1)
        ->and($task->documents()->count())->toBe(1)
        ->and($client->documents()->count())->toBe(3);
});

it('leaves no row and no object when the third file of an upload fails, and rethrows the original error', function (): void {
    $project = documentProject();
    $created = 0;

    Media::creating(static function () use (&$created): void {
        if (++$created === 3) {
            throw new RuntimeException('forced media failure');
        }
    });

    $files = [DocumentFixtures::upload(), DocumentFixtures::upload(), DocumentFixtures::upload()];

    expect(fn () => app(AttachDocuments::class)->handle($this->admin, $project, $files))
        ->toThrow(RuntimeException::class, 'forced media failure');

    expectNothingStored();
});
