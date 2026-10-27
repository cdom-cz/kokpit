<?php

declare(strict_types=1);

use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\SettingsMigration;

/*
 * Adds the cash receipt number pattern to installations that were set up
 * before cash receipts existed (Phase 15, D-09).
 *
 * The pattern is added only when it is missing, so a fresh install (where the
 * settings are created without it) and a repeated run both end with exactly one
 * row, and a pattern the Admin already chose is never overwritten. Numbers that
 * were handed out never change: this only adds a setting for a new series.
 */
return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        if ($this->migrator->exists('numbering.cash_receipt_pattern')) {
            return;
        }

        $this->migrator->add('numbering.cash_receipt_pattern', DocumentKind::CashReceipt->defaultPattern());
    }
};
