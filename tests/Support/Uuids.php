<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Shared UUID assertions for tests.
 */
final class Uuids
{
    /** Canonical lower-case version 7 UUID with the RFC 4122 variant. */
    public const string V7_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
}
