<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsMigration;

return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        $this->migrator->add('bank.accounts', []);
    }
};
