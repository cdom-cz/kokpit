<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Audit\ActivitySource;
use App\Domain\Audit\ActivitySourceLabel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * A test-only queued job that writes activity probes. Probe titles tell the
 * test which write happened where: "inside" runs in the webhook wrapper,
 * "after" runs once the wrapper has returned.
 */
final class ActivityProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const string MODE_PLAIN = 'plain';

    public const string MODE_WEBHOOK = 'webhook';

    public const string MODE_FAIL = 'fail';

    public function __construct(public readonly string $mode = self::MODE_PLAIN) {}

    public function handle(ActivitySource $source): void
    {
        match ($this->mode) {
            self::MODE_WEBHOOK => $this->writeWebhookSequence($source),
            self::MODE_FAIL => $this->writeThenFail(),
            default => ActivityProbe::query()->create(['title' => 'job plain']),
        };
    }

    private function writeWebhookSequence(ActivitySource $source): void
    {
        ActivityProbe::query()->create(['title' => 'job before']);
        $source->as(ActivitySourceLabel::Webhook, function (): void {
            ActivityProbe::query()->create(['title' => 'job inside']);
        });
        ActivityProbe::query()->create(['title' => 'job after']);
    }

    private function writeThenFail(): never
    {
        ActivityProbe::query()->create(['title' => 'job before failure']);

        throw new RuntimeException('Fictional job failure');
    }
}
