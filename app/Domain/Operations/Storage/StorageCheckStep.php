<?php

declare(strict_types=1);

namespace App\Domain\Operations\Storage;

/**
 * The outcome of one step of the storage check.
 *
 * $error is the exception class plus the provider's error code, or a short fixed
 * code such as "http_403". It never carries an exception message, a URL or a
 * configuration value, because the text is printed to terminals and CI logs.
 */
final readonly class StorageCheckStep
{
    public function __construct(
        public string $name,
        public bool $ok,
        public int $milliseconds,
        public ?string $error = null,
    ) {}
}
