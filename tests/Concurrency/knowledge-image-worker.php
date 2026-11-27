<?php

declare(strict_types=1);

/*
 * Child process of the knowledge image cleanup concurrency test.
 *
 *   php tests/Concurrency/knowledge-image-worker.php <startAt> <role> <articleId> <adminId> <imageId>
 *
 * Boots the application and waits for the shared start barrier, so the transactions of the workers truly overlap.
 *
 * <role> `save` signs in the Admin (a worker has no session, so every Partner scope would otherwise be fail-closed),
 * reads the version of the article before the barrier and calls SaveArticle::update() with a body that references the
 * image. It prints the name of the save outcome (`Updated`).
 *
 * <role> `prune` runs PruneImages::run() in the system context, the way the scheduled job does, and prints the number
 * of images it deleted.
 *
 * Prints one JSON line (role, outcome, deleted, errors by exception class only, never a message) and exits 1 on any
 * error. Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\PruneImages;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Support\ImageReferences;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "knowledge-image-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 6) {
    $fail('expected 5 arguments: startAt role articleId adminId imageId');
}

[, $startAtArg, $role, $articleId, $adminId, $imageId] = $argv;

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

foreach ([$articleId, $adminId, $imageId] as $id) {
    if (preg_match($uuid, $id) !== 1) {
        $fail('the article id, the admin id and the image id must be uuids');
    }
}

if (! in_array($role, ['save', 'prune'], true)) {
    $fail('role must be save or prune');
}

// Everything the save needs is read before the barrier, so the only work after it is the contested one.
$admin = null;
$article = null;
$version = 0;
$redactionNo = 0;

if ($role === 'save') {
    $admin = User::query()->findOrFail($adminId);
    auth()->setUser($admin);

    $article = KnowledgeArticle::query()->findOrFail($articleId);
    $version = $article->version_no;
    $redactionNo = $article->redaction_no;
}

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$outcome = '';
$deleted = 0;
/** @var array<string, int> $errors */
$errors = [];

try {
    if ($role === 'save') {
        $body = 'Example body naming an image '.ImageReferences::markdown($imageId, 'Example alt');

        $result = $app->make(SaveArticle::class)->update($admin, $article, ArticleInput::from($article->title, $body, false), $version, $redactionNo);

        $outcome = $result->outcome->name;
    } else {
        $counts = $app->make(PartnerContext::class)->runAsSystem(static fn (): array => $app->make(PruneImages::class)->run());

        $deleted = $counts['deleted'];
        $outcome = 'Pruned';
    }
} catch (Throwable $e) {
    $errors[$e::class] = ($errors[$e::class] ?? 0) + 1;
}

echo json_encode([
    'role' => $role,
    'outcome' => $outcome,
    'deleted' => $deleted,
    'errors' => $errors,
], JSON_THROW_ON_ERROR), "\n";

exit($errors === [] ? 0 : 1);
