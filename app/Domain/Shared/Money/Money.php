<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Exception\MoneyException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\IsoCurrencyProvider;
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
     * Parses an amount typed by a person in major units ("1250,50" or "1250.5").
     *
     * This constructor never rounds. The accepted shape is an optional minus
     * sign, digits and an optional fraction separated by a comma or a dot;
     * grouping characters, spaces, exponents and the empty string are refused.
     * A fraction longer than the currency's ISO 4217 minor unit is an error, not
     * a rounding: {@see Money::fromExactMinor()} stays the single rounding point.
     *
     * @throws InvalidArgumentException when the text is malformed, has more fraction digits than the currency allows, or the currency is invalid
     * @throws OverflowException when the amount in minor units does not fit into an integer
     */
    public static function fromMajor(string $amount, string $currency): self
    {
        $currency = self::validCurrency($currency);
        $amount = trim($amount);

        if (preg_match('/^-?\d+([.,]\d+)?$/D', $amount) !== 1) {
            throw new InvalidArgumentException('The amount must be digits with an optional decimal comma or point.');
        }

        try {
            $minor = BigDecimal::of(str_replace(',', '.', $amount))
                ->toScale(Currency::of($currency)->getDefaultFractionDigits())
                ->getUnscaledValue();
        } catch (RoundingNecessaryException $e) {
            throw new InvalidArgumentException('The amount has more decimals than the currency allows.', 0, $e);
        }

        return new self(self::toInt($minor), $currency);
    }

    /**
     * The amount in major units as a decimal string with a point and exactly the
     * currency's fraction digits: "1250.50", "-3.00", "1500" for a currency without fraction.
     */
    public function toMajor(): string
    {
        return BigDecimal::ofUnscaledValue($this->minor, Currency::of($this->currency)->getDefaultFractionDigits())->toString();
    }

    /**
     * Every ISO 4217 code known to the money library, sorted.
     *
     * @return list<string>
     */
    public static function isoCurrencyCodes(): array
    {
        $codes = array_map(
            static fn (Currency $currency): string => $currency->getCurrencyCode(),
            array_values(IsoCurrencyProvider::getInstance()->getAvailableCurrencies()),
        );
        sort($codes);

        return $codes;
    }

    /**
     * Whether the text is an upper-case ISO 4217 code known to the money library.
     */
    public static function isKnownCurrency(string $code): bool
    {
        try {
            self::validCurrency($code);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
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
     * @param  string  $decimalRate  a positive number: digits with an optional point and at most ten fraction digits, no sign, no comma, no exponent
     *
     * @throws InvalidArgumentException when the rate is malformed, zero or negative, the target currency is malformed or the unit amount is below one
     * @throws OverflowException when the converted amount does not fit into an integer
     */
    public function convert(string $decimalRate, string $toCurrency, int $unitAmount = 1): self
    {
        $target = self::validConversion($decimalRate, $toCurrency, $unitAmount);

        return self::fromExactMinor(
            self::convertedExact(BigRational::of($this->minor), $this->currency, $decimalRate, $target, $unitAmount)->toString(),
            $target,
        );
    }

    /**
     * The amount of one duration at an hourly rate, converted into another currency, rounded once.
     *
     * The exact value is `rate / 10^sourceDigits * seconds / 3600 * decimalRate / unitAmount * 10^targetDigits`
     * as one rational, and {@see Money::fromExactMinor()} rounds it a single time. This can differ from
     * `forDuration()` followed by `convert()`, which rounds the client-currency amount first and then the CZK
     * amount again (research Pitfall 6): 100.00 EUR per hour for 61 s at 25.125 is 42.57 CZK here and 42.46
     * CZK the double-rounded way. Reports price an entry in CZK with this method.
     *
     * @param  string  $decimalRate  same shape as for {@see Money::convert()}
     *
     * @throws InvalidArgumentException when the rate is malformed, zero or negative, the target currency is malformed, the unit amount is below one or the seconds are negative
     */
    public static function forDurationConverted(self $hourlyRate, int $seconds, string $decimalRate, string $toCurrency, int $unitAmount = 1): self
    {
        $target = self::validConversion($decimalRate, $toCurrency, $unitAmount);

        if ($seconds < 0) {
            throw new InvalidArgumentException('A duration cannot be negative.');
        }

        $exactDuration = BigRational::ofFraction(BigInteger::of($hourlyRate->minor)->multipliedBy($seconds), 3600);

        return self::fromExactMinor(
            self::convertedExact($exactDuration, $hourlyRate->currency, $decimalRate, $target, $unitAmount)->toString(),
            $target,
        );
    }

    /**
     * The effective hourly rate of a fixed price over the time spent on it: `price * 3600 / seconds` in the
     * currency of the price, rounded once at the single rounding point.
     *
     * @throws InvalidArgumentException when the seconds are below one
     */
    public static function hourlyRateFor(self $price, int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('The effective hourly rate needs at least one second.');
        }

        $exact = BigRational::ofFraction(BigInteger::of($price->minor)->multipliedBy(3600), $seconds);

        return self::fromExactMinor($exact->toString(), $price->currency);
    }

    /**
     * The amount of an invoice line: this unit price times a quantity, rounded once at the single rounding point.
     *
     * The factor is the canonical decimal text of a quantity (`InvoiceQuantity::toDecimal()`, ruling R-01-3), so
     * this class does not depend on the Invoicing domain. The product is exact and {@see Money::fromExactMinor()}
     * rounds it HALF_UP, away from zero for a negative credit-note quantity. This is the number the PostgreSQL
     * CHECK `round(quantity * unit_price_minor) = amount_minor` on `invoice_items` computes (plan 10-04), so the
     * editor, the printed document and the database agree.
     *
     * @param  string  $decimalFactor  an optional minus sign, up to ten digits and an optional point with at most four fraction digits; no comma, no exponent, no plus sign
     *
     * @throws InvalidArgumentException when the factor is malformed
     * @throws OverflowException when the rounded amount does not fit into an integer
     */
    public function times(string $decimalFactor): self
    {
        $exact = BigRational::of($this->minor)->multipliedBy(self::validFactor($decimalFactor));

        return self::fromExactMinor($exact->toString(), $this->currency);
    }

    /**
     * The amount with the opposite sign, for credit notes.
     *
     * @throws OverflowException when the negated amount does not fit into an integer
     */
    public function negated(): self
    {
        return new self(self::toInt(BigInteger::of($this->minor)->negated()), $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    /**
     * -1, 0 or 1 when this amount is less than, equal to or greater than the other.
     *
     * @throws InvalidArgumentException when the currencies differ
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    /**
     * Converts this amount between any two currencies with both CNB quotes, rounded once.
     *
     * Each quote is the central bank's rate of its currency in CZK for `$unit` units (CZK itself is rate "1",
     * unit 1). The exact value is `amount * sourceRate / sourceUnit * targetUnit / targetRate` as one rational,
     * scaled by the fraction digits of both currencies, and {@see Money::fromExactMinor()} rounds it a single
     * time. Unlike {@see Money::convert()} it needs no inverted rate, which is not exactly representable in ten
     * fraction digits. For a CZK target it equals `convert()` exactly. It prices document totals in CZK and the
     * converted unit price printed on a converted line (a display figure, never an input of the line amount,
     * see {@see Money::timesThroughCzk()}).
     *
     * @param  string  $sourceRate  a positive number: digits with an optional point and at most ten fraction digits
     * @param  string  $targetRate  same shape as the source rate
     *
     * @throws InvalidArgumentException when a rate is malformed, zero or negative, a unit is below one or a currency is invalid
     * @throws OverflowException when the converted amount does not fit into an integer
     */
    public function convertThroughCzk(string $sourceRate, int $sourceUnit, string $targetCurrency, string $targetRate, int $targetUnit): self
    {
        $target = self::validCrossConversion($this->currency, $sourceRate, $sourceUnit, $targetCurrency, $targetRate, $targetUnit);

        return self::fromExactMinor(
            self::convertedThroughCzkExact(BigRational::of($this->minor), $this->currency, $sourceRate, $sourceUnit, $target, $targetRate, $targetUnit)->toString(),
            $target,
        );
    }

    /**
     * The converted amount of an invoice line, rounded exactly once (D-09, ruling R-01-1).
     *
     * `minor * quantity * sourceRate / sourceUnit * targetUnit / targetRate`, with the fraction-digit scales of both
     * currencies, is built as one exact rational and handed to {@see Money::fromExactMinor()} a single time. This
     * is the only way a converted line amount is computed: the converted unit price printed on the line
     * ({@see Money::convertThroughCzk()}) is a display figure rounded on its own and never feeds this amount, so
     * quantity times the printed price can differ from the printed amount by rounding. A line in the document
     * currency is priced with {@see Money::times()} instead.
     *
     * @param  string  $decimalQuantity  same shape as the factor of {@see Money::times()}
     * @param  string  $sourceRate  a positive number: digits with an optional point and at most ten fraction digits
     * @param  string  $targetRate  same shape as the source rate
     *
     * @throws InvalidArgumentException when the quantity or a rate is malformed, a rate is zero, a unit is below one, a currency is invalid or both currencies are equal
     * @throws OverflowException when the converted amount does not fit into an integer
     */
    public function timesThroughCzk(string $decimalQuantity, string $sourceRate, int $sourceUnit, string $targetCurrency, string $targetRate, int $targetUnit): self
    {
        $quantity = self::validFactor($decimalQuantity);
        $target = self::validCrossConversion($this->currency, $sourceRate, $sourceUnit, $targetCurrency, $targetRate, $targetUnit);

        if ($target === $this->currency) {
            throw new InvalidArgumentException('A line in the document currency is priced with times(), not converted.');
        }

        return self::fromExactMinor(
            self::convertedThroughCzkExact(BigRational::of($this->minor)->multipliedBy($quantity), $this->currency, $sourceRate, $sourceUnit, $target, $targetRate, $targetUnit)->toString(),
            $target,
        );
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

    /**
     * @throws InvalidArgumentException when the text is not a decimal with at most ten integer and four fraction digits
     */
    private static function validFactor(string $decimalFactor): BigDecimal
    {
        if (preg_match('/^-?\d{1,10}(\.\d{1,4})?$/D', $decimalFactor) !== 1) {
            throw new InvalidArgumentException('The factor must be a decimal string with at most four fraction digits.');
        }

        return BigDecimal::of($decimalFactor);
    }

    /**
     * Validates the inputs every conversion shares and returns the target currency code.
     *
     * Digits only (no sign, exponent, INF or NAN) and not zero: a zero rate would turn every amount into
     * nothing and a negative one would flip the sign of a billed amount, in an immutable invoice snapshot.
     *
     * @throws InvalidArgumentException
     */
    private static function validConversion(string $decimalRate, string $toCurrency, int $unitAmount): string
    {
        if (preg_match('/^\d+(\.\d{1,10})?$/D', $decimalRate) !== 1 || BigDecimal::of($decimalRate)->isZero()) {
            throw new InvalidArgumentException('The exchange rate must be a positive decimal string with at most ten fraction digits.');
        }

        if ($unitAmount < 1) {
            throw new InvalidArgumentException('The quoted unit amount must be at least one.');
        }

        return self::validCurrency($toCurrency);
    }

    /**
     * An exact amount in the minor units of `$sourceCurrency`, converted into the minor units of the target.
     * Nothing is rounded here.
     */
    private static function convertedExact(BigRational $exactSourceMinor, string $sourceCurrency, string $decimalRate, string $target, int $unitAmount): BigRational
    {
        $sourceDigits = Currency::of($sourceCurrency)->getDefaultFractionDigits();
        $targetDigits = Currency::of($target)->getDefaultFractionDigits();

        return $exactSourceMinor
            ->dividedBy(BigInteger::ten()->power($sourceDigits))
            ->multipliedBy(BigDecimal::of($decimalRate))
            ->dividedBy($unitAmount)
            ->multipliedBy(BigInteger::ten()->power($targetDigits));
    }

    /**
     * Validates both quotes of a conversion through CZK and returns the target currency code.
     *
     * @throws InvalidArgumentException
     */
    private static function validCrossConversion(string $sourceCurrency, string $sourceRate, int $sourceUnit, string $targetCurrency, string $targetRate, int $targetUnit): string
    {
        self::validConversion($sourceRate, $sourceCurrency, $sourceUnit);

        return self::validConversion($targetRate, $targetCurrency, $targetUnit);
    }

    /**
     * An exact amount in the minor units of `$sourceCurrency`, converted through CZK into the minor units of the
     * target with both CNB quotes. Nothing is rounded here.
     */
    private static function convertedThroughCzkExact(BigRational $exactSourceMinor, string $sourceCurrency, string $sourceRate, int $sourceUnit, string $target, string $targetRate, int $targetUnit): BigRational
    {
        $sourceDigits = Currency::of($sourceCurrency)->getDefaultFractionDigits();
        $targetDigits = Currency::of($target)->getDefaultFractionDigits();

        return $exactSourceMinor
            ->dividedBy(BigInteger::ten()->power($sourceDigits))
            ->multipliedBy(BigDecimal::of($sourceRate))
            ->dividedBy($sourceUnit)
            ->multipliedBy($targetUnit)
            ->dividedBy(BigDecimal::of($targetRate))
            ->multipliedBy(BigInteger::ten()->power($targetDigits));
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
