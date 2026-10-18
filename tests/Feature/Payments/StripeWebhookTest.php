<?php

declare(strict_types=1);

use App\Domain\Operations\Jobs\Middleware\RunsAsSystem;
use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Payments\Jobs\StripeEventHandler;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\RawSql;
use Tests\Support\StripeEvents;
use Tests\Support\StripeTestConfig;
use Tests\Support\StripeWebhookSigner;
use Tests\TestCase;

/*
 * The Stripe webhook endpoint (PA-05, PA-07; D-12, D-15, D-16, D-17, D-18). Every body is signed with a random secret
 * the test configures itself; every id and address is assembled at runtime.
 */

beforeEach(function (): void {
    StripeTestConfig::configure();
});

/**
 * The stored Stripe rows, read as system because the model denies everybody else.
 *
 * @return list<WebhookCall>
 */
function storedStripeCalls(): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => WebhookCall::query()->where('name', 'stripe')->orderBy('created_at')->get()->all(),
    );
}

// --- Task 1: the tracer ---------------------------------------------------------------------------------------

it('stores a signed checkout event once, answers 200 at once and queues its job', function (): void {
    Queue::fake();
    $event = StripeEvents::checkoutCompleted();

    StripeWebhookSigner::post($this, $event)->assertOk();

    $calls = storedStripeCalls();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->name)->toBe('stripe')
        ->and($calls[0]->event_id)->toBe($event['id'])
        ->and($calls[0]->event_name)->toBe('checkout.session.completed')
        ->and($calls[0]->processed_at)->toBeNull()
        ->and($calls[0]->failed_at)->toBeNull();

    Queue::assertPushed(ProcessStripeWebhook::class, 1);
    Queue::assertPushed(ProcessStripeWebhook::class, fn (ProcessStripeWebhook $job): bool => $job->webhookCall->getKey() === $calls[0]->getKey());
});

it('processes the event in the job and leaves the row marked processed', function (): void {
    $event = StripeEvents::checkoutCompleted();

    StripeWebhookSigner::post($this, $event)->assertOk();

    $calls = storedStripeCalls();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->processed_at)->not->toBeNull()
        ->and($calls[0]->failed_at)->toBeNull()
        ->and($calls[0]->failure_class)->toBeNull();
});

it('runs the public route without a session, cookie or CSRF middleware', function (): void {
    $route = Route::getRoutes()->getByName('webhooks.stripe');
    $middleware = $route?->gatherMiddleware() ?? ['missing'];

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('webhooks/stripe')
        ->and($route?->methods())->toBe(['POST'])
        ->and($middleware)->toBe([]);

    Queue::fake();
    $response = StripeWebhookSigner::post($this, StripeEvents::checkoutCompleted());

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

// --- Task 2: forged, stale, malformed and repeated deliveries -------------------------------------------------

it('refuses a body that was changed after signing, with 400 and nothing stored', function (): void {
    Queue::fake();
    $body = json_encode(StripeEvents::checkoutCompleted(), JSON_THROW_ON_ERROR);
    $header = StripeWebhookSigner::header($body);
    $changed = str_replace('"amount_total":121000', '"amount_total":1', $body);

    expect($changed)->not->toBe($body);

    StripeWebhookSigner::postRaw($this, $changed, $header)
        ->assertStatus(400)
        ->assertExactJson(['message' => 'invalid signature']);

    expect(storedStripeCalls())->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses a header signed with another secret', function (): void {
    Queue::fake();
    $body = json_encode(StripeEvents::checkoutCompleted(), JSON_THROW_ON_ERROR);

    StripeWebhookSigner::postRaw($this, $body, StripeWebhookSigner::header($body, null, implode('', ['whsec', '_', Str::random(32)])))
        ->assertStatus(400)
        ->assertExactJson(['message' => 'invalid signature']);

    expect(storedStripeCalls())->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses a call without a Stripe-Signature header', function (): void {
    Queue::fake();

    StripeWebhookSigner::postUnsigned($this, StripeEvents::checkoutCompleted())
        ->assertStatus(400)
        ->assertExactJson(['message' => 'invalid signature']);

    expect(storedStripeCalls())->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses a signature whose timestamp is outside the 300 second tolerance and accepts one inside it', function (): void {
    Queue::fake();
    $body = json_encode(StripeEvents::checkoutCompleted(), JSON_THROW_ON_ERROR);

    StripeWebhookSigner::postRaw($this, $body, StripeWebhookSigner::header($body, time() - 301))
        ->assertStatus(400)
        ->assertExactJson(['message' => 'invalid signature']);

    expect(storedStripeCalls())->toBe([]);

    StripeWebhookSigner::postRaw($this, $body, StripeWebhookSigner::header($body, time() - 290))->assertOk();

    expect(storedStripeCalls())->toHaveCount(1);
});

it('fails closed when no webhook secret is configured', function (): void {
    Queue::fake();
    $body = json_encode(StripeEvents::checkoutCompleted(), JSON_THROW_ON_ERROR);
    $header = StripeWebhookSigner::header($body);

    StripeTestConfig::clear();

    // Whatever the header says, an instance without a signing secret processes nothing.
    StripeWebhookSigner::postRaw($this, $body, $header)->assertStatus(400);
    StripeWebhookSigner::postRaw($this, $body, StripeWebhookSigner::header($body, null, ''))->assertStatus(400);

    expect(storedStripeCalls())->toBe([]);
    Queue::assertNothingPushed();
});

dataset('malformed events', [
    'not JSON' => fn (): string => 'this is not json',
    'a JSON list' => fn (): string => json_encode([1, 2, 3], JSON_THROW_ON_ERROR),
    'a JSON string' => fn (): string => json_encode('evt_text', JSON_THROW_ON_ERROR),
    'no id' => fn (): string => json_encode(array_diff_key(StripeEvents::checkoutCompleted(), ['id' => true]), JSON_THROW_ON_ERROR),
    'a numeric id' => fn (): string => json_encode(StripeEvents::checkoutCompleted([], ['id' => 12345]), JSON_THROW_ON_ERROR),
    'an id without the evt prefix' => fn (): string => json_encode(StripeEvents::checkoutCompleted([], ['id' => 'cs_'.Str::random(20)]), JSON_THROW_ON_ERROR),
    'an id over 255 characters' => fn (): string => json_encode(StripeEvents::checkoutCompleted([], ['id' => 'evt_'.Str::random(252)]), JSON_THROW_ON_ERROR),
    'no type' => fn (): string => json_encode(array_diff_key(StripeEvents::checkoutCompleted(), ['type' => true]), JSON_THROW_ON_ERROR),
    'a non-string type' => fn (): string => json_encode(StripeEvents::checkoutCompleted([], ['type' => ['checkout.session.completed']]), JSON_THROW_ON_ERROR),
    'no data' => fn (): string => json_encode(array_diff_key(StripeEvents::checkoutCompleted(), ['data' => true]), JSON_THROW_ON_ERROR),
    'no data object' => fn (): string => json_encode(array_replace(StripeEvents::checkoutCompleted(), ['data' => ['previous_attributes' => []]]), JSON_THROW_ON_ERROR),
    'a data object that is a string' => fn (): string => json_encode(array_replace(StripeEvents::checkoutCompleted(), ['data' => ['object' => 'cs_text']]), JSON_THROW_ON_ERROR),
]);

it('answers a correctly signed body that is no Stripe event with 400 invalid payload and stores nothing', function (string $body): void {
    Queue::fake();

    StripeWebhookSigner::postRaw($this, $body)
        ->assertStatus(400)
        ->assertExactJson(['message' => 'invalid payload']);

    expect(storedStripeCalls())->toBe([]);
    Queue::assertNothingPushed();
})->with('malformed events');

it('acknowledges an unhandled event type with 200 and stores nothing', function (): void {
    Queue::fake();

    StripeWebhookSigner::post($this, StripeEvents::ofType('invoice.paid'))->assertOk();

    expect(storedStripeCalls())->toBe([]);
    Queue::assertNothingPushed();
});

it('stores each of the three handled types', function (string $type): void {
    Queue::fake();

    StripeWebhookSigner::post($this, StripeEvents::ofType($type))->assertOk();

    $calls = storedStripeCalls();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->event_name)->toBe($type);
})->with(['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'refund.created']);

it('stores a repeated delivery once and answers both with 200', function (): void {
    Queue::fake();
    $event = StripeEvents::checkoutCompleted();

    StripeWebhookSigner::post($this, $event)->assertOk();
    StripeWebhookSigner::post($this, $event)->assertOk();

    expect(storedStripeCalls())->toHaveCount(1)
        ->and(DB::table('webhook_calls')->where('event_id', $event['id'])->count())->toBe(1);
});

it('dispatches the job again for a stored event that is not processed, so a lost dispatch is repaired', function (): void {
    Queue::fake();
    $event = StripeEvents::checkoutCompleted();

    StripeWebhookSigner::post($this, $event)->assertOk();
    Queue::assertPushed(ProcessStripeWebhook::class, 1);

    StripeWebhookSigner::post($this, $event)->assertOk();
    Queue::assertPushed(ProcessStripeWebhook::class, 2);

    expect(storedStripeCalls())->toHaveCount(1);
});

it('never dispatches a processed event again', function (): void {
    Queue::fake();
    $event = StripeEvents::checkoutCompleted();

    StripeWebhookSigner::post($this, $event)->assertOk();
    DB::table('webhook_calls')->where('event_id', $event['id'])->update(['processed_at' => now()]);

    StripeWebhookSigner::post($this, $event)->assertOk();

    Queue::assertPushed(ProcessStripeWebhook::class, 1);
    expect(storedStripeCalls())->toHaveCount(1);
});

it('lets the database refuse a second row of the same name and event id', function (): void {
    $event = StripeEvents::checkoutCompleted();
    Queue::fake();
    StripeWebhookSigner::post($this, $event)->assertOk();

    RawSql::expectSqlState('23505', fn () => DB::table('webhook_calls')->insert([
        'name' => 'stripe',
        'url' => 'https://'.implode('.', ['example', 'com']).'/webhooks/stripe',
        'event_id' => $event['id'],
        'event_name' => 'checkout.session.completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    RawSql::expectAllowed(fn () => DB::table('webhook_calls')->insert([
        'name' => 'other',
        'url' => 'https://'.implode('.', ['example', 'com']).'/webhooks/other',
        'event_id' => $event['id'],
        'event_name' => 'checkout.session.completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

it('refuses a stripe row without an event id or type and a row that is failed and processed', function (): void {
    $row = [
        'name' => 'stripe',
        'url' => 'https://'.implode('.', ['example', 'com']).'/webhooks/stripe',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    RawSql::expectSqlState('23514', fn () => DB::table('webhook_calls')->insert($row));
    RawSql::expectSqlState('23514', fn () => DB::table('webhook_calls')->insert([...$row, 'event_id' => StripeEvents::token('evt'), 'event_name' => null]));
    RawSql::expectSqlState('23514', fn () => DB::table('webhook_calls')->insert([
        ...$row,
        'event_id' => StripeEvents::token('evt'),
        'event_name' => 'refund.created',
        'processed_at' => now(),
        'failed_at' => now(),
    ]));
});

it('drops the payer personal data from the stored payload and keeps the rest', function (): void {
    Queue::fake();
    $event = StripeEvents::checkoutCompleted([
        'shipping_details' => ['name' => 'Jane Example', 'address' => ['city' => 'Example Town']],
        'shipping' => ['name' => 'Jane Example'],
        'collected_information' => ['shipping_details' => ['name' => 'Jane Example']],
    ]);
    $object = $event['data']['object'];

    StripeWebhookSigner::post($this, $event)->assertOk();

    $stored = (string) DB::table('webhook_calls')->where('event_id', $event['id'])->value('payload');
    /** @var array{data: array{object: array<string, mixed>}} $payload */
    $payload = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['data']['object'])->not->toHaveKeys(['customer_details', 'customer_email', 'shipping_details', 'shipping', 'collected_information'])
        ->and($payload['data']['object']['id'])->toBe($object['id'])
        ->and($payload['data']['object']['amount_total'])->toBe(121000)
        ->and($payload['data']['object']['currency'])->toBe('czk')
        ->and($payload['data']['object']['metadata'])->toBe($object['metadata'])
        ->and($payload['id'])->toBe($event['id'])
        ->and($stored)->not->toContain(exampleEmail('jane'))
        ->and($stored)->not->toContain('Jane Example');
});

it('keeps no header and no attachment of the call', function (): void {
    Queue::fake();
    $event = StripeEvents::checkoutCompleted();

    StripeWebhookSigner::post($this, $event)->assertOk();

    $row = DB::table('webhook_calls')->where('event_id', $event['id'])->first();

    expect($row?->headers)->toBeNull()
        ->and($row?->attachments)->toBeNull()
        ->and($row?->exception)->toBeNull();
});

// --- Task 3: the job ------------------------------------------------------------------------------------------

/**
 * A handler the tests register through the handler map: it throws what the test sets and counts its runs.
 */
final class ScriptedStripeHandler implements StripeEventHandler
{
    public static ?Throwable $throws = null;

    public static int $runs = 0;

    public function handle(WebhookCall $call): void
    {
        self::$runs++;

        if (self::$throws !== null) {
            throw self::$throws;
        }
    }
}

/**
 * Stores a checkout event without running its job, and returns the stored row.
 */
function unprocessedCall(TestCase $test): WebhookCall
{
    Queue::fake();
    StripeWebhookSigner::post($test, StripeEvents::checkoutCompleted())->assertOk();

    return storedStripeCalls()[0];
}

/**
 * The job as the queue runs it: through the system-context middleware, from the serialized form.
 */
function runJob(WebhookCall $call): void
{
    /** @var ProcessStripeWebhook $job */
    $job = unserialize(serialize(new ProcessStripeWebhook($call)));

    app(PartnerContext::class)->runAsSystem(static fn () => $job->handle());
}

/**
 * The stored row again, as the database holds it.
 */
function reloaded(WebhookCall $call): stdClass
{
    return DB::table('webhook_calls')->where('id', $call->getKey())->first() ?? throw new LogicException('The webhook row is gone.');
}

beforeEach(function (): void {
    ScriptedStripeHandler::$throws = null;
    ScriptedStripeHandler::$runs = 0;
});

it('does nothing on a second run of a processed event', function (): void {
    $call = unprocessedCall($this);
    $this->travelTo(now()->startOfSecond());

    runJob($call);
    $first = reloaded($call);

    $this->travel(2)->hours();
    runJob($call);
    $second = reloaded($call);

    expect($first->processed_at)->not->toBeNull()
        ->and($second->processed_at)->toBe($first->processed_at)
        ->and($second->updated_at)->toBe($first->updated_at);
});

it('runs the registered handler once for its type and marks the event processed in the same step', function (): void {
    app()->instance(ProcessStripeWebhook::HANDLERS_BINDING, ['checkout.session.completed' => ScriptedStripeHandler::class]);
    $call = unprocessedCall($this);

    runJob($call);
    runJob($call);

    expect(ScriptedStripeHandler::$runs)->toBe(1)
        ->and(reloaded($call)->processed_at)->not->toBeNull();
});

it('leaves an event unprocessed when its handler throws, and marks it failed with a fixed class only after the last try', function (): void {
    app()->instance(ProcessStripeWebhook::HANDLERS_BINDING, ['checkout.session.completed' => ScriptedStripeHandler::class]);
    $call = unprocessedCall($this);
    $secretText = 'Jane Example paid '.Str::random(12);
    ScriptedStripeHandler::$throws = ValidationException::withMessages(['rate' => $secretText]);

    expect(fn () => runJob($call))->toThrow(ValidationException::class);

    $afterTry = reloaded($call);

    expect($afterTry->processed_at)->toBeNull()
        ->and($afterTry->failed_at)->toBeNull();

    (new ProcessStripeWebhook($call))->failed(ScriptedStripeHandler::$throws);

    $row = reloaded($call);

    expect($row->failed_at)->not->toBeNull()
        ->and($row->failure_class)->toBe('validation')
        ->and($row->processed_at)->toBeNull()
        ->and($row->exception)->toBeNull()
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain($secretText)
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('Jane Example');
});

it('marks any other failure as unexpected without its message', function (): void {
    $call = unprocessedCall($this);
    $message = 'SQLSTATE connection to host '.Str::random(12).' refused';

    (new ProcessStripeWebhook($call))->failed(new RuntimeException($message));

    $row = reloaded($call);

    expect($row->failure_class)->toBe('unexpected')
        ->and($row->failed_at)->not->toBeNull()
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain($message);
});

it('clears the failure when a later run succeeds', function (): void {
    app()->instance(ProcessStripeWebhook::HANDLERS_BINDING, ['checkout.session.completed' => ScriptedStripeHandler::class]);
    $call = unprocessedCall($this);
    ScriptedStripeHandler::$throws = new RuntimeException('Fictional failure');

    expect(fn () => runJob($call))->toThrow(RuntimeException::class);
    (new ProcessStripeWebhook($call))->failed(ScriptedStripeHandler::$throws);

    expect(reloaded($call)->failed_at)->not->toBeNull();

    ScriptedStripeHandler::$throws = null;
    runJob($call);

    $row = reloaded($call);

    expect($row->processed_at)->not->toBeNull()
        ->and($row->failed_at)->toBeNull()
        ->and($row->failure_class)->toBeNull();
});

it('does not mark a processed event failed', function (): void {
    $call = unprocessedCall($this);
    runJob($call);

    (new ProcessStripeWebhook($call))->failed(new RuntimeException('Fictional failure'));

    $row = reloaded($call);

    expect($row->processed_at)->not->toBeNull()
        ->and($row->failed_at)->toBeNull()
        ->and($row->failure_class)->toBeNull();
});

it('dispatches a failed but unprocessed event again on redelivery', function (): void {
    $event = StripeEvents::checkoutCompleted();
    Queue::fake();
    StripeWebhookSigner::post($this, $event)->assertOk();
    $call = storedStripeCalls()[0];
    (new ProcessStripeWebhook($call))->failed(new RuntimeException('Fictional failure'));

    StripeWebhookSigner::post($this, $event)->assertOk();

    Queue::assertPushed(ProcessStripeWebhook::class, 2);
});

it('runs through the system-context middleware and carries the retry contract of the base job', function (): void {
    $job = new ProcessStripeWebhook(new WebhookCall);
    $reflection = new ReflectionClass(ProcessStripeWebhook::class);

    expect($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(RunsAsSystem::class)
        ->and($reflection->getAttributes(Tries::class)[0]->getArguments())->toBe([3])
        ->and($reflection->getAttributes(Backoff::class)[0]->getArguments())->toBe([10, 60, 300])
        ->and($reflection->getAttributes(Timeout::class)[0]->getArguments())->toBe([60]);
});
