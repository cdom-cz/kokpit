<?php

declare(strict_types=1);

namespace App\Domain\Settings\Casts;

use App\Domain\Settings\Banking\BankAccount;
use InvalidArgumentException;
use Spatie\LaravelSettings\SettingsCasts\SettingsCast;

/**
 * Stores a list of bank accounts in the settings table as a list of arrays and
 * reads it back as BankAccount value objects.
 */
final class BankAccountListCast implements SettingsCast
{
    /**
     * @param  mixed  $payload  the decoded list of account arrays
     * @return list<BankAccount>
     *
     * @throws InvalidArgumentException when the stored payload is not a list of account arrays
     */
    public function get($payload): array
    {
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Stored bank accounts must be a list.');
        }

        $accounts = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Every stored bank account must be an array.');
            }

            $accounts[] = BankAccount::fromArray($item);
        }

        return $accounts;
    }

    /**
     * @param  list<BankAccount>  $payload
     * @return list<array<string, string|null>>
     */
    public function set($payload): array
    {
        return array_map(static fn (BankAccount $account): array => $account->toArray(), $payload);
    }
}
