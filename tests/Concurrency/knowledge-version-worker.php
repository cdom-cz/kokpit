<?php

declare(strict_types=1);

/*
 * Child process of the knowledge restore and redaction concurrency test.
 *
 *   php tests/Concurrency/knowledge-version-worker.php <startAt> <articleId> <adminId> <mode> <versionNo> <expectedVersion> <expectedRedactionNo> <holdMs>
 *
 * Boots the application, signs in the Admin (a worker has no session, so every Partner scope would otherwise be
 * fail-closed), waits until <startAt> (a unix time with fractions), then does one thing to the article:
 *
 * <mode> `redact-hold` redacts the whole text of version <versionNo> inside an outer transaction and keeps that
 * transaction open for <holdMs> milliseconds after the redaction, so the article lock the redaction took stays held.
 * <mode> `restore` restores version <versionNo> as the newest version, from <expectedVersion> and
 * <expectedRedactionNo>. A StaleArticle refusal is counted, not retried.
 *
 * Prints one JSON line (mode, done, stale, error class only, never a message) and exits 1 on any other error. Refuses
 * to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\RedactVersion;
use App\Domain\Knowledge\Actions\RestoreVersion;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "knowledge-version-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 9) {
    $fail('expected 8 arguments: startAt articleId adminId mode versionNo expectedVersion expectedRedactionNo holdMs');
}

[, $startAtArg, $articleId, $adminId, $mode, $versionNoArg, $expectedVersionArg, $expectedRedactionNoArg, $holdMsArg] = $argv;

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

if (preg_match($uuid, $articleId) !== 1 || preg_match($uuid, $adminId) !== 1) {
    $fail('the article id and the admin id must be uuids');
}

if (! in_array($mode, ['redact-hold', 'restore'], true)) {
    $fail('mode must be redact-hold or restore');
}

foreach ([$versionNoArg, $expectedVersionArg, $expectedRedactionNoArg, $holdMsArg] as $number) {
    if (preg_match('/^\d{1,6}$/D', $number) !== 1) {
        $fail('the version numbers and the hold time must be whole numbers');
    }
}

$versionNo = (int) $versionNoArg;
$expectedVersion = (int) $expectedVersionArg;
$expectedRedactionNo = (int) $expectedRedactionNoArg;
$holdMs = (int) $holdMsArg;

if ($holdMs > 10000) {
    $fail('the hold time must be at most 10000 milliseconds');
}

$admin = User::query()->findOrFail($adminId);
auth()->setUser($admin);

$article = KnowledgeArticle::query()->findOrFail($articleId);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

$startAt = (float) $startAtArg;

while (microtime(true) < $startAt) {
    usleep(100);
}

$done = false;
$stale = false;
$error = null;

try {
    if ($mode === 'redact-hold') {
        $redact = $app->make(RedactVersion::class);

        DB::transaction(static function () use ($redact, $admin, $article, $versionNo, $holdMs): void {
            $redact->handle($admin, $article, $versionNo, RedactionScope::Whole, '', 'Example reason of the race test');

            // The article lock of the redaction stays held until this outer transaction commits.
            usleep($holdMs * 1000);
        });
    } else {
        $app->make(RestoreVersion::class)->handle($admin, $article, $versionNo, $expectedVersion, $expectedRedactionNo);
    }

    $done = true;
} catch (StaleArticle) {
    $stale = true;
} catch (Throwable $e) {
    $error = $e::class;
}

echo json_encode([
    'mode' => $mode,
    'done' => $done,
    'stale' => $stale,
    'error' => $error,
], JSON_THROW_ON_ERROR), "\n";

exit($error === null ? 0 : 1);
