<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Shared\Money\Money;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The write payload of a transaction: the shape rules and the mapping to the data of the finance Actions.
 *
 * The rules check shape only (presence on create, integer, string, UUID, UUID or project key, three capital
 * letters for a currency). Every business rule stays in `RecordTransaction` and `UpdateTransaction`, so the API
 * refuses what the panel refuses with the same Czech messages. The mapping builds only the allow-listed Action
 * keys: a smuggled `id`, `czk_source`, `czk_minor` or `created_at` never leaves this class.
 *
 * Amounts arrive in minor units (`amount_minor`, `czk_amount_minor`) and become the major-unit strings the Actions
 * take through `Money::ofMinor()->toMajor()`. The Actions answer under their own keys, so {@see remap()} renames
 * `amount` to `amount_minor` and `czk_amount` to `czk_amount_minor` in one place (owner decision A2): an error is
 * always keyed by a field the client actually sent.
 *
 * The three ways to fix the CZK figure (D-06) are `exchange_rate` (with `exchange_unit_amount`, default 1) for a
 * custom rate, `czk_amount_minor` for a manual figure, or neither for the CNB rule. A CZK transaction takes none
 * of the three: the API refuses them instead of ignoring them silently (a rule about ignored input of the wire
 * contract, not a second business rule; the domain keeps ignoring them). Booleans are refused in the integer fields
 * (`amount_minor`, `czk_amount_minor`, `exchange_unit_amount`); integers and integer strings pass as before.
 *
 * `external` is the free-form object of an import tool. It is taken from the raw request body ({@see RawJsonBody}),
 * because a PHP array cannot tell the JSON object `{}` from the list `[]`, and handed to the Action as it is. The key
 * reaches the Action exactly when the body names it, so on update an absent key keeps the stored value and `null`
 * clears it.
 */
final class TransactionPayload
{
    /**
     * The Action error keys that differ from the API field names.
     */
    private const array RENAMES = ['amount' => 'amount_minor', 'czk_amount' => 'czk_amount_minor'];

    /**
     * The plain text and reference keys that are copied to the Action data when the request names them.
     */
    private const array PLAIN_UPDATE_KEYS = ['type', 'transaction_date', 'category_id', 'description', 'counterparty', 'document_number', 'client_id'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'type' => [$required, 'string'],
            'transaction_date' => [$required, 'string'],
            'category_id' => [$required, self::uuid('category_unavailable')],
            'amount_minor' => [$create ? 'required' : 'required_with:currency', 'bail', self::noBoolean('amount_invalid'), 'integer'],
            'currency' => [$required, 'string', 'regex:/^[A-Z]{3}$/D'],
            'czk_amount_minor' => ['nullable', 'bail', self::noBoolean('czk_invalid'), 'integer'],
            'exchange_rate' => ['nullable', self::rate()],
            'exchange_unit_amount' => ['nullable', 'bail', self::noBoolean('unit_invalid'), 'integer'],
            'description' => ['nullable', 'string'],
            'counterparty' => ['nullable', 'string'],
            'document_number' => ['nullable', 'string'],
            'client_id' => ['nullable', self::uuid('client_unavailable')],
            'project_id' => ['nullable', self::reference(ReferenceResolver::PROJECT_KEY, 'project_unavailable')],
            // Shape and size are decided by ExternalObject::parse() on the raw body.
            'external' => ['nullable'],
        ];
    }

    /**
     * The data of `RecordTransaction::handle()` for a validated request.
     *
     * @param  array<string, mixed>  $validated
     * @param  stdClass|null  $raw  the request body decoded as objects, where `external` is taken from
     * @return array<string, mixed>
     *
     * @throws ValidationException a rate, a unit amount or a CZK figure on a CZK transaction
     */
    public static function forCreate(array $validated, ReferenceResolver $refs, ?stdClass $raw = null): array
    {
        $currency = self::text($validated['currency'] ?? null);

        self::assertNoRateOnCzk($validated, $currency);

        $data = [
            'type' => $validated['type'] ?? null,
            'transaction_date' => $validated['transaction_date'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'currency' => $currency,
            'description' => $validated['description'] ?? null,
            'counterparty' => $validated['counterparty'] ?? null,
            'document_number' => $validated['document_number'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'project_id' => $refs->writeProject($validated['project_id'] ?? null),
        ];

        $amount = self::major($validated['amount_minor'] ?? null, $currency);

        if ($amount !== null) {
            $data['amount'] = $amount;
        }

        return [...$data, ...self::figure($validated), ...self::external($validated, $raw)];
    }

    /**
     * The data of `RecordTransaction::handle()` for one item of a batch: the single-create rules, then the same
     * mapping. An item that is not a JSON object (a scalar, null or a non-empty list) fails under `transaction`.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forBatchItem(mixed $item, ReferenceResolver $refs): array
    {
        if (! is_array($item) || ($item !== [] && array_is_list($item))) {
            throw ValidationException::withMessages(['transaction' => __('api.errors.batch_item_not_object')]);
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($item, self::rules(true))->validate();

        return self::forCreate($validated, $refs);
    }

    /**
     * The data of `UpdateTransaction::handle()` for a validated request: only the keys the request names, because an
     * absent key keeps the stored value and a null clears the text and reference fields.
     *
     * The rate and the bank figure follow the "null clears" rule of the API (owner decision A1 on D-06): a null
     * `exchange_rate` drops an own rate, a null `czk_amount_minor` drops a bank figure, and both null always
     * return the transaction to the CNB rule. A null of a field the stored figure does not use changes nothing.
     * A null `exchange_rate` sent together with a `czk_amount_minor` value only drops the own rate: the sent figure
     * becomes the bank figure (manual) and is never replaced by the CNB figure.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException a rate, a unit amount or a CZK figure on a CZK transaction
     */
    public static function forUpdate(array $validated, Transaction $stored, ReferenceResolver $refs): array
    {
        $currency = array_key_exists('currency', $validated) ? self::text($validated['currency']) : $stored->amount_currency;

        self::assertNoRateOnCzk($validated, $currency);

        $data = [];

        foreach (self::PLAIN_UPDATE_KEYS as $key) {
            if (array_key_exists($key, $validated)) {
                $data[$key] = $validated[$key];
            }
        }

        if (array_key_exists('project_id', $validated)) {
            $data['project_id'] = $refs->writeProject($validated['project_id']);
        }

        if (array_key_exists('currency', $validated)) {
            $data['currency'] = $currency;
        }

        if (array_key_exists('amount_minor', $validated)) {
            $amount = self::major($validated['amount_minor'], $currency);

            if ($amount !== null) {
                $data['amount'] = $amount;
            }
        }

        return [...$data, ...self::figure($validated), ...self::cleared($validated, $stored)];
    }

    /**
     * The `external` key of the Action data: present exactly when the request names it. The value is the raw object
     * (or null, or whatever else was sent, for the Action to refuse). Without a JSON object body, a form post for
     * example, a named `external` is an array, which the Action refuses too.
     *
     * @param  array<string, mixed>  $validated
     * @return array{external?: mixed}
     */
    private static function external(array $validated, ?stdClass $raw): array
    {
        if ($raw !== null) {
            return property_exists($raw, 'external') ? ['external' => $raw->external] : [];
        }

        return array_key_exists('external', $validated) ? ['external' => $validated['external']] : [];
    }

    /**
     * Renames the Action error keys to the API field names; the messages and every other key stay as they are.
     */
    public static function remap(ValidationException $e): ValidationException
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $name = self::RENAMES[$key] ?? $key;
            $errors[$name] = [...($errors[$name] ?? []), ...$messages];
        }

        return ValidationException::withMessages($errors);
    }

    /**
     * The CZK figure keys the request names with a value. Both an own rate and a figure are handed on when both
     * are sent, and a unit amount without a rate is handed on too: the Action refuses those combinations.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function figure(array $validated): array
    {
        $data = [];

        if (($validated['exchange_rate'] ?? null) !== null) {
            $data['exchange_rate'] = self::rateText($validated['exchange_rate']);
        }

        if (($validated['exchange_unit_amount'] ?? null) !== null) {
            $data['exchange_unit_amount'] = (int) $validated['exchange_unit_amount'];
        }

        if (($validated['czk_amount_minor'] ?? null) !== null) {
            $data['czk_amount'] = self::major($validated['czk_amount_minor'], 'CZK');
        }

        return $data;
    }

    /**
     * The keys that return a transaction to the CNB rule, from the nulls of an update. A null rate on an own-rate
     * row that comes with a CZK figure drops only the rate, so the figure of this request makes the row manual.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function cleared(array $validated, Transaction $stored): array
    {
        $rateNull = array_key_exists('exchange_rate', $validated) && $validated['exchange_rate'] === null;
        $figureNull = array_key_exists('czk_amount_minor', $validated) && $validated['czk_amount_minor'] === null;
        $toCnb = ['exchange_rate' => null, 'czk_manual' => false];

        // Both named and null: back to the CNB rule, whatever the stored figure was (a CNB row stays as it is).
        if ($rateNull && $figureNull) {
            return $toCnb;
        }

        if ($rateNull && $stored->czk_source === CzkSource::Custom) {
            // A figure sent in the same request becomes the bank figure (manual): only the own rate is dropped.
            if (($validated['czk_amount_minor'] ?? null) !== null) {
                return ['exchange_rate' => null];
            }

            return $toCnb;
        }

        if ($figureNull && $stored->czk_source === CzkSource::Manual) {
            return ['czk_manual' => false];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private static function assertNoRateOnCzk(array $validated, string $currency): void
    {
        if ($currency !== 'CZK') {
            return;
        }

        $errors = [];

        foreach (['exchange_rate', 'exchange_unit_amount', 'czk_amount_minor'] as $key) {
            if (($validated[$key] ?? null) !== null) {
                $errors[$key] = __('api.errors.czk_needs_no_rate');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * An amount in minor units as the major-unit string of the Actions; null when the currency is no ISO code, so
     * the Action answers under `currency` instead.
     */
    private static function major(mixed $minor, string $currency): ?string
    {
        if (! is_numeric($minor) || ! Money::isKnownCurrency($currency)) {
            return null;
        }

        return Money::ofMinor((int) $minor, $currency)->toMajor();
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The exchange rate as the text the Action parses. A JSON number is written in its shortest exact form, so an
     * exponent shows up as such and the Action refuses it as an invalid rate.
     */
    private static function rateText(mixed $value): string
    {
        if (is_float($value)) {
            return (string) json_encode($value);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A rule that accepts a string or a JSON number and refuses the rest with the message of the panel.
     */
    private static function rate(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $fail(__('finance.transactions.errors.rate_invalid'));
            }
        };
    }

    /**
     * A rule that refuses a JSON boolean with the given message of the panel: Laravel's `integer` rule lets `true`
     * through as 1, which would re-denominate or drop an amount.
     */
    private static function noBoolean(string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (is_bool($value)) {
                $fail(__('finance.transactions.errors.'.$message));
            }
        };
    }

    /**
     * A rule that accepts a uuid and refuses the rest with the given message of the panel.
     */
    private static function uuid(string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (! is_string($value) || ! Str::isUuid($value)) {
                $fail(__('finance.transactions.errors.'.$message));
            }
        };
    }

    /**
     * A rule that accepts a uuid or a project key and refuses a name or any other string with the given message of
     * the panel.
     */
    private static function reference(string $pattern, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($pattern, $message): void {
            if (! is_string($value) || (! Str::isUuid($value) && preg_match($pattern, $value) !== 1)) {
                $fail(__('finance.transactions.errors.'.$message));
            }
        };
    }
}
