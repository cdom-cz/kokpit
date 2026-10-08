<?php

declare(strict_types=1);

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Settings\Settings\SupplierSettings;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Tests\Support\Canary;
use Tests\Support\Probes\SettingsReadingProbeJob;
use Tests\Support\RedisTestQueue;

/*
 * The job contract on the real Redis queue (D-10, research Pitfalls 2 and 4):
 * the base retry policy travels in the payload, a job's own attributes win, a
 * job dispatched inside a transaction stays invisible until the commit, and a
 * job reads fail-closed data through the system context.
 */

#[Idempotent(how: 'Does nothing, so a second run changes nothing')]
final class QueueContractBareJob extends KokpitJob
{
    public function handle(): void {}
}

#[Idempotent(how: 'Does nothing, so a second run changes nothing')]
#[Tries(5)]
#[Backoff(1, 2)]
final class QueueContractOverridingJob extends KokpitJob
{
    public function handle(): void {}
}

/**
 * The decoded payloads waiting in the Redis list of this queue.
 *
 * @return list<array<string, mixed>>
 */
function queueContractPayloads(string $queue): array
{
    $connection = config('queue.connections.redis.connection');
    $raw = Redis::connection(is_string($connection) ? $connection : 'default')->lrange('queues:'.$queue, 0, -1);

    return array_values(array_map(
        static fn (string $body): array => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        $raw,
    ));
}

/**
 * Stores fictional supplier settings as Admin, then leaves the request without a user.
 */
function queueContractStoreSupplier(): void
{
    test()->actingAs(Canary::admin());

    $settings = app(SupplierSettings::class);
    $settings->company_name = 'Example s.r.o.';
    $settings->street = 'Sample Street 1';
    $settings->city = 'Sampletown';
    $settings->postal_code = '10000';
    $settings->country = 'CZ';
    $settings->company_id = '12345678';
    $settings->vat_id = null;
    $settings->email = exampleEmail();
    $settings->phone = null;
    $settings->website = null;
    $settings->registration_note = null;
    $settings->save();

    app()->forgetScopedInstances();
    auth()->logout();
}

beforeEach(function (): void {
    $this->queue = RedisTestQueue::name();
    SettingsReadingProbeJob::$companyName = null;
});

afterEach(function (): void {
    RedisTestQueue::flush($this->queue);
});

it('puts the base retry policy in the payload of a pushed job', function (): void {
    QueueContractBareJob::dispatch()->onConnection('redis')->onQueue($this->queue);

    $payloads = queueContractPayloads($this->queue);

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['maxTries'])->toBe(3)
        ->and($payloads[0]['backoff'])->toBe('10,60,300')
        ->and($payloads[0]['timeout'])->toBe(60);
});

it('lets a job that declares its own tries and backoff override the base defaults', function (): void {
    QueueContractOverridingJob::dispatch()->onConnection('redis')->onQueue($this->queue);

    $payloads = queueContractPayloads($this->queue);

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['maxTries'])->toBe(5)
        ->and($payloads[0]['backoff'])->toBe('1,2')
        ->and($payloads[0]['timeout'])->toBe(60);
});

it('keeps a job dispatched inside a transaction invisible to workers until the commit', function (): void {
    expect(config('queue.connections.redis.after_commit'))->toBeTrue();

    DB::transaction(function (): void {
        QueueContractBareJob::dispatch()->onConnection('redis')->onQueue($this->queue);

        expect(RedisTestQueue::pending($this->queue))->toBe(0);
    });

    expect(RedisTestQueue::pending($this->queue))->toBe(1);
});

it('never pushes a job whose transaction rolled back', function (): void {
    try {
        DB::transaction(function (): void {
            QueueContractBareJob::dispatch()->onConnection('redis')->onQueue($this->queue);

            throw new RuntimeException('Fictional rollback');
        });
    } catch (RuntimeException) {
        // The rollback is the point of the test.
    }

    expect(RedisTestQueue::pending($this->queue))->toBe(0);
});

it('reads fail-closed settings in a job without a signed-in user, while the same read outside fails closed', function (): void {
    queueContractStoreSupplier();

    expect(auth()->check())->toBeFalse()
        ->and(fn (): string => app(SupplierSettings::class)->company_name)->toThrow(MissingSettings::class);

    app()->forgetScopedInstances();

    SettingsReadingProbeJob::dispatch()->onConnection('sync');

    expect(SettingsReadingProbeJob::$companyName)->toBe('Example s.r.o.');
});
