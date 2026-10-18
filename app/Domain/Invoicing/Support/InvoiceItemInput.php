<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Shared\Money\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * The checked lines of a draft (IN-02, D-08): one place answers the same field error for the same bad value, for
 * every Action that takes lines from a form or a request.
 *
 * Every key is re-validated whatever the editor offered. A line is `id?`, `description`, `quantity`, `unit`,
 * `unit_price` and `confirm_zero_price?`; the quantity and the price are text typed by a person (a comma or a
 * point, spaces of any kind ignored) or an integer. The problems of all lines are collected and thrown together as
 * one {@see ValidationException} keyed `items.<n>.<field>` (n counts from 0 in the order sent), with the Czech copy
 * of `invoices.errors`; too many lines are one error on `items` and nothing else is checked then.
 *
 * - description: 1 to 500 characters after trimming;
 * - quantity: at most four decimals, never zero; positive on an invoice or a proforma, negative on a credit note,
 *   whose lines take the quantity back (D-14);
 * - unit: 1 to 16 characters after trimming;
 * - unit price: a non-negative amount with no more decimals than the currency has; zero only with
 *   `confirm_zero_price` true.
 *
 * The id is only carried here: whether it belongs to the draft is decided by the Action against the locked rows.
 */
final readonly class InvoiceItemInput
{
    private const int DESCRIPTION_MAX_LENGTH = 500;

    private const int UNIT_MAX_LENGTH = 16;

    private function __construct(
        public ?string $id,
        public string $description,
        public InvoiceQuantity $quantity,
        public string $unit,
        public Money $unitPrice,
    ) {}

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<self>
     *
     * @throws ValidationException
     */
    public static function list(array $items, string $currency, InvoiceKind $kind, int $max): array
    {
        if (count($items) > $max) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.items_cap', ['max' => $max])]);
        }

        $inputs = [];
        $errors = [];

        foreach (array_values($items) as $index => $item) {
            $line = is_array($item) ? $item : [];
            $problems = [];

            $input = self::line($line, $currency, $kind, $problems);

            foreach ($problems as $field => $message) {
                $errors["items.{$index}.{$field}"] = [$message];
            }

            if ($input instanceof self) {
                $inputs[] = $input;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $inputs;
    }

    /**
     * @param  array<array-key, mixed>  $line
     * @param  array<string, string>  $problems  filled with `field => message` for every problem of the line
     */
    private static function line(array $line, string $currency, InvoiceKind $kind, array &$problems): ?self
    {
        $description = self::description($line['description'] ?? null);
        $unit = self::unit($line['unit'] ?? null);
        $quantity = self::quantity($line['quantity'] ?? null, $kind);
        $price = self::price($line['unit_price'] ?? null, $currency, self::flag($line['confirm_zero_price'] ?? null));

        if ($description === null) {
            $problems['description'] = self::message('item_description');
        }

        if ($unit === null) {
            $problems['unit'] = self::message('item_unit');
        }

        if (! $quantity instanceof InvoiceQuantity) {
            $problems['quantity'] = self::message('item_quantity');
        }

        if (! $price instanceof Money) {
            $problems['unit_price'] = self::priceProblem($line['unit_price'] ?? null, $currency, self::flag($line['confirm_zero_price'] ?? null));
        }

        if ($problems !== [] || $description === null || $unit === null || ! $quantity instanceof InvoiceQuantity || ! $price instanceof Money) {
            return null;
        }

        $id = $line['id'] ?? null;

        return new self(is_string($id) && $id !== '' ? $id : null, $description, $quantity, $unit, $price);
    }

    private static function description(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text !== '' && mb_strlen($text) <= self::DESCRIPTION_MAX_LENGTH ? $text : null;
    }

    private static function unit(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text !== '' && mb_strlen($text) <= self::UNIT_MAX_LENGTH ? $text : null;
    }

    private static function quantity(mixed $value, InvoiceKind $kind): ?InvoiceQuantity
    {
        $text = self::decimal($value);

        if ($text === null) {
            return null;
        }

        try {
            $quantity = InvoiceQuantity::fromDecimal($text);
        } catch (InvalidArgumentException) {
            return null;
        }

        $signed = $kind === InvoiceKind::CreditNote ? $quantity->negated() : $quantity;

        return $signed->isPositive() ? $quantity : null;
    }

    /**
     * The price when it is acceptable; null for a malformed, negative or unconfirmed zero price.
     */
    private static function price(mixed $value, string $currency, bool $confirmedZero): ?Money
    {
        $text = self::decimal($value);

        if ($text === null) {
            return null;
        }

        try {
            $price = Money::fromMajor($text, $currency);
        } catch (InvalidArgumentException|OverflowException) {
            return null;
        }

        if ($price->minor < 0 || ($price->isZero() && ! $confirmedZero)) {
            return null;
        }

        return $price;
    }

    /**
     * The message of a refused price: the zero-price confirmation when the price is a valid zero, else the
     * price copy.
     */
    private static function priceProblem(mixed $value, string $currency, bool $confirmedZero): string
    {
        $text = self::decimal($value);

        if ($text !== null && ! $confirmedZero) {
            try {
                if (Money::fromMajor($text, $currency)->isZero()) {
                    return self::message('item_zero_price');
                }
            } catch (InvalidArgumentException|OverflowException) {
                // Falls through to the ordinary price message.
            }
        }

        return self::message('item_price');
    }

    /**
     * A decimal typed by a person with a comma or a point, spaces of any kind removed; null for anything but
     * text or a whole number.
     */
    private static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);
    }

    private static function message(string $key): string
    {
        $text = __('invoices.errors.'.$key);

        return is_string($text) ? $text : $key;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
