<?php

declare(strict_types=1);

/*
 * Child process of the card payment matching concurrency test.
 *
 *   php tests/Concurrency/payment-match-worker.php <startAt> <mode> <adminId> <target> [<amount>]
 *
 * Boots the application, waits for the shared start barrier so the transactions truly overlap, then acts once in the
 * system context (a worker has no session, so every Partner scope would otherwise be fail-closed). <target> is what the
 * mode works on:
 * - `match`      ProcessStripeWebhook for the stored webhook row <target>, as the queue runs it;
 * - `store`      WebhookCall::storeStripeEvent() for the payload in the JSON file <target>;
 * - `manual`     RecordPayment of <amount> (major units, dated today, a bank transfer) on the invoice <target>, as the
 *                committed fictional Admin.
 *
 * Prints one JSON line `{"mode": ..., "result": ..., "pid": ...}` where the result is `matched`, `duplicate`,
 * `unmatched:<reason>`, `ignored` (the stored event was already processed), `processed` (processed with no record),
 * `stored:new`, `stored:existing`, `recorded`, `refused:<key>` for a typed ValidationException (<key> is the key under
 * invoices.errors whose text it carries) or `db:<sqlstate>` for a QueryException; anything else is `error:<class>` and
 * exits 1. Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\WebhookClient\WebhookConfigRepository;
use Tests\Support\FakeStripeGateway;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "payment-match-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc < 5 || $argc > 6) {
    $fail('expected 4 or 5 arguments: startAt mode adminId target [amount]');
}

[, $startAtArg, $mode, $adminId, $target] = $argv;
$amount = $argv[5] ?? '';

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

if (! in_array($mode, ['match', 'store', 'manual'], true)) {
    $fail('the mode must be match, store or manual');
}

if (preg_match($uuid, $adminId) !== 1) {
    $fail('the admin id must be a uuid');
}

if ($mode !== 'store' && preg_match($uuid, $target) !== 1) {
    $fail('the target must be a uuid');
}

if ($mode === 'store' && ! is_file($target)) {
    $fail('the store mode needs the path of a payload file');
}

if ($mode === 'manual' && preg_match('/^\d{1,6}$/D', $amount) !== 1) {
    $fail('the manual mode needs a whole amount of up to six digits');
}

// A worker never reaches Stripe: the link deactivation that follows a payment runs on the in-memory gateway.
FakeStripeGateway::install();

$system = static fn (callable $callback): mixed => app(PartnerContext::class)->runAsSystem($callback);

$admin = $system(static fn (): User => User::query()->findOrFail($adminId));
auth()->setUser($admin);

/**
 * The key under invoices.errors whose text the validation error carries, or its first message.
 */
$errorKey = static function (ValidationException $e): string {
    $message = (string) (collect($e->errors())->flatten()->first() ?? '');
    $texts = trans('invoices.errors');

    foreach (is_array($texts) ? $texts : [] as $key => $text) {
        if (! is_string($text)) {
            continue;
        }

        $parts = preg_split('/:[a-z_]+/', $text) ?: [];
        $pattern = '/^'.implode('.+', array_map(static fn (string $part): string => preg_quote($part, '/'), $parts)).'$/Du';

        if (preg_match($pattern, $message) === 1) {
            return (string) $key;
        }
    }

    return $message;
};

$sqlState = static function (QueryException $e): string {
    $previous = $e->getPrevious();

    if ($previous instanceof PDOException && is_array($previous->errorInfo) && isset($previous->errorInfo[0])) {
        return (string) $previous->errorInfo[0];
    }

    return (string) $e->getCode();
};

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

$waitForBarrier = static function () use ($barrierAt): void {
    while (microtime(true) < $barrierAt) {
        usleep(100);
    }
};

/**
 * What a processed event became: the type of its record, with the reason of an unmatched one.
 */
$outcomeOf = static function (WebhookCall $call) use ($system): string {
    $record = $system(static fn (): ?CardPayment => CardPayment::query()->where('stripe_event_id', $call->event_id)->first());

    if (! $record instanceof CardPayment) {
        return 'processed';
    }

    return $record->type->value.($record->reason === null || $record->type->value !== 'unmatched' ? '' : ':'.$record->reason->value);
};

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

// Whether this worker itself marked the stored event processed: the only way to tell it from one that found the event
// already processed and returned.
$processedHere = false;
DB::listen(static function (QueryExecuted $query) use (&$processedHere): void {
    if (str_starts_with($query->sql, 'update "webhook_calls"') && str_contains($query->sql, '"processed_at"')) {
        $processedHere = true;
    }
});

$call = null;
$payload = null;

if ($mode === 'match') {
    $call = $system(static fn (): WebhookCall => WebhookCall::query()->findOrFail($target));
}

if ($mode === 'store') {
    $payload = json_decode((string) file_get_contents($target), true, 512, JSON_THROW_ON_ERROR);
}

$pid = (int) getmypid();
$exit = 0;

try {
    switch ($mode) {
        case 'match':
            $waitForBarrier();
            $job = new ProcessStripeWebhook($call);
            $system(static fn (): mixed => $job->handle());
            $result = $processedHere ? $outcomeOf($call) : 'ignored';
            break;

        case 'store':
            $waitForBarrier();
            [, $inserted] = WebhookCall::storeStripeEvent(
                app(WebhookConfigRepository::class)->getConfig('stripe') ?? throw new RuntimeException('no stripe webhook config'),
                (string) $payload['id'],
                (string) $payload['type'],
                $payload,
            );
            $result = $inserted ? 'stored:new' : 'stored:existing';
            break;

        default: // manual
            $waitForBarrier();
            $invoice = $system(static fn (): Invoice => Invoice::query()->findOrFail($target));
            $today = CarbonImmutable::now('Europe/Prague')->format('Y-m-d');
            $app->make(RecordPayment::class)->handle($admin, $invoice, ['amount' => $amount, 'paid_on' => $today]);
            $result = 'recorded';
    }
} catch (ValidationException $e) {
    $result = 'refused:'.$errorKey($e);
} catch (QueryException $e) {
    $result = 'db:'.$sqlState($e);
} catch (Throwable $e) {
    $result = 'error:'.$e::class.': '.substr($e->getMessage(), 0, 160);
    $exit = 1;
}

echo json_encode(['mode' => $mode, 'result' => $result, 'pid' => $pid], JSON_THROW_ON_ERROR), "\n";

exit($exit);
