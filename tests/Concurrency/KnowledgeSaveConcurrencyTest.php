<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

/*
 * Real parallel PHP processes saving the same knowledge article at the same moment (Phase 18, P18-05, KB-08, VER-01,
 * D-09, D-11): every save locks the article row, compares the version it was opened with and writes the next number, so
 * parallel saves from one version must end with exactly one winner, a refusal for everybody else and version numbers
 * without a gap or a duplicate.
 *
 * Scenario A (five rounds): four workers behind one barrier each save once from the same version. Every round ends
 * with one saved, three stale, no error, the version one higher, the version rows numbered 1 to n without a gap and
 * the newest version holding the winner's body.
 *
 * Scenario B: four workers each perform three saves in a row, re-reading the version after every refusal. It ends with
 * twelve saved saves, the version rows 1 to 13 without a gap or a duplicate and no error (so no unique violation).
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. The version rows are guarded by
 * triggers, so the cleanup disables the user triggers of that table inside one transaction, deletes the rows of the
 * test's own articles, enables the triggers again and asserts that every trigger of the table is enabled.
 */

const KNOWLEDGE_SAVE_ROUNDS = 5;
const KNOWLEDGE_SAVE_WORKERS = 4;
const KNOWLEDGE_SAVE_BARRIER_SECONDS = 5.0;
const KNOWLEDGE_SAVE_TIMEOUT_SECONDS = 180;

/**
 * Bookkeeping for the rows committed by the running test.
 */
function knowledgeSaveRaceScratch(): ArrayObject
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
function knowledgeSaveRaceAdmin(): User
{
    $scratch = knowledgeSaveRaceScratch();

    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    return $admin;
}

/**
 * Commits an article of version 1 through the real save path and returns its id.
 */
function knowledgeSaveRaceArticle(User $admin): string
{
    auth()->setUser($admin);

    $article = app(SaveArticle::class)->create($admin, ArticleInput::from('Example concurrency article', 'Example starting body', false))->article;

    $scratch = knowledgeSaveRaceScratch();
    $scratch['articles'] = [...$scratch['articles'], $article->id];

    return $article->id;
}

/**
 * The version rows of an article, oldest number first.
 *
 * @return list<object{version_no: int, body_md: string}>
 */
function knowledgeSaveRaceVersions(string $articleId): array
{
    return DB::table('knowledge_article_versions')
        ->where('article_id', $articleId)
        ->orderBy('version_no')
        ->get(['version_no', 'body_md'])
        ->all();
}

/**
 * The number of triggers on the version table that are not enabled. Zero when every guard is in force.
 */
function knowledgeSaveRaceDisabledTriggers(): int
{
    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname = 'knowledge_article_versions' AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
    )->n;
}

/**
 * Removes everything the test committed. The version table is opened inside one transaction, so a failure leaves its
 * triggers as they were. Returns the number of rows of its own that are left behind plus the number of triggers that
 * are not enabled (zero when the cleanup is complete).
 */
function knowledgeSaveRaceCleanup(): int
{
    $scratch = knowledgeSaveRaceScratch();
    $admin = $scratch['admin'];
    $articles = $scratch['articles'];

    if ($articles !== []) {
        DB::transaction(static function () use ($articles): void {
            DB::statement('ALTER TABLE knowledge_article_versions DISABLE TRIGGER USER');

            DB::table('knowledge_article_versions')->whereIn('article_id', $articles)->delete();
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
    $left += $articles === [] ? 0 : DB::table('activity_log')->where('subject_type', 'knowledge_article')->whereIn('subject_id', $articles)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += knowledgeSaveRaceDisabledTriggers();

    $scratch['admin'] = null;
    $scratch['role_created'] = false;
    $scratch['articles'] = [];

    return $left;
}

/**
 * Starts one worker per job behind one shared barrier and waits for all of them.
 *
 * @param  list<array{mode: string, label: string, count: int}>  $jobs
 * @return list<array{label: string, saved: int, stale: int, errors: array<string, int>}> the result of each worker, in order
 */
function knowledgeSaveRaceRun(string $adminId, string $articleId, array $jobs): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + KNOWLEDGE_SAVE_BARRIER_SECONDS, 6, '.', '');

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) config("database.connections.{$connection}.host"),
        'DB_PORT' => (string) config("database.connections.{$connection}.port"),
        'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
        'DB_USERNAME' => (string) config("database.connections.{$connection}.username"),
        'DB_PASSWORD' => (string) config("database.connections.{$connection}.password"),
        'DB_URL' => '',
        'KOKPIT_BARRIER_AT' => $barrier,
    ];

    $processes = [];
    foreach ($jobs as $job) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/knowledge-save-worker.php', $barrier, $articleId, $adminId, $job['mode'], $job['label'], (string) $job['count']],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(KNOWLEDGE_SAVE_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $i => $process) {
        $process->wait();

        $result = null;
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['saved'], $decoded['stale'], $decoded['label'])) {
                $result = $decoded;
            }
        }

        expect($result)->not->toBeNull("worker {$i} printed no result: {$process->getErrorOutput()}");
        $results[] = $result;
    }

    return $results;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = knowledgeSaveRaceCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows or disabled triggers behind');
});

it('ends parallel saves from one version with one winner and a gap-free version chain, five rounds', function () {
    $admin = knowledgeSaveRaceAdmin();
    $articleId = knowledgeSaveRaceArticle($admin);

    for ($round = 1; $round <= KNOWLEDGE_SAVE_ROUNDS; $round++) {
        $from = (int) DB::table('knowledge_articles')->where('id', $articleId)->value('version_no');

        $jobs = [];
        for ($worker = 1; $worker <= KNOWLEDGE_SAVE_WORKERS; $worker++) {
            $jobs[] = ['mode' => 'once', 'label' => "r{$round}w{$worker}", 'count' => 1];
        }

        $results = knowledgeSaveRaceRun($admin->id, $articleId, $jobs);

        $winners = array_values(array_filter($results, static fn (array $result): bool => $result['saved'] === 1));
        $errors = array_filter(array_column($results, 'errors'), static fn (array $errors): bool => $errors !== []);

        expect($errors)->toBe([], "round {$round} reported errors")
            ->and(array_sum(array_column($results, 'saved')))->toBe(1, "round {$round} saved")
            ->and(array_sum(array_column($results, 'stale')))->toBe(KNOWLEDGE_SAVE_WORKERS - 1, "round {$round} stale")
            ->and($winners)->toHaveCount(1);

        $versions = knowledgeSaveRaceVersions($articleId);

        expect((int) DB::table('knowledge_articles')->where('id', $articleId)->value('version_no'))->toBe($from + 1, "round {$round}")
            ->and(array_column($versions, 'version_no'))->toBe(range(1, $from + 1), "round {$round} version numbers")
            ->and(end($versions)->body_md)->toBe('Example body of worker '.$winners[0]['label'], "round {$round} newest body")
            ->and((string) DB::table('knowledge_articles')->where('id', $articleId)->value('body_md'))->toBe(end($versions)->body_md);
    }
});

it('ends parallel chains of saves with every save stored and no gap or duplicate in the version numbers', function () {
    $admin = knowledgeSaveRaceAdmin();
    $articleId = knowledgeSaveRaceArticle($admin);

    $jobs = [];
    for ($worker = 1; $worker <= KNOWLEDGE_SAVE_WORKERS; $worker++) {
        $jobs[] = ['mode' => 'chain', 'label' => "c{$worker}", 'count' => 3];
    }

    $results = knowledgeSaveRaceRun($admin->id, $articleId, $jobs);

    $errors = array_filter(array_column($results, 'errors'), static fn (array $errors): bool => $errors !== []);
    $versions = knowledgeSaveRaceVersions($articleId);
    $numbers = array_column($versions, 'version_no');

    expect($errors)->toBe([])
        ->and(array_sum(array_column($results, 'saved')))->toBe(KNOWLEDGE_SAVE_WORKERS * 3)
        ->and($numbers)->toBe(range(1, (KNOWLEDGE_SAVE_WORKERS * 3) + 1))
        ->and(array_unique($numbers))->toHaveCount(count($numbers))
        ->and((int) DB::table('knowledge_articles')->where('id', $articleId)->value('version_no'))->toBe((KNOWLEDGE_SAVE_WORKERS * 3) + 1)
        ->and((string) DB::table('knowledge_articles')->where('id', $articleId)->value('body_md'))->toBe(end($versions)->body_md);
});
