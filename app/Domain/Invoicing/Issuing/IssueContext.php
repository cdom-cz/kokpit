<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Issuing;

use App\Domain\TimeTracking\Support\TimerClock;
use Filament\Support\Facades\FilamentTimezone;

/**
 * What the issue pipeline needs from the outside (ruling R-12-2): the day it runs as a Prague calendar day, and,
 * for a document that must carry the exchange rate of another document (a credit note copies the rate of the
 * invoice it credits, plan 10-23), the CNB figures to copy instead of looking one up.
 *
 * Passing the day in keeps the pipeline free of the clock, so the pre-check of the UI and the transaction of the
 * Action agree on "today" and a test can name the day.
 */
final readonly class IssueContext
{
    /**
     * @param  string  $today  the current Prague calendar day, `Y-m-d`
     * @param  array{rate: string, unit_amount: int, rate_date: string}|null  $exchangeSnapshot  the CNB trio to copy
     */
    public function __construct(
        public string $today,
        public ?array $exchangeSnapshot = null,
    ) {}

    /**
     * Today as the Prague calendar day, no exchange snapshot to copy.
     */
    public static function now(): self
    {
        return new self(TimerClock::now()->setTimezone(FilamentTimezone::get())->format('Y-m-d'));
    }
}
