<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Closure;

/**
 * Resolves the source label of the activity written right now (D-08).
 *
 * Resolution order: an explicit as() wrapper (the innermost wins), else inside
 * a queue job, else a console process, else web. The label comes from the
 * execution context only, never from input.
 *
 * Registered as a container singleton with a console detector, so a test can
 * simulate a web request (the test runner is always a console process). The
 * queue listeners call enterJob() on JobProcessing and leaveJob() on
 * JobProcessed and JobExceptionOccurred; exactly one of the two leaving events
 * follows every processing event, and the depth never goes below zero.
 */
final class ActivitySource
{
    private int $jobDepth = 0;

    /** @var list<ActivitySourceLabel> */
    private array $explicit = [];

    /**
     * @param  Closure(): bool  $runningInConsole
     */
    public function __construct(private readonly Closure $runningInConsole) {}

    public function current(): ActivitySourceLabel
    {
        if ($this->explicit !== []) {
            return $this->explicit[array_key_last($this->explicit)];
        }

        if ($this->jobDepth > 0) {
            return ActivitySourceLabel::Job;
        }

        return ($this->runningInConsole)() ? ActivitySourceLabel::Console : ActivitySourceLabel::Web;
    }

    /**
     * Runs the callback with an explicit label and restores the previous one
     * afterwards, also when the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function as(ActivitySourceLabel $label, callable $callback): mixed
    {
        $this->explicit[] = $label;

        try {
            return $callback();
        } finally {
            array_pop($this->explicit);
        }
    }

    public function enterJob(): void
    {
        $this->jobDepth++;
    }

    public function leaveJob(): void
    {
        $this->jobDepth = max(0, $this->jobDepth - 1);
    }
}
