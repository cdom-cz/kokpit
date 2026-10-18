<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

/**
 * A test-only webhook job that keeps the retry attributes but declares neither #[Idempotent] nor the system context,
 * so the architecture self-check can prove that JobDeclaration::webhookJobProblems() reports both gaps.
 */
#[Tries(3)]
#[Backoff(10, 60, 300)]
#[Timeout(60)]
final class UndeclaredWebhookProbeJob extends ProcessWebhookJob
{
    public function handle(): void {}
}
