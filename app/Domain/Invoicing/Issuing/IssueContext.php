<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Issuing;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Support\TimerClock;
use Filament\Support\Facades\FilamentTimezone;

/**
 * What the issue pipeline needs from the outside (ruling R-12-2): the day it runs as a Prague calendar day, and,
 * for a document that must carry the exchange rate of another document (a credit note copies the rate of the
 * invoice it credits, plan 10-23), the CNB figures to copy instead of looking one up.
 *
 * The acting Admin travels with the context (ruling R-20-4): the PDF of the document is stored through the hardened
 * upload path, which authorises the `attach` ability for the actor.
 *
 * Passing the day in keeps the pipeline free of the clock, so the pre-check of the UI and the transaction of the
 * Action agree on "today" and a test can name the day.
 */
final readonly class IssueContext
{
    /**
     * @param  User  $actor  the Admin issuing the document
     * @param  string  $today  the current Prague calendar day, `Y-m-d`
     * @param  array{rate: string, unit_amount: int, rate_date: string}|null  $exchangeSnapshot  the CNB trio to copy
     * @param  array<string, mixed>|null  $bankSnapshot  the bank details to copy
     */
    public function __construct(
        public User $actor,
        public string $today,
        public ?array $exchangeSnapshot = null,
        public ?array $bankSnapshot = null,
    ) {}

    /**
     * Today as the Prague calendar day, no exchange snapshot to copy, issued by the given Admin.
     */
    public static function now(User $actor): self
    {
        return new self($actor, self::today());
    }

    /**
     * The current Prague calendar day, `Y-m-d`: the day the pre-checks and the issue agree on.
     */
    public static function today(): string
    {
        return TimerClock::now()->setTimezone(FilamentTimezone::get())->format('Y-m-d');
    }
}
