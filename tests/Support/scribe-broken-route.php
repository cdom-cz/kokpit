<?php

declare(strict_types=1);

/*
 * Child process of the API documentation test: the generation with one broken route.
 *
 *   php tests/Support/scribe-broken-route.php
 *
 * Boots the application like the concurrency workers, registers GET api/v1/_docs-probe to
 * BrokenDocsProbeController inside the read group's middleware (so Scribe includes it), and runs
 * `scribe:generate --no-interaction` through the console kernel. Scribe reports the unreadable attribute and ends
 * the process with exit status 2; this script adds nothing to that status.
 *
 * Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\ApiAbility;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Output\ConsoleOutput;
use Tests\Support\Probes\BrokenDocsProbeController;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    fwrite(STDERR, "scribe-broken-route: refusing to run: the database name must end in _test\n");
    exit(2);
}

Route::middleware(['auth:sanctum', 'abilities:'.ApiAbility::TimeRead->value, 'throttle:api-read'])
    ->get('api/v1/_docs-probe', BrokenDocsProbeController::class);

// Scribe ends the process itself on an error, so its output goes straight to the console instead of a buffer.
exit($kernel->call('scribe:generate', ['--no-interaction' => true], new ConsoleOutput));
