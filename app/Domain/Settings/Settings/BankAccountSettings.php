<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Banking\Iban;
use App\Domain\Settings\Casts\BankAccountListCast;
use App\Domain\Settings\Rules\IbanRule;
use App\Domain\Settings\Rules\KnownCurrency;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The supplier's bank accounts (group `bank`), one per currency (D-03, D-04).
 */
class BankAccountSettings extends ValidatedSettings
{
    /** @phpstan-var list<BankAccount> */
    public array $accounts;

    public static function group(): string
    {
        return 'bank';
    }

    /**
     * @return array<string, BankAccountListCast>
     */
    public static function casts(): array
    {
        return [
            'accounts' => new BankAccountListCast,
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'accounts' => ['array'],
            'accounts.*.label' => ['required', 'string', 'max:100'],
            'accounts.*.format' => ['required', Rule::enum(BankAccountFormat::class)],
            'accounts.*.currency' => ['required', new KnownCurrency],
            'accounts.*.iban' => ['required_if:accounts.*.format,europe_1,europe_2', 'nullable', new IbanRule],
        ];
    }

    /**
     * @return array{accounts: list<array<string, string|null>>}
     */
    public function toFormState(): array
    {
        return [
            'accounts' => array_map(static fn (BankAccount $account): array => $account->toArray(), $this->accounts),
        ];
    }

    /**
     * Converts the submitted accounts into value objects, normalising the IBAN
     * and the currency; an account that cannot be read becomes a field error.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     */
    public function fillFromFormState(array $state): static
    {
        if (! array_key_exists('accounts', $state)) {
            return $this;
        }

        $submitted = is_array($state['accounts']) ? array_values($state['accounts']) : [];
        $accounts = [];

        foreach ($submitted as $index => $item) {
            try {
                $account = BankAccount::fromArray(is_array($item) ? $item : []);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    "accounts.{$index}.format" => [__('kokpit.settings.bank.format_invalid')],
                ]);
            }

            $accounts[] = new BankAccount(
                format: $account->format,
                label: trim($account->label),
                currency: mb_strtoupper(trim($account->currency)),
                bic: $account->bic,
                accountNumber: $account->accountNumber,
                bankCode: $account->bankCode,
                bankName: $account->bankName,
                iban: $account->iban === null ? null : Iban::normalise($account->iban),
                recipientName: $account->recipientName,
                bankAddress: $account->bankAddress,
            );
        }

        $this->accounts = $accounts;

        return $this;
    }
}
