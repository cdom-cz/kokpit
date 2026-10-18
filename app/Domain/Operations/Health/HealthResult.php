<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health;

/**
 * What one indicator measured: a status, a short value and an optional detail.
 *
 * The detail is shown to the Admin as text. It must never carry an exception
 * message, a connection string or any other raw infrastructure text.
 *
 * An indicator may also point the Admin at the screen that fixes the problem: `url` is an internal link and
 * `urlLabel` its fixed Czech text (the System page renders it next to the detail).
 */
final readonly class HealthResult
{
    public function __construct(
        public HealthStatus $status,
        public ?string $value = null,
        public ?string $detail = null,
        public ?string $url = null,
        public ?string $urlLabel = null,
    ) {}
}
