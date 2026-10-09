<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsMigration;

return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        $this->migrator->add('supplier.company_name', '');
        $this->migrator->add('supplier.street', '');
        $this->migrator->add('supplier.city', '');
        $this->migrator->add('supplier.postal_code', '');
        $this->migrator->add('supplier.country', 'CZ');
        $this->migrator->add('supplier.company_id', null);
        $this->migrator->add('supplier.vat_id', null);
        $this->migrator->add('supplier.email', null);
        $this->migrator->add('supplier.phone', null);
        $this->migrator->add('supplier.website', null);
        $this->migrator->add('supplier.registration_note', null);
    }
};
