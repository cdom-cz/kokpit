<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\RedactVersion;
use App\Domain\Knowledge\Actions\SaveArticle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

/*
 * Real parallel PHP processes: a restore that starts while a redaction of the same version is in progress (Phase 19,
 * plan 19-09, research Pitfall 7; D-11, D-12). The redaction takes the article lock first, rewrites the version and
 * keeps the lock for a while; the restore starts shortly after and has to queue on the same article lock before it
 * reads the version. It must therefore restore the redacted text, never the original.
 *
 * Three rounds. Each round commits an article with version 1 ("line one") and version 2 ("line two") through the real
 * save path, starts the redaction worker on version 1 with a held lock and the restore worker on version 1 300 ms
 * later, and ends with: both done without an error, version 1 holding the marker, version 3 restored from version 1
 * holding the marker, and one redaction row for version 1.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. The version and redaction tables are
 * guarded by triggers, so the cleanup disables the user triggers of both inside one transaction, deletes the rows of
 * the test's own articles, enables the triggers again and asserts that every trigger of both tables is enabled.
 */

const KNOWLEDGE_RESTORE_RACE_ROUNDS = 3;
const KNOWLEDGE_RESTORE_RACE_BARRIER_SECONDS = 5.0;
const KNOWLEDGE_RESTORE_RACE_RESTORE_DELAY_SECONDS = 0.3;
const KNOWLEDGE_RESTORE_RACE_HOLD_MILLISECONDS = 1500;
const KNOWLEDGE_RESTORE_RACE_TIMEOUT_SECONDS = 120;

/**
 * Bookkeeping for the rows committed by the running test.
 */
function knowledgeRestoreRaceScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'admin' => null,
        'role_created' => false,
        'articles' => [],
    ]);
}

/**
 * Commits an Admin and returns it, remembering what the cleanup has to undo.
 */
function knowledgeRestoreRaceAdmin(): User
{
    $scratch = knowledgeRestoreRaceScratch();

    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    return $admin;
}

/**
 * Commits an article of two versions through the real save path and returns its id.
 */
function knowledgeRestoreRaceArticle(User $admin): string
{
    auth()->setUser($admin);

    $save = app(SaveArticle::class);
    $article = $save->create($admin, ArticleInput::from('Example race article', 'Example line one of the race test', false))->article;

    $scratch = knowledgeRestoreRaceScratch();
    $scratch['articles'] = [...$scratch['articles'], $article->id];

    $save->update($admin, $article, ArticleInput::from('Example race article', 'Example line two of the race test', false), 1, 0);

    return $article->id;
}

/**
 * The version rows of an article, oldest number first.
 *
 * @return list<object{id: string, version_no: int, body_md: string, restored_from_version_no: int|null}>
 */
function knowledgeRestoreRaceVersions(string $articleId): array
{
    return DB::table('knowledge_article_versions')
        ->where('article_id', $articleId)
        ->orderBy('version_no')
        ->get(['id', 'version_no', 'body_md', 'restored_from_version_no'])
        ->all();
}

/**
 * The number of triggers on the two guarded tables that are not enabled. Zero when every guard is in force.
 */
function knowledgeRestoreRaceDisabledTriggers(): int
{
    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ('knowledge_article_versions', 'knowledge_version_redactions') AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
    )->n;
}

/**
 * Removes everything the test committed. Both guarded tables are opened inside one transaction, so a failure leaves
 * their triggers as they were. Returns the number of rows of its own that are left behind plus the number of triggers
 * that are not enabled (zero when the cleanup is complete).
 */
function knowledgeRestoreRaceCleanup(): int
{
    $scratch = knowledgeRestoreRaceScratch();
    $admin = $scratch['admin'];
    $articles = $scratch['articles'];

    if ($articles !== []) {
        DB::transaction(static function () use ($articles): void {
            DB::statement('ALTER TABLE knowledge_article_versions DISABLE TRIGGER USER');
            DB::statement('ALTER TABLE knowledge_version_redactions DISABLE TRIGGER USER');

            $versions = DB::table('knowledge_article_versions')->whereIn('article_id', $articles)->pluck('id')->all();

            DB::table('knowledge_version_redactions')->whereIn('version_id', $versions)->delete();
            DB::table('knowledge_article_versions')->whereIn('article_id', $articles)->delete();
            DB::table('activity_log')->where('subject_type', 'knowledge_article')->whereIn('subject_id', $articles)->delete();
            DB::table('knowledge_articles')->whereIn('id', $articles)->delete();

            DB::statement('ALTER TABLE knowledge_version_redactions ENABLE TRIGGER USER');
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
    $left += $articles === [] ? 0 : DB::table('activity_log')->where('subject_type', 'knowledge_article')->whereIn('subject_id', $articles)->count();
    $left += $admin === null ? 0 : DB::table('knowledge_version_redactions')->where('actor_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += knowledgeRestoreRaceDisabledTriggers();

    $scratch['admin'] = null;
    $scratch['role_created'] = false;
    $scratch['articles'] = [];

    return $left;
}

/**
 * Starts the redaction worker at the barrier and the restore worker a little later, and waits for both.
 *
 * @return array{redact: array{mode: string, done: bool, stale: bool, error: string|null}, restore: array{mode: string, done: bool, stale: bool, error: string|null}}
 */
function knowledgeRestoreRaceRun(string $adminId, string $articleId): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = microtime(true) + KNOWLEDGE_RESTORE_RACE_BARRIER_SECONDS;

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) config("database.connections.{$connection}.host"),
        'DB_PORT' => (string) config("database.connections.{$connection}.port"),
        'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
        'DB_USERNAME' => (string) config("database.connections.{$connection}.username"),
        'DB_PASSWORD' => (string) config("database.connections.{$connection}.password"),
        'DB_URL' => '',
    ];

    $jobs = [
        'redact' => ['redact-hold', $barrier, (string) KNOWLEDGE_RESTORE_RACE_HOLD_MILLISECONDS],
        'restore' => ['restore', $barrier + KNOWLEDGE_RESTORE_RACE_RESTORE_DELAY_SECONDS, '0'],
    ];

    $processes = [];

    foreach ($jobs as $name => [$mode, $startAt, $holdMs]) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/knowledge-version-worker.php', number_format($startAt, 6, '.', ''), $articleId, $adminId, $mode, '1', '2', '0', $holdMs],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(KNOWLEDGE_RESTORE_RACE_TIMEOUT_SECONDS);
        $process->start();
        $processes[$name] = $process;
    }

    $results = [];

    foreach ($processes as $name => $process) {
        $process->wait();

        $result = null;

        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['mode'], $decoded['done'], $decoded['stale'])) {
                $result = $decoded;
            }
        }

        expect($result)->not->toBeNull("the {$name} worker printed no result: {$process->getErrorOutput()}");
        $results[$name] = $result;
    }

    return $results;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = knowledgeRestoreRaceCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows or disabled triggers behind');
});

it('restores the redacted text when the restore waits on the article lock of a redaction of the same version, three rounds', function () {
    $admin = knowledgeRestoreRaceAdmin();

    for ($round = 1; $round <= KNOWLEDGE_RESTORE_RACE_ROUNDS; $round++) {
        $articleId = knowledgeRestoreRaceArticle($admin);

        $results = knowledgeRestoreRaceRun($admin->id, $articleId);

        $versions = knowledgeRestoreRaceVersions($articleId);
        $first = $versions[0];
        $restored = $versions[2] ?? null;

        expect($results['redact']['error'])->toBeNull("round {$round} redaction error")
            ->and($results['redact']['done'])->toBeTrue("round {$round} redaction done")
            ->and($results['restore']['error'])->toBeNull("round {$round} restore error")
            ->and($results['restore']['stale'])->toBeFalse("round {$round} restore stale")
            ->and($results['restore']['done'])->toBeTrue("round {$round} restore done")
            ->and($first->body_md)->toBe(RedactVersion::MARKER, "round {$round} version 1")
            ->and($versions)->toHaveCount(3, "round {$round} versions")
            ->and($restored?->version_no)->toBe(3)
            ->and($restored?->restored_from_version_no)->toBe(1)
            ->and($restored?->body_md)->toBe(RedactVersion::MARKER, "round {$round} restored body")
            ->and(DB::table('knowledge_version_redactions')->where('version_id', $first->id)->count())->toBe(1, "round {$round} redaction rows")
            ->and((int) DB::table('knowledge_articles')->where('id', $articleId)->value('version_no'))->toBe(3)
            ->and((string) DB::table('knowledge_articles')->where('id', $articleId)->value('body_md'))->toBe(RedactVersion::MARKER);
    }
});
