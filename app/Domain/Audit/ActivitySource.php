<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Closure;

/**
 * Placeholder for the failing-test commit: every context reads as web and the
 * wrapper only calls through. The real resolution follows in the next commit.
 */
final class ActivitySource
{
    public function __construct(private readonly Closure $runningInConsole) {}

    public function current(): ActivitySourceLabel
    {
        return ActivitySourceLabel::Web;
    }

    public function as(ActivitySourceLabel $label, callable $callback): mixed
    {
        return $callback();
    }

    public function enterJob(): void {}

    public function leaveJob(): void {}
}
