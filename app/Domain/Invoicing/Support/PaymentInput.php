<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * The checked input of a manual payment (IN-05, D-17, D-19): one place answers the same field error for the same
 * bad value, whatever the screen offered. Every key is re-validated.
 *
 * Keys of the data: `amount` (a decimal in the currency of the document, greater than zero, a comma or a dot, spaces
 * of any kind ignored), `paid_on` (a Prague day, not after today), `method` (`transfer` by default, or `cash`),
 * `note` (at most 500 characters), and, for an invoice only, `income_date` (the day of the income transaction, by
 * default the payment date) and `category_id` (a selectable income category, absent for the system one). A proforma
 * books no income, so for it both income keys are ignored. The first problem is thrown as a field error keyed by the
 * data key, with a Czech message from `invoices.errors`. A payment dated before the issue date is allowed (an advance
 * received earlier, R-16-4); one dated in the future is not.
 *
 * The category is read under a share lock, so the caller MUST run inside its transaction.
 */
final readonly class PaymentInput
{
    private const int NOTE_MAX_LENGTH = 500;

    private function __construct(
        public Money $amount,
        public string $paidOn,
        public PaymentMethod $method,
        public ?string $note,
        public string $incomeDate,
        public ?string $categoryId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  string  $today  the current Prague day, `Y-m-d`
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data, Invoice $invoice, string $today): self
    {
        $amount = self::amount($data, $invoice->currency);
        $paidOn = self::paidOn($data, $today);
        $books = $invoice->kind === InvoiceKind::Invoice;

        return new self(
            amount: $amount,
            paidOn: $paidOn,
            method: self::method($data),
            note: self::note($data),
            incomeDate: $books ? self::incomeDate($data, $paidOn) : $paidOn,
            categoryId: $books ? self::categoryId($data) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function amount(array $data, string $currency): Money
    {
        $value = $data['amount'] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw self::error('amount', 'payment_amount');
        }

        try {
            $amount = Money::fromMajor((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value), $currency);
        } catch (InvalidArgumentException|OverflowException) {
            throw self::error('amount', 'payment_amount');
        }

        if (! $amount->isPositive()) {
            throw self::error('amount', 'payment_amount');
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function paidOn(array $data, string $today): string
    {
        $day = PragueDay::day($data['paid_on'] ?? null) ?? throw self::error('paid_on', 'date_invalid');

        if ($day > $today) {
            throw self::error('paid_on', 'payment_date_future');
        }

        return $day;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function method(array $data): PaymentMethod
    {
        $value = $data['method'] ?? null;

        if ($value === null || $value === '') {
            return PaymentMethod::Transfer;
        }

        if ($value instanceof PaymentMethod) {
            return $value;
        }

        return (is_string($value) ? PaymentMethod::tryFrom($value) : null)
            ?? throw self::error('method', 'payment_method');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function note(array $data): ?string
    {
        $value = $data['note'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw self::error('note', 'payment_note');
        }

        $text = trim($value);

        if (mb_strlen($text) > self::NOTE_MAX_LENGTH) {
            throw self::error('note', 'payment_note');
        }

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function incomeDate(array $data, string $paidOn): string
    {
        $value = $data['income_date'] ?? null;

        if ($value === null || $value === '') {
            return $paidOn;
        }

        return PragueDay::day($value) ?? throw self::error('income_date', 'date_invalid');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function categoryId(array $data): ?string
    {
        $value = $data['category_id'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! Str::isUuid($value)) {
            throw self::error('category_id', 'payment_category');
        }

        $id = strtolower($value);

        $usable = TransactionCategory::query()
            ->selectable(TransactionType::Income)
            ->whereKey($id)
            ->sharedLock()
            ->exists();

        return $usable ? $id : throw self::error('category_id', 'payment_category');
    }

    private static function error(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => __('invoices.errors.'.$message)]);
    }
}
