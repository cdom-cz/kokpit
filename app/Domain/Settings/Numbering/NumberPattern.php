<?php

declare(strict_types=1);

namespace App\Domain\Settings\Numbering;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A parsed document number pattern (D-05).
 *
 * Pure PHP, no framework. The pattern drives two things: how a counter value
 * is written out (format) and which allocator counter it comes from
 * (scopeKey), so a pattern change can start a new series but never rewrite a
 * number that was already handed out.
 */
final readonly class NumberPattern
{
    private const string TIME_ZONE = 'Europe/Prague';

    /**
     * @param  list<array{type: 'literal'|'year4'|'year2'|'month'|'counter', text: string, width: int}>  $parts
     */
    private function __construct(
        private string $pattern,
        private DocumentKind $kind,
        private array $parts,
        private ResetPeriod $reset,
    ) {}

    /**
     * @throws InvalidNumberPattern
     */
    public static function parse(string $pattern, DocumentKind $kind): self
    {
        if ($kind === DocumentKind::Task) {
            throw new InvalidNumberPattern('task_fixed');
        }

        $parts = [];
        $counters = 0;
        $year = false;
        $month = false;
        $length = strlen($pattern);

        for ($i = 0; $i < $length;) {
            $char = $pattern[$i];

            if ($char === '{') {
                $end = strpos($pattern, '}', $i);

                if ($end === false) {
                    throw new InvalidNumberPattern('unclosed_token');
                }

                $name = substr($pattern, $i + 1, $end - $i - 1);
                $i = $end + 1;

                if ($name === 'YYYY' || $name === 'YY') {
                    $year = true;
                    $parts[] = ['type' => $name === 'YYYY' ? 'year4' : 'year2', 'text' => '', 'width' => 0];
                } elseif ($name === 'MM') {
                    $month = true;
                    $parts[] = ['type' => 'month', 'text' => '', 'width' => 0];
                } elseif ($name !== '' && strlen($name) <= 10 && strspn($name, 'N') === strlen($name)) {
                    $counters++;
                    $parts[] = ['type' => 'counter', 'text' => '', 'width' => strlen($name)];
                } else {
                    throw new InvalidNumberPattern('unknown_token');
                }

                continue;
            }

            if (! ctype_digit($char)) {
                throw new InvalidNumberPattern('literal');
            }

            $parts[] = ['type' => 'literal', 'text' => $char, 'width' => 0];
            $i++;
        }

        if ($counters !== 1) {
            throw new InvalidNumberPattern('counter_count');
        }

        return new self($pattern, $kind, $parts, match (true) {
            $year && $month => ResetPeriod::Monthly,
            $year => ResetPeriod::Yearly,
            default => ResetPeriod::Never,
        });
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function resetPeriod(): ResetPeriod
    {
        return $this->reset;
    }

    /**
     * The allocator scope key: kind:YYYY, kind:YYYY-MM or kind:all, year and
     * month taken in Europe/Prague. {YYYY} and {YY} share the yearly key.
     */
    public function scopeKey(DateTimeInterface $at): string
    {
        $local = self::local($at);

        return $this->kind->sequenceKind().':'.match ($this->reset) {
            ResetPeriod::Yearly => $local->format('Y'),
            ResetPeriod::Monthly => $local->format('Y-m'),
            ResetPeriod::Never => 'all',
        };
    }

    /**
     * Writes the counter value into the pattern, dates taken in Europe/Prague.
     */
    public function format(int $number, DateTimeInterface $at, ?string $projectKey = null): string
    {
        if ($number < 1) {
            throw new InvalidArgumentException('A counter value starts at 1.');
        }

        $local = self::local($at);
        $result = '';

        foreach ($this->parts as $part) {
            $result .= match ($part['type']) {
                'literal' => $part['text'],
                'year4' => $local->format('Y'),
                'year2' => $local->format('y'),
                'month' => $local->format('m'),
                'counter' => str_pad((string) $number, $part['width'], '0', STR_PAD_LEFT),
            };
        }

        return $result;
    }

    private static function local(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone(self::TIME_ZONE));
    }
}
