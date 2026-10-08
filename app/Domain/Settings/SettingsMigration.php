<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Shared\Auth\PartnerContext;
use Spatie\LaravelSettings\Migrations\SettingsMigration as BaseSettingsMigration;

/**
 * Base of every settings migration (D-01).
 *
 * A settings migration runs from the console without a signed-in user, where
 * the fail-closed SettingsProperty scope would hide every stored row from an
 * update or rename. The body therefore runs inside the system context. The
 * entry point is final so a subclass cannot forget the wrapper.
 */
abstract class SettingsMigration extends BaseSettingsMigration
{
    final public function up(): void
    {
        app(PartnerContext::class)->runAsSystem(function (): void {
            $this->migrate();
        });
    }

    abstract protected function migrate(): void;
}
