<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Casts\BankAccountListCast;
use App\Domain\Settings\Rules\IbanRule;
use App\Domain\Settings\Rules\KnownCurrency;
use App\Domain\Settings\Rules\UniqueCurrencies;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The supplier's bank accounts (group `bank`), at most one per currency
 * (D-03, D-04). Every stored account has the same shape: fields the chosen
 * format does not show are null.
 */
class BankAccountSettings extends ValidatedSettings
{
    /** BIC/SWIFT after upper-casing: four letters, a country, a location and an optional branch. */
    private const string BIC_PATTERN = '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/D';

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
     * Rules of the list as a whole. The rules of a single account depend on its
     * format and are applied per account by accountRules() in save().
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'accounts' => ['array', new UniqueCurrencies],
        ];
    }

    /**
     * The rules of one field of an account in the given format, the single
     * source for the settings form and for save(). A field the format does not
     * show has no rules, because it is stored as null.
     *
     * @return list<mixed>
     */
    public static function fieldRules(string $field, BankAccountFormat $format): array
    {
        $common = self::commonRules($field);

        if ($common !== []) {
            return $common;
        }

        if (! in_array($field, $format->visibleFields(), true)) {
            return [];
        }

        $world = $format === BankAccountFormat::World;

        return match ($field) {
            'iban' => ['required', new IbanRule],
            'account_number' => ['required', self::pattern(
                $world ? '/^[A-Za-z0-9]{1,34}$/D' : '/^(\d{1,6}-)?\d{2,10}$/D',
                $world ? 'kokpit.settings.bank.account_number_world_invalid' : 'kokpit.settings.bank.account_number_invalid',
            )],
            'bank_code' => ['required', self::pattern('/^\d{4}$/D', 'kokpit.settings.bank.bank_code_invalid')],
            'bank_name' => [$world ? 'required' : 'nullable', 'string', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:100'],
            'bank_address' => ['nullable', 'string', 'max:255'],
            default => [],
        };
    }

    /**
     * Rules of the fields every format has: label, currency and BIC/SWIFT.
     * Empty for any other field.
     *
     * @return list<mixed>
     */
    public static function commonRules(string $field): array
    {
        return match ($field) {
            'label' => ['required', 'string', 'max:100'],
            'currency' => ['required', new KnownCurrency],
            'bic' => ['nullable', 'string', self::bicRule()],
            default => [],
        };
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
     * Converts the submitted accounts into value objects: text trimmed, currency
     * and BIC upper case, IBAN without spaces, hidden fields null. An account
     * with an unknown format becomes a field error.
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
                $accounts[] = BankAccount::fromArray(is_array($item) ? $item : [])->normalised();
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    "accounts.{$index}.format" => [__('kokpit.settings.bank.format_invalid')],
                ]);
            }
        }

        $this->accounts = $accounts;

        return $this;
    }

    /**
     * Validates every account by its format, then the list (one account per
     * currency), and only then writes.
     *
     * @throws ValidationException
     */
    public function save(): static
    {
        $errors = [];

        foreach ($this->accounts as $index => $account) {
            foreach (self::accountRules($account->format) as $field => $rules) {
                $validator = Validator::make(
                    [$field => $account->toArray()[$field]],
                    [$field => $rules],
                    [],
                    [$field => __("kokpit.settings.bank.{$field}")],
                );

                if ($validator->fails()) {
                    $errors["accounts.{$index}.{$field}"] = $validator->errors()->get($field);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return parent::save();
    }

    /**
     * The account of a currency (compared upper case), or null when none exists.
     * Invoices use it to pick the account by the client's currency (D-04).
     */
    public function forCurrency(string $currency): ?BankAccount
    {
        $currency = mb_strtoupper(trim($currency));

        foreach ($this->accounts as $account) {
            if ($account->currency === $currency) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function accountRules(BankAccountFormat $format): array
    {
        $rules = [];

        foreach (['label', 'currency', 'bic', 'account_number', 'bank_code', 'bank_name', 'iban', 'recipient_name', 'bank_address'] as $field) {
            $rules[$field] = self::fieldRules($field, $format);
        }

        return array_filter($rules, static fn (array $fieldRules): bool => $fieldRules !== []);
    }

    /**
     * A rule that checks the BIC/SWIFT shape after trimming and upper-casing,
     * the way it is stored.
     */
    private static function bicRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match(self::BIC_PATTERN, mb_strtoupper(trim($value))) !== 1) {
                $fail(__('kokpit.settings.bank.bic_invalid'));
            }
        };
    }

    /**
     * A rule that fails with a Czech message when the value does not match the pattern.
     */
    private static function pattern(string $regex, string $messageKey): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($regex, $messageKey): void {
            if (! is_string($value) || preg_match($regex, $value) !== 1) {
                $fail(__($messageKey));
            }
        };
    }
}
