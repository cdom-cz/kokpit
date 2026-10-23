<?php

declare(strict_types=1);

namespace App\Domain\Settings\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Passes for a list of bank accounts in which every account has an id that is
 * a UUID and no id appears twice (D-04, Phase 14).
 *
 * A document points at its bank account by this id, so a missing, malformed
 * or shared id would detach it or send it to the wrong account. The ids are
 * assigned in BankAccountSettings::save() and by the settings migration, never
 * on read (a generated-on-read id would change on every request), and this
 * rule makes the data layer hold for every writer, not only the settings form.
 */
final class UniqueAccountIds implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $seen = [];

        foreach ($value as $account) {
            $id = is_array($account) ? ($account['id'] ?? null) : null;

            if (! is_string($id) || ! Str::isUuid($id)) {
                $fail(__('kokpit.settings.bank.id_invalid'));

                return;
            }

            $id = mb_strtolower($id);

            if (isset($seen[$id])) {
                $fail(__('kokpit.settings.bank.id_duplicate'));

                return;
            }

            $seen[$id] = true;
        }
    }
}
