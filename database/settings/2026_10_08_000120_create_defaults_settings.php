<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsMigration;

return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        $this->migrator->add('defaults.default_currency', 'CZK');
        $this->migrator->add('defaults.default_hourly_rate', ['minor' => 0, 'currency' => 'CZK']);
    }
};
