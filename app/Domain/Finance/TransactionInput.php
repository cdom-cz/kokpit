<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * The checked input of a transaction write (D-12, D-13): one place answers the same field error for the same
 * bad value, for RecordTransaction and UpdateTransaction.
 *
 * Every key is re-validated whatever the form offered. Without `$current` (a new transaction) all of type,
 * date, category, amount and currency are required; with the stored transaction an absent key keeps the stored
 * value. The first problem is thrown as a field error keyed by the data key, with a Czech message from
 * `finance.transactions.errors`.
 *
 * An own exchange rate (`exchange_rate`, with `exchange_unit_amount`, default 1) is read by `customRate()`; the
 * data naming `exchange_rate` with null sets `customRateCleared`, which the update Action turns into dropping it.
 *
 * The category, client and project are read under a share lock, so the caller MUST run inside its transaction
 * or the locks end with the statement. A category, client, project or currency that the stored transaction
 * already carries is accepted as it is (a later archive must not block editing the description), but a changed
 * one has to be usable now: a category of the transaction type that is not archived, a live client, a live
 * project of that client, `CZK` or a currency with at least one stored CNB row. A known ISO currency without any
 * CNB row is usable as well when the data brings its own CZK figure: an own rate or a typed CZK amount.
 */
final readonly class TransactionInput
{
    private const int MAX_UNIT_AMOUNT = 2147483647;

    private const int DESCRIPTION_MAX_LENGTH = 2000;

    private const int COUNTERPARTY_MAX_LENGTH = 255;

    private const int DOCUMENT_NUMBER_MAX_LENGTH = 64;

    private function __construct(
        public TransactionType $type,
        public string $transactionDate,
        public string $categoryId,
        public Money $amount,
        public ?Money $czkAmount,
        public ?bool $czkManual,
        public ?CustomRate $customRate,
        public bool $customRateCleared,
        public ?string $description,
        public ?string $counterparty,
        public ?string $documentNumber,
        public ?string $clientId,
        public ?string $projectId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data, ?Transaction $current = null): self
    {
        $type = self::type($data, $current);
        $day = self::day($data, $current);
        $czkAmount = self::czkAmount($data);
        $customRate = self::customRate($data, $czkAmount);
        $figureGiven = $customRate instanceof CustomRate || ($czkAmount instanceof Money && self::flag($data['czk_manual'] ?? null) !== false);
        $currency = self::currency($data, $current, $figureGiven);
        $amount = self::amount($data, $current, $currency);
        $categoryId = self::categoryId($data, $current);
        $clientId = self::optionalId($data, $current, 'client_id', 'client_unavailable');
        $projectId = self::optionalId($data, $current, 'project_id', 'project_unavailable');

        self::assertCategory($categoryId, $type, $current);
        self::assertClientAndProject($clientId, $projectId, $current);

        return new self(
            type: $type,
            transactionDate: $day,
            categoryId: $categoryId,
            amount: $amount,
            czkAmount: $czkAmount,
            czkManual: self::flag($data['czk_manual'] ?? null),
            customRate: $customRate,
            customRateCleared: array_key_exists('exchange_rate', $data) && $data['exchange_rate'] === null,
            description: self::text($data, $current, 'description', 'description', self::DESCRIPTION_MAX_LENGTH),
            counterparty: self::text($data, $current, 'counterparty', 'counterparty', self::COUNTERPARTY_MAX_LENGTH),
            documentNumber: self::text($data, $current, 'document_number', 'document_number', self::DOCUMENT_NUMBER_MAX_LENGTH),
            clientId: $clientId,
            projectId: $projectId,
        );
    }

    /**
     * The currencies a transaction may be written in: CZK and every currency with at least one stored CNB row
     * (D-13), CZK first, the rest sorted.
     *
     * @return list<string>
     */
    public static function currencyOptions(): array
    {
        /** @var list<string> $codes */
        $codes = ExchangeRate::query()->distinct()->orderBy('currency')->pluck('currency')->all();

        return ['CZK', ...array_values(array_filter($codes, static fn (string $code): bool => $code !== 'CZK'))];
    }

    /**
     * The figure a new transaction takes as manual: the typed CZK amount, unless the data switches the manual
     * figure off explicitly.
     */
    public function manualCzk(): ?Money
    {
        return $this->czkManual === false ? null : $this->czkAmount;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function type(array $data, ?Transaction $current): TransactionType
    {
        if (! array_key_exists('type', $data) && $current instanceof Transaction) {
            return $current->type;
        }

        $value = $data['type'] ?? null;

        if ($value instanceof TransactionType) {
            return $value;
        }

        return (is_string($value) ? TransactionType::tryFrom($value) : null)
            ?? throw self::error('type', 'invalid_type');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function day(array $data, ?Transaction $current): string
    {
        if (! array_key_exists('transaction_date', $data) && $current instanceof Transaction) {
            return $current->transaction_date->toDateString();
        }

        $value = $data['transaction_date'] ?? null;

        if ($value instanceof CarbonInterface) {
            $value = $value->format('Y-m-d');
        }

        return PragueDay::day($value) ?? throw self::error('transaction_date', 'invalid_date');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function currency(array $data, ?Transaction $current, bool $figureGiven): string
    {
        if (! array_key_exists('currency', $data) && $current instanceof Transaction) {
            return $current->amount_currency;
        }

        $value = $data['currency'] ?? null;
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if ($current instanceof Transaction && $code === $current->amount_currency) {
            return $code;
        }

        $usable = Money::isKnownCurrency($code)
            && ($code === 'CZK' || $figureGiven || ExchangeRate::query()->where('currency', $code)->exists());

        if (! $usable) {
            throw self::error('currency', 'currency_unknown');
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function amount(array $data, ?Transaction $current, string $currency): Money
    {
        if (! array_key_exists('amount', $data) && $current instanceof Transaction) {
            $text = $current->amount->toMajor();
        } else {
            $text = self::decimal($data['amount'] ?? null, 'amount', 'amount_invalid');
        }

        try {
            $amount = Money::fromMajor($text, $currency);
        } catch (InvalidArgumentException|OverflowException) {
            throw self::error('amount', 'amount_invalid');
        }

        if ($amount->minor <= 0) {
            throw self::error('amount', 'amount_not_positive');
        }

        return $amount;
    }

    /**
     * The typed CZK figure, null when none is given. Zero is allowed, a negative figure is not.
     *
     * @param  array<string, mixed>  $data
     */
    private static function czkAmount(array $data): ?Money
    {
        $value = $data['czk_amount'] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        try {
            $czk = Money::fromMajor(self::decimal($value, 'czk_amount', 'czk_invalid'), 'CZK');
        } catch (InvalidArgumentException|OverflowException) {
            throw self::error('czk_amount', 'czk_invalid');
        }

        if ($czk->minor < 0) {
            throw self::error('czk_amount', 'czk_invalid');
        }

        return $czk;
    }

    /**
     * The own rate of this transaction, null when `exchange_rate` is absent or null (D-06).
     *
     * The rate is a positive decimal with at most ten integer and ten fraction digits (the column holds no
     * more), the unit amount a whole number from 1 to the largest integer of its column, default 1. A rate
     * together with a typed CZK figure is a conflict unless `czk_manual` switches the figure off.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException `exchange_rate`, `exchange_unit_amount`
     */
    private static function customRate(array $data, ?Money $czkAmount): ?CustomRate
    {
        $value = $data['exchange_rate'] ?? null;
        $unit = $data['exchange_unit_amount'] ?? null;

        if ($value === null) {
            return $unit === null ? null : throw self::error('exchange_unit_amount', 'unit_without_rate');
        }

        $rate = str_replace(',', '.', self::decimal($value, 'exchange_rate', 'rate_invalid'));

        if (preg_match('/^\d{1,10}(\.\d{1,10})?$/D', $rate) !== 1 || preg_match('/[1-9]/', $rate) !== 1) {
            throw self::error('exchange_rate', 'rate_invalid');
        }

        $unitAmount = self::unitAmount($unit);

        if ($czkAmount instanceof Money && self::flag($data['czk_manual'] ?? null) !== false) {
            throw self::error('exchange_rate', 'rate_with_czk_amount');
        }

        return new CustomRate($rate, $unitAmount);
    }

    /**
     * @throws ValidationException `exchange_unit_amount`
     */
    private static function unitAmount(mixed $unit): int
    {
        if ($unit === null) {
            return 1;
        }

        if (is_string($unit) && preg_match('/^\d{1,10}$/D', trim($unit)) === 1) {
            $unit = (int) trim($unit);
        }

        if (! is_int($unit) || $unit < 1 || $unit > self::MAX_UNIT_AMOUNT) {
            throw self::error('exchange_unit_amount', 'unit_invalid');
        }

        return $unit;
    }

    /**
     * A decimal typed by a person with a comma or a dot, spaces of any kind removed.
     *
     * @throws ValidationException
     */
    private static function decimal(mixed $value, string $key, string $message): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw self::error($key, $message);
        }

        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);
    }

    private static function flag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function categoryId(array $data, ?Transaction $current): string
    {
        if (! array_key_exists('category_id', $data) && $current instanceof Transaction) {
            return $current->category_id;
        }

        $value = $data['category_id'] ?? null;

        return is_string($value) && Str::isUuid($value)
            ? strtolower($value)
            : throw self::error('category_id', 'category_unavailable');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, ?Transaction $current, string $key, string $column, int $max): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $current instanceof Transaction ? $current->getAttribute($column) : null;
        }

        $value = $data[$key];

        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if (mb_strlen($text) > $max) {
            throw self::error($key, 'text_too_long');
        }

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalId(array $data, ?Transaction $current, string $key, string $message): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $current instanceof Transaction ? $current->getAttribute($key) : null;
        }

        $value = $data[$key];

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && Str::isUuid($value)
            ? strtolower($value)
            : throw self::error($key, $message);
    }

    /**
     * @throws ValidationException `category_id`: unknown, archived (or under an archived parent) or of the other type
     */
    private static function assertCategory(string $id, TransactionType $type, ?Transaction $current): void
    {
        if ($current instanceof Transaction && $id === $current->category_id && $type === $current->type) {
            return;
        }

        $category = TransactionCategory::query()->whereKey($id)->sharedLock()->first()
            ?? throw self::error('category_id', 'category_unavailable');

        if ($category->type !== $type) {
            throw self::error('category_id', 'category_type_mismatch');
        }

        if (! TransactionCategory::query()->selectable($type)->whereKey($id)->exists()) {
            throw self::error('category_id', 'category_unavailable');
        }
    }

    /**
     * @throws ValidationException `client_id`: unknown or archived; `project_id`: unknown, archived, without a
     *                             client or of another client
     */
    private static function assertClientAndProject(?string $clientId, ?string $projectId, ?Transaction $current): void
    {
        if ($clientId !== null) {
            $unchanged = $current instanceof Transaction && $clientId === $current->client_id;
            $client = ($unchanged ? Client::query()->withTrashed() : Client::query())->whereKey($clientId)->sharedLock()->first();

            if ($client === null) {
                throw self::error('client_id', 'client_unavailable');
            }
        }

        if ($projectId === null) {
            return;
        }

        if ($clientId === null) {
            throw self::error('project_id', 'project_not_of_client');
        }

        $unchanged = $current instanceof Transaction && $projectId === $current->project_id;
        $project = $unchanged
            ? Project::query()->withTrashed()->whereKey($projectId)->sharedLock()->first()
            : Project::query()->selectable()->whereKey($projectId)->sharedLock()->first();

        if ($project === null) {
            throw self::error('project_id', 'project_unavailable');
        }

        if ($project->client_id !== $clientId) {
            throw self::error('project_id', 'project_not_of_client');
        }
    }

    private static function error(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => __('finance.transactions.errors.'.$message)]);
    }
}
