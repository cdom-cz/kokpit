<?php

declare(strict_types=1);

namespace App\Domain\Operations\Jobs;

use Attribute;

/**
 * Mandatory idempotence declaration of a queued job (D-10).
 *
 * A job can run twice: a worker can die after the work and before the
 * acknowledgement, and a retry repeats the whole handle(). Every concrete
 * KokpitJob therefore states in one sentence how a second run stays harmless,
 * for example "unique constraint on (currency, rate_date); the job checks the
 * row first". The text is for the reader and for the architecture test, which
 * refuses an empty one. Same style as #[NotPartnerScoped(reason)].
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Idempotent
{
    public function __construct(public readonly string $how) {}
}
