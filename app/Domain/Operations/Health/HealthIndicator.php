<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health;

/**
 * One measurement behind one slot of the System page (D-13).
 *
 * check() may throw; the registry turns that into an Error result. It must not
 * report Ok for something it could not measure.
 */
interface HealthIndicator
{
    public function slot(): HealthSlot;

    public function check(): HealthResult;
}
