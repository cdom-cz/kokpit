<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
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
 */
final readonly class TransactionInput
{
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
        $currency = self::currency($data, $current);
        $amount = self::amount($data, $current, $currency);

        return new self(
            type: $type,
            transactionDate: $day,
            categoryId: self::categoryId($data, $current),
            amount: $amount,
            czkAmount: self::czkAmount($data),
            czkManual: self::flag($data['czk_manual'] ?? null),
            description: self::text($data, $current, 'description', 'description', self::DESCRIPTION_MAX_LENGTH),
            counterparty: self::text($data, $current, 'counterparty', 'counterparty', self::COUNTERPARTY_MAX_LENGTH),
            documentNumber: self::text($data, $current, 'document_number', 'document_number', self::DOCUMENT_NUMBER_MAX_LENGTH),
            clientId: self::optionalId($data, $current, 'client_id', 'client_unavailable'),
            projectId: self::optionalId($data, $current, 'project_id', 'project_unavailable'),
        );
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
    private static function currency(array $data, ?Transaction $current): string
    {
        if (! array_key_exists('currency', $data) && $current instanceof Transaction) {
            return $current->amount_currency;
        }

        $value = $data['currency'] ?? null;
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if (! Money::isKnownCurrency($code)) {
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

    private static function error(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => __('finance.transactions.errors.'.$message)]);
    }
}
