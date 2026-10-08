<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Settings\Settings\SupplierSettings;

/**
 * A test-only job that reads fail-closed data (the stored supplier settings) in
 * handle(). It sees the data only because KokpitJob puts the system-context
 * middleware in front of it; without a signed-in user the same read fails closed.
 */
#[Idempotent(how: 'Only reads a setting and records it in memory, so a second run changes nothing')]
final class SettingsReadingProbeJob extends KokpitJob
{
    /** Set in the worker process, which is the test process itself. */
    public static ?string $companyName = null;

    public function handle(): void
    {
        self::$companyName = app(SupplierSettings::class)->company_name;
    }
}
