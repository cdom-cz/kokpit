<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsMigration;

return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        $this->migrator->add('invoicing.vat_mode', 'non_payer');
        $this->migrator->add('invoicing.payment_due_days', 14);
    }
};
