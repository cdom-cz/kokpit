<?php

declare(strict_types=1);

namespace App\Domain\Settings\Numbering;

/**
 * When a counter series starts again at 1. It follows from the date tokens of
 * a pattern: year and month give Monthly, a year alone Yearly, no date Never.
 * The reset is a new scope key; no counter row is ever rewritten.
 */
enum ResetPeriod
{
    case Yearly;
    case Monthly;
    case Never;
}
