<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsMigration;

return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        $this->migrator->add('invoicing.document_accent_color', '#ff6f00');
        $this->migrator->add('invoicing.document_footer', null);
        $this->migrator->add('supplier.logo_path', null);
        $this->migrator->add('supplier.signature_path', null);
    }
};
