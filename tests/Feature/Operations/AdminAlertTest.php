<?php

declare(strict_types=1);

use App\Domain\Operations\Alerts\AdminAlerter;
use App\Domain\Operations\Alerts\OperationalAlert;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\Canary;

/*
 * The Admin alert for a job that failed for good (D-11): throttled per job
 * class, free of payloads, traces and long messages, sent on each channel on
 * its own, and never able to hide the failed job. The JobFailed event is raised
 * directly with a mocked queue job; the real-Redis flow is FailingJobFlowTest.
 */

const ALERT_JOB = 'App\\Jobs\\FictionalReportJob';

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A raised line that has a stack frame named after this function.
 */
function throwFromDeepFrame(string $message): never
{
    throw new RuntimeException($message);
}

function caught(string $message): Throwable
{
    try {
        throwFromDeepFrame($message);
    } catch (Throwable $e) {
        return $e;
    }
}

/**
 * A JobFailed event for a mocked queue job. The job exposes a payload and a raw
 * body full of a canary so a test can prove that neither leaves the system.
 */
function failedEvent(string $job = ALERT_JOB, string $message = 'Fictional failure', string $payloadCanary = 'CANARY_PAYLOAD_UNSET', ?Throwable $exception = null): JobFailed
{
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('resolveName')->andReturn($job);
    $queueJob->shouldReceive('getQueue')->andReturn('fictional-queue');
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $queueJob->shouldReceive('uuid')->andReturn('0190aaaa-bbbb-7ccc-8ddd-eeeeffff0001');
    // Horizon's own JobFailed listeners read the job id.
    $queueJob->shouldReceive('getJobId')->andReturn('0190aaaa-bbbb-7ccc-8ddd-eeeeffff0001');
    $queueJob->shouldReceive('payload')->andReturn(['data' => ['command' => $payloadCanary]]);
    $queueJob->shouldReceive('getRawBody')->andReturn(json_encode(['data' => ['command' => $payloadCanary]]));

    return new JobFailed('redis', $queueJob, $exception ?? caught($message));
}

/**
 * Raises the event and then runs what the listener deferred. The worker runs the
 * deferred alert on JobAttempted, after all JobFailed listeners; a test that raises
 * JobFailed by hand has to play that second step itself.
 */
function raiseFailure(JobFailed $event): void
{
    event($event);
    defer()->invoke();
}

function sentMails(): Collection
{
    return Mail::getSymfonyTransport()->messages();
}

function bellRows(object $admin): Collection
{
    return DB::table('notifications')->where('notifiable_id', $admin->getKey())->orderBy('created_at')->get();
}

/**
 * @return array<string, mixed>
 */
function bellData(object $row): array
{
    return json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR);
}

it('alerts once for two failures of the same job class inside the window and reports the suppressed count with the next alert', function (): void {
    $admin = Canary::admin();
    Carbon::setTestNow('2026-10-08 10:00:00');

    raiseFailure(failedEvent());
    Carbon::setTestNow('2026-10-08 10:05:00');
    raiseFailure(failedEvent());

    expect(sentMails())->toHaveCount(1)
        ->and(bellRows($admin))->toHaveCount(1);

    Carbon::setTestNow('2026-10-08 10:16:00');
    raiseFailure(failedEvent());

    $rows = bellRows($admin);
    expect(sentMails())->toHaveCount(2)
        ->and($rows)->toHaveCount(2)
        ->and(bellData($rows->last())['body'])->toContain(__('kokpit.alerts.suppressed', ['count' => 1]))
        ->and(bellData($rows->first())['body'])->not->toContain(__('kokpit.alerts.suppressed', ['count' => 1]));
});

it('counts every suppressed failure of the window', function (): void {
    $admin = Canary::admin();
    Carbon::setTestNow('2026-10-08 10:00:00');

    foreach (range(1, 4) as $minute) {
        Carbon::setTestNow(Carbon::parse('2026-10-08 10:00:00')->addMinutes($minute));
        raiseFailure(failedEvent());
    }

    Carbon::setTestNow('2026-10-08 10:20:00');
    raiseFailure(failedEvent());

    expect(bellData(bellRows($admin)->last())['body'])->toContain(__('kokpit.alerts.suppressed', ['count' => 3]));
});

it('alerts separately for two different job classes inside the window', function (): void {
    $admin = Canary::admin();
    Carbon::setTestNow('2026-10-08 10:00:00');

    raiseFailure(failedEvent('App\\Jobs\\FictionalReportJob'));
    raiseFailure(failedEvent('App\\Jobs\\FictionalExportJob'));

    expect(sentMails())->toHaveCount(2)
        ->and(bellRows($admin))->toHaveCount(2);
});

it('still sends the alert when the cache fails', function (): void {
    $admin = Canary::admin();
    Cache::partialMock()->shouldReceive('add')->andThrow(new RuntimeException('cache down'));

    raiseFailure(failedEvent());

    expect(sentMails())->toHaveCount(1)
        ->and(bellRows($admin))->toHaveCount(1);
});

it('delivers the mail and the bell row without pushing anything to a queue', function (): void {
    $admin = Canary::admin();
    Queue::fake();

    raiseFailure(failedEvent());

    expect(sentMails())->toHaveCount(1)
        ->and(bellRows($admin))->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('stores the bell row and logs a critical entry when the mail transport throws', function (): void {
    $admin = Canary::admin();
    Log::spy();
    Mail::extend('fictional-broken', fn (): TransportInterface => new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new RuntimeException('mail transport down');
        }

        public function __toString(): string
        {
            return 'fictional-broken';
        }
    });
    config(['mail.mailers.fictional-broken' => ['transport' => 'fictional-broken'], 'mail.default' => 'fictional-broken']);

    raiseFailure(failedEvent());

    expect(bellRows($admin))->toHaveCount(1);
    Log::shouldHaveReceived('critical')->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'mail') && ($context['channel'] ?? null) === 'mail',
    )->once();
});

it('sends the mail and logs a critical entry when the bell row cannot be stored', function (): void {
    $admin = Canary::admin();
    Log::spy();
    Event::listen(NotificationSending::class, function (NotificationSending $event): void {
        if ($event->channel === 'database') {
            throw new RuntimeException('database channel down');
        }
    });

    raiseFailure(failedEvent());

    expect(sentMails())->toHaveCount(1)
        ->and(bellRows($admin))->toHaveCount(0);
    Log::shouldHaveReceived('critical')->withArgs(
        fn (string $message, array $context = []): bool => ($context['channel'] ?? null) === 'database',
    )->once();
});

it('returns normally and logs critical when the alerter itself throws', function (): void {
    Canary::admin();
    Log::spy();
    app()->instance(AdminAlerter::class, new class extends AdminAlerter
    {
        public function alert(string $throttleKey, OperationalAlert $alert): void
        {
            throw new RuntimeException('alerter down');
        }
    });

    raiseFailure(failedEvent());

    Log::shouldHaveReceived('critical')->once();
});

it('puts at most 200 characters of the first message line and no payload or stack trace into the alert', function (): void {
    $admin = Canary::admin();
    $head = Canary::canary('head');
    $tail = Canary::canary('tail');
    $second = Canary::canary('second');
    $payload = Canary::canary('payload');
    $firstLine = str_pad(str_pad($head, 250, 'x').$tail, 300, 'y');

    raiseFailure(failedEvent(message: $firstLine."\n".$second, payloadCanary: $payload));

    $kept = mb_substr($firstLine, 0, 200);
    $data = bellData(bellRows($admin)->first());
    $mail = sentMails()->first()->getOriginalMessage();
    $mailText = (string) $mail->getTextBody().(string) $mail->getHtmlBody();

    expect($data['body'])->toContain($kept)->toContain(RuntimeException::class)->toContain('fictional-queue')
        ->toContain('0190aaaa-bbbb-7ccc-8ddd-eeeeffff0001')->toContain(ALERT_JOB);

    foreach ([$data['body'], $mailText] as $text) {
        expect($text)->toContain($kept)
            ->not->toContain(mb_substr($firstLine, 0, 201))
            ->not->toContain($tail)
            ->not->toContain($second)
            ->not->toContain($payload)
            ->not->toContain('throwFromDeepFrame')
            ->not->toContain('Stack trace');
    }

    expect($mailText)->toContain(RuntimeException::class)->toContain('fictional-queue');
});

it('links the alert to the System page', function (): void {
    $admin = Canary::admin();

    raiseFailure(failedEvent());

    $data = bellData(bellRows($admin)->first());
    $url = $data['actions'][0]['url'];

    expect($url)->toBe(route('filament.admin.pages.system'))
        ->and(parse_url($url, PHP_URL_PATH))->toEndWith('/system');

    $mail = sentMails()->first()->getOriginalMessage();
    expect((string) $mail->getTextBody().(string) $mail->getHtmlBody())->toContain($url);
});

it('does not deliver anything while the JobFailed listeners run, only when the deferred step runs', function (): void {
    $admin = Canary::admin();

    event(failedEvent());

    expect(sentMails())->toHaveCount(0)
        ->and(bellRows($admin))->toHaveCount(0);

    defer()->invoke();

    expect(sentMails())->toHaveCount(1)
        ->and(bellRows($admin))->toHaveCount(1);
});

it('delivers a timeout failure inline, because the worker exits right after JobFailed', function (): void {
    $admin = Canary::admin();
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('resolveName')->andReturn(ALERT_JOB);
    $timeout = TimeoutExceededException::forJob($queueJob);

    event(failedEvent(exception: $timeout));

    expect(sentMails())->toHaveCount(1)
        ->and(bellRows($admin))->toHaveCount(1);
});

it('bounds the SMTP socket timeout well below the job timeout', function (): void {
    $timeout = config('mail.mailers.smtp.timeout');

    expect($timeout)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(15);
});

it('keeps hosts, addresses and database detail of the exception message out of the mail and the bell', function (): void {
    $admin = Canary::admin();
    $host = implode('.', ['db', 'internal', 'example', 'com']);
    $ip = implode('.', [10, 20, 30, 40]);
    $leak = 'SQLSTATE[08006] connection to server at "'.$host.'" ('.$ip.'), port 5432 failed (Connection: pgsql, SQL: select * from "clients" where "email" = jane@example.com)';

    raiseFailure(failedEvent(message: $leak));

    $mail = sentMails()->first()->getOriginalMessage();
    $mailText = (string) $mail->getTextBody().(string) $mail->getHtmlBody();
    $body = bellData(bellRows($admin)->first())['body'];

    foreach ([$mailText, $body] as $text) {
        expect($text)->toContain(RuntimeException::class)
            ->not->toContain($host)
            ->not->toContain($ip)
            ->not->toContain('5432')
            ->not->toContain('jane@example.com')
            ->not->toContain('select *');
    }
});
