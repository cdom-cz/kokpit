<?php

declare(strict_types=1);

use App\Domain\Operations\Alerts\OperationalAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Canary;
use Tests\Support\Probes\FailingProbeJob;
use Tests\Support\RedisTestQueue;

/*
 * The failure path of a background job on the real Redis queue (D-10, D-11): a
 * job that always fails is tried three times, ends in failed_jobs and raises one
 * Admin alert by e-mail and in the Filament bell, without the alert touching the queue.
 */

beforeEach(function (): void {
    $this->queue = RedisTestQueue::name();
    FailingProbeJob::$attempts = 0;
});

afterEach(function (): void {
    RedisTestQueue::flush($this->queue);
});

it('tries a failing job three times, records it in failed_jobs and alerts the Admin once', function (): void {
    $admin = Canary::admin();

    FailingProbeJob::dispatch()->onConnection('redis')->onQueue($this->queue);
    RedisTestQueue::work($this->queue);

    expect(FailingProbeJob::$attempts)->toBe(3)
        ->and(app('queue.failer')->count())->toBe(1)
        ->and(RedisTestQueue::pending($this->queue))->toBe(0);

    $messages = Mail::getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getEnvelope()->getRecipients()[0]->getAddress())->toBe($admin->email);

    $rows = DB::table('notifications')->where('notifiable_id', $admin->getKey())->get();
    expect($rows)->toHaveCount(1);

    $data = json_decode((string) $rows->first()->data, true, 512, JSON_THROW_ON_ERROR);
    expect($data['format'])->toBe('filament')
        ->and($data['status'])->toBe('danger')
        ->and($data['body'])->toContain(FailingProbeJob::class);
});

it('sends the alert without the queue: the alert class is not queueable', function (): void {
    expect(class_implements(OperationalAlert::class))->not->toHaveKey(ShouldQueue::class)
        ->and(class_uses_recursive(OperationalAlert::class))->not->toHaveKey(Queueable::class);
});

it('has written the failed_jobs row before the alert is sent, on every channel', function (): void {
    Canary::admin();
    $seen = [];
    Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$seen): void {
        $seen[$event->channel] = app('queue.failer')->count();
    });

    FailingProbeJob::dispatch()->onConnection('redis')->onQueue($this->queue);
    RedisTestQueue::work($this->queue);

    expect($seen)->toBe(['database' => 1, 'mail' => 1]);
});

it('keeps the failed_jobs row and the job out of the queue when the alert delivery throws on every channel', function (): void {
    Canary::admin();
    Event::listen(NotificationSending::class, function (): void {
        throw new RuntimeException('alert delivery down');
    });

    FailingProbeJob::dispatch()->onConnection('redis')->onQueue($this->queue);
    RedisTestQueue::work($this->queue);

    expect(FailingProbeJob::$attempts)->toBe(3)
        ->and(app('queue.failer')->count())->toBe(1)
        ->and(RedisTestQueue::pending($this->queue))->toBe(0);
});
