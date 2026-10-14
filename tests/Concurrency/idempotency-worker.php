<?php

declare(strict_types=1);

/*
 * Child process of the idempotency concurrency test.
 *
 *   php tests/Concurrency/idempotency-worker.php <token> <key> <clientId> <storeClass> <barrierAt> [<rendezvousDir> <parties>]
 *
 * Boots the application, binds the idempotency store, waits for the shared start barrier so the workers'
 * requests truly overlap, then sends one `POST /api/v1/time-entries` through the real HTTP kernel with the
 * bearer token, the `Idempotency-Key` header and a fixed fictional body (the same bytes in every worker, so
 * the request hash is identical). Prints one JSON summary line:
 *
 *   {"pid": int, "status": int, "replayed": bool, "body": string, "error": string|null}
 *
 * `body` is the raw response content, so the test can compare replays byte for byte. Exits 0 when a response
 * came back (whatever its status) and 1 when the request itself blew up.
 *
 * <storeClass> is the short name `IdempotencyStore` or `UnclaimedIdempotencyStore` and is bound to
 * IdempotencyStore in the container before the request is handled. <barrierAt> is the shared start instant
 * (microtime as a decimal string); the environment variable KOKPIT_BARRIER_AT takes precedence.
 *
 * Optional, for the mutation run only: <rendezvousDir> and <parties> make the UnclaimedIdempotencyStore hold
 * every party inside claim() until all of them have arrived (see UnclaimedIdempotencyStore). They are refused
 * with the real store, which has no rendezvous.
 *
 * Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Api\Idempotency\IdempotencyStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\UnclaimedIdempotencyStore;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "idempotency-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 6 && $argc !== 8) {
    $fail('expected 5 or 7 arguments: token key clientId storeClass barrierAt [rendezvousDir parties]');
}

[, $token, $key, $clientId, $storeName, $barrierArg] = $argv;
$rendezvousDir = $argv[6] ?? null;
$parties = (int) ($argv[7] ?? 0);

if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $clientId) !== 1) {
    $fail('the client id must be a uuid');
}

if ($token === '' || $key === '') {
    $fail('the token and the key must not be empty');
}

if (! in_array($storeName, ['IdempotencyStore', 'UnclaimedIdempotencyStore'], true)) {
    $fail('the store class must be IdempotencyStore or UnclaimedIdempotencyStore');
}

if ($rendezvousDir !== null) {
    if ($storeName !== 'UnclaimedIdempotencyStore') {
        $fail('the rendezvous belongs to UnclaimedIdempotencyStore only: the real store has none');
    }

    if (! is_dir($rendezvousDir) || $parties < 2 || $parties > 64) {
        $fail('the rendezvous needs an existing directory and between 2 and 64 parties');
    }
}

$store = $storeName === 'UnclaimedIdempotencyStore'
    ? new UnclaimedIdempotencyStore($rendezvousDir, $parties)
    : new IdempotencyStore;

$app->instance(IdempotencyStore::class, $store);

$body = json_encode([
    'client_id' => $clientId,
    'started_at' => '2026-10-12T10:00:00+02:00',
    'duration_seconds' => 3600,
    'description' => 'Fictional concurrent import',
], JSON_THROW_ON_ERROR);

$request = Request::create('/api/v1/time-entries', 'POST', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_IDEMPOTENCY_KEY' => $key,
    'CONTENT_TYPE' => 'application/json',
], $body);

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $barrierArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$pid = (int) getmypid();
$status = 0;
$replayed = false;
$content = '';
$error = null;

try {
    $response = $kernel->handle($request);

    $status = $response->getStatusCode();
    $replayed = $response->headers->get('Idempotent-Replayed') === 'true';
    $content = (string) $response->getContent();
} catch (Throwable $e) {
    $error = $e::class.': '.substr($e->getMessage(), 0, 160);
}

echo json_encode([
    'pid' => $pid,
    'status' => $status,
    'replayed' => $replayed,
    'body' => $content,
    'error' => $error,
], JSON_THROW_ON_ERROR), "\n";

exit($error === null ? 0 : 1);
