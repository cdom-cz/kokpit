<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Exception\MoneyException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

/**
 * An amount of money: integer minor units plus an ISO 4217 currency code.
 *
 * The value object is the only place in Kokpit that talks to brick/money and
 * brick/math (D-08). No Brick type appears in a public signature, no method
 * accepts or returns a float, and every operation is exact except one:
 * {@see Money::fromExactMinor()} is the single rounding point (D-09).
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    /**
     * @throws InvalidArgumentException when the currency code is not a known upper-case ISO 4217 code
     */
    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, self::validCurrency($currency));
    }

    /**
     * @throws InvalidArgumentException when the currency code is not a known upper-case ISO 4217 code
     */
    public static function zero(string $currency): self
    {
        return new self(0, self::validCurrency($currency));
    }

    /**
     * @throws InvalidArgumentException when the currencies differ
     * @throws OverflowException when the sum does not fit into an integer
     */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            self::toInt(BigInteger::of($this->minor)->plus($other->minor)),
            $this->currency,
        );
    }

    /**
     * @throws InvalidArgumentException when the currencies differ
     * @throws OverflowException when the difference does not fit into an integer
     */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            self::toInt(BigInteger::of($this->minor)->minus($other->minor)),
            $this->currency,
        );
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    /**
     * The single rounding point of Kokpit (D-09): HALF_UP (half away from zero),
     * applied once per invoice line amount. Every other method is exact.
     *
     * The exact amount in minor units is a decimal string ("137859.2") or a
     * rational string ("1/3"). Brick's default rounding mode would throw on an
     * inexact value; this method is where the rounding is deliberately chosen.
     *
     * @throws InvalidArgumentException when the string is not a decimal or rational number, or the currency is invalid
     * @throws OverflowException when the rounded amount does not fit into an integer
     */
    public static function fromExactMinor(string $exactMinor, string $currency): self
    {
        $currency = self::validCurrency($currency);

        try {
            $rounded = BigRational::of($exactMinor)->toScale(0, RoundingMode::HalfUp);
        } catch (MathException $e) {
            throw new InvalidArgumentException('The exact minor amount is not a decimal or rational number.', 0, $e);
        }

        return new self(self::toInt($rounded), $currency);
    }

    /**
     * The amount for one tracked duration at an hourly rate, rounded once.
     */
    public static function forDuration(self $hourlyRate, int $seconds): self
    {
        return self::forDurations($hourlyRate->currency, [[$hourlyRate, $seconds]]);
    }

    /**
     * The amount of one invoice line made of several duration parts: the exact
     * sum of rate * seconds / 3600 over all parts, rounded once (D-09).
     *
     * @param  iterable<array{0: self, 1: int}>  $parts  pairs of hourly rate and whole seconds
     *
     * @throws InvalidArgumentException when a part is in another currency than the line or has negative seconds
     */
    public static function forDurations(string $currency, iterable $parts): self
    {
        $exact = BigRational::zero();

        foreach ($parts as [$hourlyRate, $seconds]) {
            if ($hourlyRate->currency !== $currency) {
                throw new InvalidArgumentException('Every duration part must use the currency of the line.');
            }

            if ($seconds < 0) {
                throw new InvalidArgumentException('A duration cannot be negative.');
            }

            $exact = $exact->plus(
                BigRational::ofFraction(BigInteger::of($hourlyRate->minor)->multipliedBy($seconds), 3600),
            );
        }

        return self::fromExactMinor($exact->toString(), $currency);
    }

    /**
     * Converts this amount into another currency with an exchange rate.
     *
     * The rate is the stored NUMERIC(20,10) decimal string, quoted for
     * `$unitAmount` units of this currency (the central bank quotes 1, 100 or
     * 1000). The product is exact; the single rounding point is applied once
     * to the result. Each currency's default fraction digits decide the minor
     * unit, so JPY (no fraction) converts correctly.
     *
     * @param  string  $decimalRate  digits with an optional point and at most ten fraction digits, no comma, no exponent
     *
     * @throws InvalidArgumentException when the rate or the target currency is malformed or the unit amount is below one
     */
    public function convert(string $decimalRate, string $toCurrency, int $unitAmount = 1): self
    {
        if (preg_match('/^-?\d+(\.\d{1,10})?$/D', $decimalRate) !== 1) {
            throw new InvalidArgumentException('The exchange rate must be a decimal string with at most ten fraction digits.');
        }

        if ($unitAmount < 1) {
            throw new InvalidArgumentException('The quoted unit amount must be at least one.');
        }

        $target = self::validCurrency($toCurrency);
        $sourceDigits = Currency::of($this->currency)->getDefaultFractionDigits();
        $targetDigits = Currency::of($target)->getDefaultFractionDigits();

        $exact = BigRational::of($this->minor)
            ->dividedBy(BigInteger::ten()->power($sourceDigits))
            ->multipliedBy(BigDecimal::of($decimalRate))
            ->dividedBy($unitAmount)
            ->multipliedBy(BigInteger::ten()->power($targetDigits));

        return self::fromExactMinor($exact->toString(), $target);
    }

    /**
     * Formats the amount for display in a locale, for example "cs" gives "1 234,50 Kč".
     *
     * @throws InvalidArgumentException when the locale is unknown or the amount cannot be formatted exactly
     */
    public function format(string $locale): string
    {
        try {
            return BrickMoney::ofMinor($this->minor, $this->currency)->formatToLocale($locale);
        } catch (MoneyException $e) {
            throw new InvalidArgumentException('The amount cannot be formatted for this locale.', 0, $e);
        }
    }

    /**
     * @return array{minor: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency];
    }

    private static function validCurrency(string $currency): string
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('The currency must be an upper-case ISO 4217 code.');
        }

        try {
            return Currency::of($currency)->getCurrencyCode();
        } catch (UnknownCurrencyException $e) {
            throw new InvalidArgumentException('The currency is not a known ISO 4217 code.', 0, $e);
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money in different currencies cannot be combined.');
        }
    }

    private static function toInt(BigNumber $number): int
    {
        try {
            return $number->toInt();
        } catch (MathException $e) {
            throw new OverflowException('The amount does not fit into an integer number of minor units.', 0, $e);
        }
    }
}
