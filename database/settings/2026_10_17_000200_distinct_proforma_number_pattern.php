<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsMigration;
use Illuminate\Support\Facades\DB;

/*
 * Moves the proforma number pattern from the old default to the distinct one
 * (ruling R-07-3, D-10, research Pitfall 13).
 *
 * The proforma number is printed as the variable symbol of its payment QR. With
 * the old default {YYYY}{NNNN} a proforma and an invoice of the same year could
 * carry the same symbol, so the new default {YYYY}9{NNNN} has one digit more.
 *
 * The switch is guarded twice, because an issued number must never change and
 * an Admin's own choice must never be overwritten:
 *  - only the unchanged old default is replaced, any other stored pattern stays;
 *  - only while no proforma counter row exists in number_sequences, so no
 *    proforma number has been handed out yet and the series does not change
 *    shape in the middle of a year.
 */
return new class extends SettingsMigration
{
    private const string OLD_DEFAULT = '{YYYY}{NNNN}';

    private const string NEW_DEFAULT = '{YYYY}9{NNNN}';

    protected function migrate(): void
    {
        if (! $this->migrator->exists('numbering.proforma_pattern')) {
            return;
        }

        if (DB::table('number_sequences')->where('scope_key', 'like', 'proforma:%')->exists()) {
            return;
        }

        $this->migrator->update(
            'numbering.proforma_pattern',
            static fn (string $stored): string => $stored === self::OLD_DEFAULT ? self::NEW_DEFAULT : $stored,
        );
    }
};
