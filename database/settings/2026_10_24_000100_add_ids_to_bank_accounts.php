<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsMigration;
use App\Domain\Shared\Models\SettingsProperty;
use Illuminate\Support\Str;
use Spatie\LaravelSettings\SettingsRepositories\SettingsRepository;

/*
 * Gives the bank accounts stored before Phase 14 a stable id (D-04, R-14-01-5).
 *
 * A document points at its bank account by id from Phase 14 on, so every
 * stored account needs one. Ids are written in exactly two places: here, for
 * the accounts that already exist, and in BankAccountSettings::save(), for the
 * accounts saved afterwards. They are never generated on a read, because an
 * id that differs on every request would detach documents from their account.
 *
 * The migration is idempotent: an entry that already has a string id keeps it
 * and every other entry and key stays as stored, so a second run changes
 * nothing. It returns early when the bank accounts were never stored.
 *
 * The migrator's update() cannot be used for this property: it reads the
 * payload as stdClass objects and runs them through the list cast, which
 * rejects them, and the cast would drop every key it does not know anyway. The
 * raw stored list is therefore read from the settings row (the system context
 * of the base class lifts the Partner scope) and written back, with the missing
 * ids, through the settings repository.
 */
return new class extends SettingsMigration
{
    protected function migrate(): void
    {
        if (! $this->migrator->exists('bank.accounts')) {
            return;
        }

        $raw = SettingsProperty::query()->where('group', 'bank')->where('name', 'accounts')->value('payload');
        $stored = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;

        if (! is_array($stored)) {
            return;
        }

        $numbered = array_map(
            static fn (mixed $account): mixed => is_array($account) && ! is_string($account['id'] ?? null)
                ? ['id' => (string) Str::uuid7(), ...array_diff_key($account, ['id' => true])]
                : $account,
            $stored,
        );

        app(SettingsRepository::class)->updatePropertiesPayload('bank', ['accounts' => $numbered]);
    }
};
