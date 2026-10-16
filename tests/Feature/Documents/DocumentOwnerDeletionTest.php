<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\RestoreClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\RestoreTask;
use App\Domain\Tasks\Models\TaskComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * No document owner can be hard-deleted behind the files' back (DO-03, D-09 as amended by D-21, research
 * Pitfall 6). Clients, projects and tasks are only archived, comments are append-only and a transaction
 * deletes its receipts itself, so a stored object is never orphaned by an owner going away. The guard fails
 * when a document owner gains a hard-delete path; the archive and restore proofs show that archiving never
 * costs a row, a media row or a stored object. Every name and content is fictional.
 */

/**
 * The lines of the calls that hard-delete a model in PHP source: `->forceDelete(`, `::forceDelete(`,
 * `->forceDestroy(` and `::forceDestroy(`, also through `?->`. Comments and strings are ignored because the scan
 * works on tokens, and a method declaration (`function forceDelete(`, as the policy abilities of KokpitPolicy)
 * is not a call.
 *
 * @return list<int>
 */
function ownerDeletionForceDeleteCalls(string $source): array
{
    $tokens = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $tokens[] = is_array($token)
            ? ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]]
            : ['id' => null, 'text' => $token, 'line' => $tokens === [] ? 1 : $tokens[array_key_last($tokens)]['line']];
    }

    $lines = [];

    foreach ($tokens as $index => $token) {
        if (! in_array(strtolower($token['text']), ['forcedelete', 'forcedestroy'], true) || ($tokens[$index + 1]['text'] ?? '') !== '(') {
            continue;
        }

        $previous = $tokens[$index - 1] ?? null;

        if ($previous !== null && in_array($previous['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            $lines[] = $token['line'];
        }
    }

    return $lines;
}

/**
 * The lines of the calls that delete a row (`->delete(`, `->forceDelete(`, `::destroy(`, `->destroy(`) in a source
 * that also names comments, either the TaskComment class or a `comments` relation. Used on the Task Actions,
 * where nothing may remove a comment.
 *
 * @return list<int>
 */
function ownerDeletionCommentDeletes(string $source): array
{
    $tokens = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $tokens[] = is_array($token)
            ? ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]]
            : ['id' => null, 'text' => $token, 'line' => $tokens === [] ? 1 : $tokens[array_key_last($tokens)]['line']];
    }

    $namesComments = false;

    foreach ($tokens as $token) {
        if (in_array(strtolower($token['text']), ['taskcomment', 'comments'], true) || str_ends_with($token['text'], '\\TaskComment')) {
            $namesComments = true;
        }
    }

    if (! $namesComments) {
        return [];
    }

    $lines = [];

    foreach ($tokens as $index => $token) {
        if (! in_array(strtolower($token['text']), ['delete', 'forcedelete', 'destroy', 'forcedestroy'], true) || ($tokens[$index + 1]['text'] ?? '') !== '(') {
            continue;
        }

        $previous = $tokens[$index - 1] ?? null;

        if ($previous !== null && in_array($previous['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            $lines[] = $token['line'];
        }
    }

    return $lines;
}

/**
 * The PHP files under a directory of the application, as path => source.
 *
 * @return array<string, string>
 */
function ownerDeletionSources(string $directory): array
{
    $sources = [];
    $base = str_replace(DIRECTORY_SEPARATOR, '/', base_path()).'/';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $sources[str_replace($base, '', str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()))] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

/**
 * A client-visible project of the given client, written through the domain Action.
 */
function ownerDeletionProject(Client $client): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example owner-deletion project',
        'key' => 'ODL',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * Asserts, as the Admin, that every document still has its row, exactly one media row and its stored object,
 * and that nothing else was stored or removed.
 */
function ownerDeletionAssertIntact(Document ...$documents): void
{
    foreach ($documents as $document) {
        expect(Document::query()->whereKey($document->getKey())->exists())->toBeTrue();

        $media = app(PartnerContext::class)->runAsSystem(
            static fn () => Media::query()->where('model_type', 'document')->where('model_id', $document->getKey())->get(),
        );

        expect($media)->toHaveCount(1)
            ->and(Storage::disk('local')->exists($media->first()->getPathRelativeToRoot()))->toBeTrue();
    }

    expect(Document::query()->count())->toBe(count($documents))
        ->and(Storage::disk('local')->allFiles())->toHaveCount(count($documents));
}

/**
 * The sorted ids a query returns.
 *
 * @param  Builder<Document>  $query
 * @return list<string>
 */
function ownerDeletionIds(Builder $query): array
{
    /** @var list<string> $ids */
    $ids = $query->pluck('id')->all();
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * The sorted ids of the given documents.
 *
 * @return list<string>
 */
function ownerDeletionKeys(Document ...$documents): array
{
    $ids = array_map(static fn (Document $document): string => (string) $document->getKey(), $documents);
    sort($ids, SORT_STRING);

    return $ids;
}

describe('every document owner is archived, append-only or deletes its files itself', function (): void {
    it('maps every owner kind to a soft-deleting model, the append-only comment, or the transaction', function (): void {
        $kinds = [];

        foreach (DocumentOwnerType::cases() as $type) {
            $class = Relation::getMorphedModel($type->value);

            expect($class)->not->toBeNull("The owner kind {$type->value} has no model in the morph map.");

            $kinds[$type->value] = match (true) {
                in_array(SoftDeletes::class, class_uses_recursive($class), true) => 'archived',
                $class === TaskComment::class => 'append-only',
                $class === Transaction::class => 'deletes its receipts',
                default => 'UNGUARDED: '.$class,
            };
        }

        $this->assertSame(
            ['client' => 'archived', 'project' => 'archived', 'task' => 'archived', 'task_comment' => 'append-only', 'transaction' => 'deletes its receipts'],
            $kinds,
            'A document owner kind gained a hard-delete path or is new. Delete its owned documents through DeleteDocument (row, media row and stored object) before the owner row goes, then list the kind here (D-09, research Pitfall 6).',
        );
    });

    it('has no hard-delete call in the application', function (): void {
        $found = [];
        $scanned = 0;

        foreach (ownerDeletionSources(app_path()) as $path => $source) {
            $scanned++;

            foreach (ownerDeletionForceDeleteCalls($source) as $line) {
                $found[] = "{$path}:{$line}";
            }
        }

        expect($scanned)->toBeGreaterThan(20);

        $this->assertSame(
            [],
            $found,
            'A forceDelete or forceDestroy call can orphan the stored objects of a document owner. Delete the owned documents through DeleteDocument first, and keep archiving (soft delete) for clients, projects and tasks, which must never touch stored objects (D-09, D-21).',
        );
    });

    it('reports a hard-delete call in a fixture, so the scan cannot pass vacuously', function (): void {
        $source = "<?php\n\nfinal class Example\n{\n    public function drop(\$client): void\n    {\n        \$client->forceDelete();\n        Client::forceDestroy(\$id);\n        \$maybe?->forceDelete();\n    }\n}\n";

        expect(ownerDeletionForceDeleteCalls($source))->toBe([7, 8, 9]);
    });

    it('does not report a method declaration, a comment or a string', function (): void {
        $source = "<?php\n\nfinal class Example\n{\n    // \$client->forceDelete();\n    public function forceDelete(\$user, \$record): bool\n    {\n        \$text = '\$client->forceDelete()';\n\n        return false;\n    }\n}\n";

        expect(ownerDeletionForceDeleteCalls($source))->toBe([]);
    });

    it('has no task Action that deletes a comment', function (): void {
        $found = [];
        $sources = ownerDeletionSources(app_path('Domain/Tasks/Actions'));

        foreach ($sources as $path => $source) {
            foreach (ownerDeletionCommentDeletes($source) as $line) {
                $found[] = "{$path}:{$line}";
            }
        }

        expect($sources)->not->toBeEmpty();

        $this->assertSame(
            [],
            $found,
            'Task comments are append-only. A comment delete would orphan the objects of its files: delete them through DeleteDocument first (D-09, research Pitfall 6).',
        );
    });

    it('reports a comment delete in a fixture', function (): void {
        $source = "<?php\n\nfinal class Example\n{\n    public function drop(\$comment): void\n    {\n        TaskComment::query()->whereKey(\$id)->delete();\n    }\n}\n";

        expect(ownerDeletionCommentDeletes($source))->toBe([7]);
    });

    it('does not report a delete in an Action that never names comments', function (): void {
        $source = "<?php\n\nfinal class Example\n{\n    public function drop(\$billing): void\n    {\n        \$billing->delete();\n    }\n}\n";

        expect(ownerDeletionCommentDeletes($source))->toBe([]);
    });
});

describe('archiving and restoring an owner never costs a file (D-21)', function (): void {
    beforeEach(function (): void {
        DocumentFixtures::fakeDisk();
        $this->admin = Canary::admin();
        $this->actingAs($this->admin);
    });

    it('keeps every row, media row and object through archiving a task, a project and a client and restoring them', function (): void {
        $client = Client::factory()->create();
        $project = ownerDeletionProject($client);
        $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example owner-deletion task']);
        $files = [];

        foreach ([$client, $project, $task] as $owner) {
            $files[] = app(AttachDocuments::class)->handle($this->admin, $owner, [DocumentFixtures::upload()])->firstOrFail();
        }

        [$clientFile, $projectFile, $taskFile] = $files;
        $partner = Canary::partnerFor((string) $client->getKey());

        // Before anything is archived the Partner reads the project and the task file, never the client file.
        $this->actingAs($partner);
        expect(ownerDeletionIds(Document::query()))->toBe(ownerDeletionKeys($projectFile, $taskFile));
        $this->actingAs($this->admin);

        // Archive: task, then project, then client.
        app(ArchiveTask::class)->handle($this->admin, $task);
        ownerDeletionAssertIntact(...$files);
        $this->actingAs($partner);
        expect(ownerDeletionIds(Document::query()))->toBe(ownerDeletionKeys($projectFile));
        $this->actingAs($this->admin);

        $project->delete();
        ownerDeletionAssertIntact(...$files);
        $this->actingAs($partner);
        expect(ownerDeletionIds(Document::query()))->toBe([]);
        $this->actingAs($this->admin);

        app(ArchiveClient::class)->handle($client);
        ownerDeletionAssertIntact(...$files);
        expect(ownerDeletionIds(Document::query()->withoutArchivedOwners()))->toBe([])
            ->and(ownerDeletionIds(Document::query()->onlyArchivedOwners()))->toBe(ownerDeletionKeys(...$files));

        // Restore: client, then project, then task.
        app(RestoreClient::class)->handle($client);
        ownerDeletionAssertIntact(...$files);
        expect(ownerDeletionIds(Document::query()->withoutArchivedOwners()))->toBe(ownerDeletionKeys($clientFile))
            ->and(ownerDeletionIds(Document::query()->onlyArchivedOwners()))->toBe(ownerDeletionKeys($projectFile, $taskFile));

        $project->restore();
        ownerDeletionAssertIntact(...$files);
        $this->actingAs($partner);
        expect(ownerDeletionIds(Document::query()))->toBe(ownerDeletionKeys($projectFile));
        $this->actingAs($this->admin);

        app(RestoreTask::class)->handle($this->admin, $task);
        ownerDeletionAssertIntact(...$files);

        // Back where it began: the Partner reads the visible project and task files again, the Admin sees all three.
        $this->actingAs($partner);
        expect(ownerDeletionIds(Document::query()))->toBe(ownerDeletionKeys($projectFile, $taskFile));
        $this->actingAs($this->admin);
        expect(ownerDeletionIds(Document::query()->withoutArchivedOwners()))->toBe(ownerDeletionKeys(...$files))
            ->and(ownerDeletionIds(Document::query()->onlyArchivedOwners()))->toBe([]);
    });
});
