<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\KnowledgeFixtures;

/*
 * Real parallel PHP processes: the daily image cleanup racing a save that references the same image (Phase 20, plan
 * 20-09; IMG-03, D-16, D-17, D-18; R-20-09-2). The cleanup locks one image row at a time and rechecks its references
 * under that lock; a save locks the article row and then the image rows it names, and indexes the image for the new
 * version. Whichever side takes the image row first decides, and the other side must adapt without failing:
 *
 *  - the save first: the cleanup finds the stamp cleared or the reference present and keeps the image;
 *  - the cleanup first: the image is gone, the save's locked read finds no row and the save succeeds without a
 *    reference to it.
 *
 * Ten rounds. Each round commits an article of version 1 through the real save path and a bound image that no version
 * references, stamped as an orphan 8 days ago (so the cleanup may delete it right now), with its object on the default
 * disk. A save worker and a prune worker start behind one barrier. Every round ends with both workers done, without an
 * exception, the save outcome `Updated` (version 2 stored), and exactly one of two end states:
 *  (a) the image row and its object exist, its stamp is null and one reference row names it for version 2;
 *  (b) the image row and its object are gone and no reference row names it.
 * The deleted count of the cleanup worker agrees with the end state. Which side wins is up to the scheduler, so the test
 * does not require both end states to occur.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. The version table is guarded by
 * triggers, so the cleanup deletes the reference rows, then disables the user triggers of that table inside one
 * transaction, deletes the versions of the test's own articles, enables the triggers again and asserts that every
 * trigger of the table is enabled. The objects of the test's images are removed from the disk.
 */

const KNOWLEDGE_IMAGE_CLEANUP_ROUNDS = 10;
const KNOWLEDGE_IMAGE_CLEANUP_BARRIER_SECONDS = 5.0;
const KNOWLEDGE_IMAGE_CLEANUP_TIMEOUT_SECONDS = 120;

/**
 * Bookkeeping for the rows and objects committed by the running test.
 */
function knowledgeImageCleanupScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'admin' => null,
        'role_created' => false,
        'articles' => [],
        'images' => [],
        'paths' => [],
    ]);
}

/**
 * Commits an Admin and returns it, remembering what the cleanup has to undo.
 */
function knowledgeImageCleanupAdmin(): User
{
    $scratch = knowledgeImageCleanupScratch();

    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    return $admin;
}

/**
 * Commits an article of version 1 through the real save path and a bound image that no version references, stamped as
 * an orphan 8 days ago, with its object on the default disk. Returns the ids and the object path.
 *
 * @return array{article: string, image: string, path: string}
 */
function knowledgeImageCleanupRound(User $admin): array
{
    auth()->setUser($admin);

    $article = app(SaveArticle::class)->create($admin, ArticleInput::from('Example concurrency article', 'Example starting body', false))->article;
    $image = KnowledgeFixtures::image($article, $admin, ['orphaned_at' => now()->subDays(8)]);

    $scratch = knowledgeImageCleanupScratch();
    $scratch['articles'] = [...$scratch['articles'], $article->id];
    $scratch['images'] = [...$scratch['images'], $image->id];
    $scratch['paths'] = [...$scratch['paths'], $image->storagePath()];

    return ['article' => $article->id, 'image' => $image->id, 'path' => $image->storagePath()];
}

/**
 * The number of triggers on the version table that are not enabled. Zero when every guard is in force.
 */
function knowledgeImageCleanupDisabledTriggers(): int
{
    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname = 'knowledge_article_versions' AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
    )->n;
}

/**
 * Removes everything the test committed. Returns the number of rows and objects of its own that are left behind plus
 * the number of triggers that are not enabled (zero when the cleanup is complete).
 */
function knowledgeImageCleanupCleanup(): int
{
    $scratch = knowledgeImageCleanupScratch();
    $admin = $scratch['admin'];
    $articles = $scratch['articles'];
    $images = $scratch['images'];
    $paths = $scratch['paths'];

    foreach ($paths as $path) {
        Storage::disk()->delete($path);
    }

    if ($articles !== []) {
        DB::transaction(static function () use ($articles, $images): void {
            DB::table('knowledge_article_version_images')->whereIn('article_id', $articles)->delete();

            DB::statement('ALTER TABLE knowledge_article_versions DISABLE TRIGGER USER');

            DB::table('knowledge_article_versions')->whereIn('article_id', $articles)->delete();
            DB::table('knowledge_article_images')->whereIn('id', $images)->delete();
            DB::table('activity_log')->where('subject_type', 'knowledge_article')->whereIn('subject_id', $articles)->delete();
            DB::table('knowledge_articles')->whereIn('id', $articles)->delete();

            DB::statement('ALTER TABLE knowledge_article_versions ENABLE TRIGGER USER');
        });
    }

    if ($admin !== null) {
        DB::table('model_has_roles')->where('model_id', $admin)->delete();
        DB::table('activity_log')->where('causer_id', $admin)->orWhere('subject_id', $admin)->delete();
        DB::table('users')->where('id', $admin)->delete();
    }

    if ($scratch['role_created']) {
        DB::table('roles')->where('name', RoleName::Admin->value)->where('guard_name', 'web')->delete();
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app()->forgetScopedInstances();

    $left = 0;
    $left += $articles === [] ? 0 : DB::table('knowledge_articles')->whereIn('id', $articles)->count();
    $left += $articles === [] ? 0 : DB::table('knowledge_article_versions')->whereIn('article_id', $articles)->count();
    $left += $articles === [] ? 0 : DB::table('knowledge_article_version_images')->whereIn('article_id', $articles)->count();
    $left += $images === [] ? 0 : DB::table('knowledge_article_images')->whereIn('id', $images)->count();
    $left += $articles === [] ? 0 : DB::table('activity_log')->where('subject_type', 'knowledge_article')->whereIn('subject_id', $articles)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += count(array_filter($paths, static fn (string $path): bool => Storage::disk()->exists($path)));
    $left += knowledgeImageCleanupDisabledTriggers();

    $scratch['admin'] = null;
    $scratch['role_created'] = false;
    $scratch['articles'] = [];
    $scratch['images'] = [];
    $scratch['paths'] = [];

    return $left;
}

/**
 * Starts the save worker and the prune worker behind one shared barrier and waits for both.
 *
 * @return array{save: array{role: string, outcome: string, deleted: int, errors: array<string, int>}, prune: array{role: string, outcome: string, deleted: int, errors: array<string, int>}} the result of each worker
 */
function knowledgeImageCleanupRun(string $adminId, string $articleId, string $imageId): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + KNOWLEDGE_IMAGE_CLEANUP_BARRIER_SECONDS, 6, '.', '');

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) config("database.connections.{$connection}.host"),
        'DB_PORT' => (string) config("database.connections.{$connection}.port"),
        'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
        'DB_USERNAME' => (string) config("database.connections.{$connection}.username"),
        'DB_PASSWORD' => (string) config("database.connections.{$connection}.password"),
        'DB_URL' => '',
        'FILESYSTEM_DISK' => (string) config('filesystems.default'),
        'KOKPIT_BARRIER_AT' => $barrier,
    ];

    $processes = [];
    foreach (['save', 'prune'] as $role) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/knowledge-image-worker.php', $barrier, $role, $articleId, $adminId, $imageId],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(KNOWLEDGE_IMAGE_CLEANUP_TIMEOUT_SECONDS);
        $process->start();
        $processes[$role] = $process;
    }

    $results = [];
    foreach ($processes as $role => $process) {
        $process->wait();

        $result = null;
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['role'], $decoded['outcome'], $decoded['errors'])) {
                $result = $decoded;
            }
        }

        expect($result)->not->toBeNull("the {$role} worker printed no result: {$process->getErrorOutput()}");
        expect($process->getExitCode())->toBe(0, "the {$role} worker failed: ".json_encode($result['errors'] ?? []));
        $results[$role] = $result;
    }

    return $results;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = knowledgeImageCleanupCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows, objects or disabled triggers behind');
});

it('ends a cleanup racing a save either with the image kept and indexed or with the image gone and the save stored, ten rounds', function () {
    $admin = knowledgeImageCleanupAdmin();

    for ($round = 1; $round <= KNOWLEDGE_IMAGE_CLEANUP_ROUNDS; $round++) {
        $ids = knowledgeImageCleanupRound($admin);
        $path = $ids['path'];

        expect(Storage::disk()->exists($path))->toBeTrue("round {$round}: the object exists before the race");

        $results = knowledgeImageCleanupRun($admin->id, $ids['article'], $ids['image']);

        expect($results['save']['errors'])->toBe([], "round {$round}: the save raised")
            ->and($results['prune']['errors'])->toBe([], "round {$round}: the cleanup raised")
            ->and($results['save']['outcome'])->toBe('Updated', "round {$round}: the save outcome")
            ->and((int) DB::table('knowledge_articles')->where('id', $ids['article'])->value('version_no'))->toBe(2, "round {$round}: the article version");

        $image = DB::table('knowledge_article_images')->where('id', $ids['image'])->first();
        $references = DB::table('knowledge_article_version_images')->where('image_id', $ids['image'])->get();
        $objectExists = Storage::disk()->exists($path);

        if ($image !== null) {
            // End state (a): the save indexed the image first and the cleanup kept it.
            expect($objectExists)->toBeTrue("round {$round}: the kept image has its object")
                ->and($image->orphaned_at)->toBeNull("round {$round}: the kept image lost its stamp")
                ->and($references)->toHaveCount(1, "round {$round}: one reference row")
                ->and($results['prune']['deleted'])->toBe(0, "round {$round}: nothing deleted");

            $version = DB::table('knowledge_article_versions')->where('id', $references[0]->version_id)->first();

            expect((int) $version->version_no)->toBe(2, "round {$round}: the reference names version 2");

            continue;
        }

        // End state (b): the cleanup deleted the image first and the save went on without a reference to it.
        expect($objectExists)->toBeFalse("round {$round}: the deleted image has no object")
            ->and($references)->toHaveCount(0, "round {$round}: no reference to a deleted image")
            ->and($results['prune']['deleted'])->toBe(1, "round {$round}: one image deleted");
    }
});
