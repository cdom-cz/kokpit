<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use InvalidArgumentException;
use OverflowException;

/**
 * The quantity of an invoice line: a scaled integer of ten-thousandths of a unit.
 *
 * Pure PHP, no framework and no Brick (ruling R-01-2): a quantity is not money,
 * so its one rounding is integer arithmetic here and the Money class stays the
 * only place that talks to Brick and owns the money rounding point.
 *
 * {@see InvoiceQuantity::fromSeconds()} is the only place in Kokpit where a
 * quantity is rounded: exact seconds divided by 3600, rounded half-up once to
 * four decimals (D-02). Everything else is exact: a typed quantity longer than
 * four decimals is refused rather than rounded, and sums and differences of
 * ten-thousandths stay exact integers.
 *
 * The canonical text ({@see InvoiceQuantity::toDecimal()}) is what
 * `Money::times()` multiplies by, so the printed quantity, the editor and the
 * database CHECK `round(quantity * unit_price_minor)` all see the same number.
 */
final readonly class InvoiceQuantity
{
    private const SCALE = 10000;

    /** The largest absolute value: ten integer digits and four decimals. */
    private const MAX_ABSOLUTE = 99_999_999_999_999;

    private function __construct(
        public int $tenThousandths,
    ) {}

    /**
     * The hours of an exact number of seconds, rounded half-up once to four decimals.
     *
     * seconds / 3600 * 10000 = seconds * 25 / 9, so half-up rounding is
     * floor(seconds * 25 / 9 + 1/2) = intdiv(50 * seconds + 9, 18); 45305 s is 12,5847 h.
     *
     * @throws InvalidArgumentException when the seconds are negative
     * @throws OverflowException when the quantity does not fit the ten-digit integer part
     */
    public static function fromSeconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('A duration cannot be negative.');
        }

        // 25 * 3.6e13 s is below MAX_ABSOLUTE; the bound also keeps 50 * seconds inside an integer.
        if ($seconds > 36_000_000_000_000) {
            throw new OverflowException('The duration is too long for an invoice quantity.');
        }

        return self::ofScaled(intdiv(50 * $seconds + 9, 18));
    }

    /**
     * Parses a quantity typed by a person: an optional minus sign, up to ten digits and an
     * optional fraction of at most four digits after a comma or a point. Never rounds.
     *
     * @throws InvalidArgumentException when the text is not such a number
     */
    public static function fromDecimal(string $value): self
    {
        if (preg_match('/^(-?)(\d{1,10})(?:[.,](\d{1,4}))?$/D', $value, $match) !== 1) {
            throw new InvalidArgumentException('The quantity must be digits with an optional decimal comma or point and at most four decimals.');
        }

        $scaled = (int) $match[2] * self::SCALE + (int) str_pad($match[3] ?? '', 4, '0');

        return self::ofScaled($match[1] === '-' ? -$scaled : $scaled);
    }

    /**
     * The canonical text: a point and exactly four decimals, "12.5847", "-0.5000".
     */
    public function toDecimal(): string
    {
        $absolute = abs($this->tenThousandths);

        return ($this->tenThousandths < 0 ? '-' : '')
            .intdiv($absolute, self::SCALE).'.'
            .str_pad((string) ($absolute % self::SCALE), 4, '0', STR_PAD_LEFT);
    }

    /**
     * The quantity for display: the decimal separator of the locale and trailing zeros
     * trimmed, so "2,5", "1,3333" and "3".
     *
     * @throws InvalidArgumentException when the locale is neither "cs" nor "en"
     */
    public function format(string $locale): string
    {
        $separator = match ($locale) {
            'cs' => ',',
            'en' => '.',
            default => throw new InvalidArgumentException('The quantity can be formatted for "cs" or "en" only.'),
        };

        $text = rtrim(rtrim($this->toDecimal(), '0'), '.');

        return str_replace('.', $separator, $text);
    }

    /**
     * @throws OverflowException when the sum leaves the ten-digit integer part
     */
    public function plus(self $other): self
    {
        return self::ofScaled($this->tenThousandths + $other->tenThousandths);
    }

    /**
     * @throws OverflowException when the difference leaves the ten-digit integer part
     */
    public function minus(self $other): self
    {
        return self::ofScaled($this->tenThousandths - $other->tenThousandths);
    }

    public function negated(): self
    {
        return new self(-$this->tenThousandths);
    }

    public function isZero(): bool
    {
        return $this->tenThousandths === 0;
    }

    public function isPositive(): bool
    {
        return $this->tenThousandths > 0;
    }

    public function equals(self $other): bool
    {
        return $this->tenThousandths === $other->tenThousandths;
    }

    public function compareTo(self $other): int
    {
        return $this->tenThousandths <=> $other->tenThousandths;
    }

    /**
     * @throws OverflowException
     */
    private static function ofScaled(int $scaled): self
    {
        if (abs($scaled) > self::MAX_ABSOLUTE) {
            throw new OverflowException('The quantity does not fit ten integer digits and four decimals.');
        }

        return new self($scaled);
    }
}
