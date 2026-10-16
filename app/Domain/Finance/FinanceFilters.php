<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;

/**
 * What the finance overview is asked about: one Prague calendar month or one Prague calendar year (FI-02, D-17).
 *
 * The values come from the URL and from Livewire state, so every one of them is client-controlled and is validated
 * here, never trusted (T-09-52). The period must be `month` or `year`, a year must be a whole number from
 * {@see self::MIN_YEAR} to {@see self::MAX_YEAR} and a month a whole number from 1 to 12 (only a month period
 * needs one). Anything else, an array, a float, a number out of range or a missing value, makes the whole selection
 * fall back to the current Prague month; nothing throws.
 *
 * The period is a half-open range of Prague calendar dates, `from` included and `until` excluded, because a
 * transaction is dated by its day and not by an instant.
 */
final readonly class FinanceFilters
{
    public const string PERIOD_MONTH = 'month';

    public const string PERIOD_YEAR = 'year';

    public const int MIN_YEAR = 2000;

    public const int MAX_YEAR = 2100;

    /**
     * @param  int  $month  1 to 12; in a year period only the month the form shows when the Admin switches back
     */
    private function __construct(
        private string $kind,
        private int $year,
        private int $month,
    ) {}

    /**
     * The selection of the given values; the current Prague month for anything invalid. Never throws.
     */
    public static function fromInput(mixed $period, mixed $year, mixed $month, ?CarbonInterface $now = null): self
    {
        $kind = is_string($period) && in_array($period, [self::PERIOD_MONTH, self::PERIOD_YEAR], true) ? $period : null;
        $validYear = self::whole($year, self::MIN_YEAR, self::MAX_YEAR);
        $validMonth = self::whole($month, 1, 12);

        if ($kind === self::PERIOD_YEAR && $validYear !== null) {
            return new self(self::PERIOD_YEAR, $validYear, $validMonth ?? 1);
        }

        if ($kind === self::PERIOD_MONTH && $validYear !== null && $validMonth !== null) {
            return new self(self::PERIOD_MONTH, $validYear, $validMonth);
        }

        return self::currentMonth($now);
    }

    /**
     * The current Prague month.
     */
    public static function currentMonth(?CarbonInterface $now = null): self
    {
        $local = CarbonImmutable::instance($now ?? TimerClock::now())->setTimezone(FilamentTimezone::get());

        return new self(self::PERIOD_MONTH, $local->year, $local->month);
    }

    /**
     * The Prague dates of the period, `from` included and `until` excluded, as `Y-m-d`.
     *
     * @return array{from: string, until: string}
     */
    public function period(): array
    {
        if ($this->isYear()) {
            return [
                'from' => sprintf('%04d-01-01', $this->year),
                'until' => sprintf('%04d-01-01', $this->year + 1),
            ];
        }

        $next = $this->month === 12 ? [$this->year + 1, 1] : [$this->year, $this->month + 1];

        return [
            'from' => sprintf('%04d-%02d-01', $this->year, $this->month),
            'until' => sprintf('%04d-%02d-01', $next[0], $next[1]),
        ];
    }

    /**
     * The year of the selection: the year itself, or the year of the chosen month (the chart shows it).
     */
    public function year(): int
    {
        return $this->year;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function isYear(): bool
    {
        return $this->kind === self::PERIOD_YEAR;
    }

    /**
     * The words that name the period: "říjen 2026" or "2026".
     */
    public function label(): string
    {
        return $this->isYear()
            ? (string) $this->year
            : __('finance.overview.months.'.$this->month).' '.$this->year;
    }

    /**
     * The state of the filter form for this selection.
     *
     * @return array{period: string, year: int, month: int}
     */
    public function toState(): array
    {
        return ['period' => $this->kind, 'year' => $this->year, 'month' => $this->month];
    }

    /**
     * A whole number in the range, from an int or a string of digits; null for anything else.
     */
    private static function whole(mixed $value, int $min, int $max): ?int
    {
        if (is_string($value) && preg_match('/^\d{1,4}$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }
}
