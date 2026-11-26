<?php

declare(strict_types=1);

/*
 * Child process of the knowledge article save concurrency test.
 *
 *   php tests/Concurrency/knowledge-save-worker.php <startAt> <articleId> <adminId> <mode> <label> <count>
 *
 * Boots the application, signs in the Admin (a worker has no session, so every Partner scope would otherwise be
 * fail-closed), waits for the shared start barrier so the workers' transactions truly overlap, then calls
 * SaveArticle::update() for the article.
 *
 * <mode> `once` reads the version of the article before the barrier and saves once with exactly that version; a
 * StaleArticle refusal is counted, not retried. <mode> `chain` performs <count> saves, each from the version it reads
 * just before the save, and after a StaleArticle refusal reads the version again and tries once more until the save
 * is stored.
 *
 * The body of every save is a fictional text naming the worker label (and the save number in chain mode), so the test
 * can tell whose save is the newest version.
 *
 * Prints one JSON line (label, saved, stale, errors by exception class only, never a message) and exits 1 on any other
 * error. Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "knowledge-save-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 7) {
    $fail('expected 6 arguments: startAt articleId adminId mode label count');
}

[, $startAtArg, $articleId, $adminId, $mode, $label, $countArg] = $argv;

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
$count = (int) $countArg;

if (preg_match($uuid, $articleId) !== 1 || preg_match($uuid, $adminId) !== 1) {
    $fail('the article id and the admin id must be uuids');
}

if (! in_array($mode, ['once', 'chain'], true)) {
    $fail('mode must be once or chain');
}

if (preg_match('/^[A-Za-z0-9]{1,16}$/D', $label) !== 1) {
    $fail('the label must be 1 to 16 letters or digits');
}

if ($count < 1 || $count > 50) {
    $fail('count must be between 1 and 50');
}

$admin = User::query()->findOrFail($adminId);
auth()->setUser($admin);

$article = KnowledgeArticle::query()->findOrFail($articleId);
$title = $article->title;
$action = $app->make(SaveArticle::class);

// The version and the redaction number this worker saves from in `once` mode are read before the barrier.
$version = $article->version_no;
$redactionNo = $article->redaction_no;

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$saved = 0;
$stale = 0;
/** @var array<string, int> $errors */
$errors = [];

$errorOf = static function (Throwable $e) use (&$errors): void {
    $errors[$e::class] = ($errors[$e::class] ?? 0) + 1;
};

if ($mode === 'once') {
    try {
        $action->update($admin, $article, ArticleInput::from($title, "Example body of worker {$label}", false), $version, $redactionNo);

        $saved++;
    } catch (StaleArticle) {
        $stale++;
    } catch (Throwable $e) {
        $errorOf($e);
    }
} else {
    for ($i = 1; $i <= $count && $errors === []; $i++) {
        // A save stays refused only while others win; it is retried from the newly read version.
        for ($attempt = 1; $attempt <= 500; $attempt++) {
            try {
                $currentRow = KnowledgeArticle::query()->whereKey($articleId)->first(['version_no', 'redaction_no']);
                $current = (int) $currentRow?->version_no;
                $currentRedactionNo = (int) $currentRow?->redaction_no;

                $action->update($admin, $article, ArticleInput::from($title, "Example body of worker {$label} save {$i}", false), $current, $currentRedactionNo);

                $saved++;

                break;
            } catch (StaleArticle) {
                $stale++;
            } catch (Throwable $e) {
                $errorOf($e);

                break;
            }
        }
    }
}

echo json_encode([
    'label' => $label,
    'saved' => $saved,
    'stale' => $stale,
    'errors' => $errors,
], JSON_THROW_ON_ERROR), "\n";

exit($errors === [] ? 0 : 1);
