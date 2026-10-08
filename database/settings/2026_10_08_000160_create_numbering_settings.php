<?php

declare(strict_types=1);

use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\SettingsMigration;

return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        $this->migrator->add('numbering.invoice_pattern', DocumentKind::Invoice->defaultPattern());
        $this->migrator->add('numbering.proforma_pattern', DocumentKind::Proforma->defaultPattern());
        $this->migrator->add('numbering.credit_note_pattern', DocumentKind::CreditNote->defaultPattern());
        $this->migrator->add('numbering.task_pattern', DocumentKind::Task->defaultPattern());
    }
};
