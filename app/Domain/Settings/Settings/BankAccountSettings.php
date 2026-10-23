<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Casts\BankAccountListCast;
use App\Domain\Settings\Rules\IbanRule;
use App\Domain\Settings\Rules\KnownCurrency;
use App\Domain\Settings\Rules\UniqueAccountIds;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The supplier's bank accounts (group `bank`). Several accounts may share a
 * currency: D-04 of Phase 14 overrides Phase 3 D-04 "one account per currency".
 * Every stored account has the same shape: fields the chosen format does not
 * show are null.
 *
 * Every account has a stable UUID v7 id that a document can point at. The id
 * is assigned once, in save() or by the settings migration that numbered the
 * accounts stored before the phase, and never on a read.
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
     * Rules of the list as a whole: every account has an id, no id twice. The
     * rules of a single account depend on its format and are applied per
     * account by accountRules() in save().
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'accounts' => ['array', new UniqueAccountIds],
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
     * The form state carries the id of every account, so the Repeater hands it
     * back on save.
     *
     * @return array{accounts: list<array<string, string|null>>}
     */
    public function toFormState(): array
    {
        return [
            'accounts' => array_map(static fn (BankAccount $account): array => $account->toStoredArray(), $this->accounts),
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
     * Validates every account by its format, gives every account without an
     * id a new UUID v7 (D-04), then validates the list (every id a UUID, no id
     * twice) and only then writes.
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

        $this->accounts = array_map(
            static fn (BankAccount $account): BankAccount => $account->id === null ? $account->withId((string) Str::uuid7()) : $account,
            $this->accounts,
        );

        return parent::save();
    }

    /**
     * The account with the given id, or null for an unknown id or an account
     * that has none yet. The exact id match is the one way a document finds
     * the account it was told to use (D-04).
     */
    public function find(string $id): ?BankAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->id !== null && $account->id === $id) {
                return $account;
            }
        }

        return null;
    }

    /**
     * The first account of a currency in the order of the settings list
     * (compared upper case), or null when none exists. This is the
     * preselection rule of D-05: the currency only suggests an account, the
     * document keeps the account it references by id.
     */
    public function firstForCurrency(string $currency): ?BankAccount
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
     * A one-line delegate to firstForCurrency(), kept only for its present
     * callers (IssueChecks, InvoiceSnapshot, EditInvoice). No new code may call
     * it: plan 14-10 deletes it together with the architecture test
     * tests/Arch/BankAccountByIdTest.php that keeps it deleted.
     */
    public function forCurrency(string $currency): ?BankAccount
    {
        return $this->firstForCurrency($currency);
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
