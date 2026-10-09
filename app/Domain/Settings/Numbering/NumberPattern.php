<?php

declare(strict_types=1);

namespace App\Domain\Settings\Numbering;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * A parsed document number pattern (D-05).
 *
 * Pure PHP, no framework. The pattern drives two things: how a counter value
 * is written out (format) and which allocator counter it comes from
 * (scopeKey), so a pattern change can start a new series but never rewrite a
 * number that was already handed out.
 *
 * Grammar:
 *  - tokens {YYYY}, {YY}, {MM} and {N} to {NNNNNNNNNN} (pad width 1 to 10; the
 *    counter is padded, never truncated)
 *  - literal characters A-Z a-z 0-9 . _ / - ; an invoice pattern allows digits only
 *  - exactly one counter token; a year token at most once ({YYYY} and {YY}
 *    together count as twice); {MM} at most once and only together with a year
 *  - at most 32 characters, checked before anything else
 *  - an invoice number is at most 10 characters (it is the SPAYD variable
 *    symbol), so a pattern whose shortest number is longer is refused and a
 *    counter value that would outgrow 10 characters throws
 *  - the task pattern is exactly {KEY}-{N}: the project key, a dash and the
 *    per-project counter
 *
 * The parser is one linear scan; no regular expression runs on the pattern.
 * {YYYY} and {YY} give the same yearly scope, so switching between them keeps
 * the counter.
 */
final readonly class NumberPattern
{
    public const int MAX_LENGTH = 32;

    public const int INVOICE_MAX_LENGTH = 10;

    /** Project keys as stored on a project: two to six capital letters. */
    public const string PROJECT_KEY_PATTERN = '/^[A-Z]{2,6}$/D';

    private const string LITERAL_PATTERN = '/^[A-Za-z0-9._\/-]$/D';

    private const string TIME_ZONE = 'Europe/Prague';

    /**
     * @param  list<array{type: 'literal'|'year4'|'year2'|'month'|'counter'|'key', text: string, width: int}>  $parts
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
        if (strlen($pattern) > self::MAX_LENGTH) {
            throw new InvalidNumberPattern('too_long');
        }

        if ($kind === DocumentKind::Task) {
            if ($pattern !== DocumentKind::Task->defaultPattern()) {
                throw new InvalidNumberPattern('task_fixed');
            }

            return new self($pattern, $kind, [
                ['type' => 'key', 'text' => '', 'width' => 0],
                ['type' => 'literal', 'text' => '-', 'width' => 0],
                ['type' => 'counter', 'text' => '', 'width' => 1],
            ], ResetPeriod::Never);
        }

        $parts = [];
        $counters = 0;
        $year = false;
        $month = false;
        $minimalLength = 0;
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
                    if ($year) {
                        throw new InvalidNumberPattern('duplicate_token');
                    }

                    $year = true;
                    $parts[] = ['type' => $name === 'YYYY' ? 'year4' : 'year2', 'text' => '', 'width' => 0];
                    $minimalLength += strlen($name);
                } elseif ($name === 'MM') {
                    if ($month) {
                        throw new InvalidNumberPattern('duplicate_token');
                    }

                    $month = true;
                    $parts[] = ['type' => 'month', 'text' => '', 'width' => 0];
                    $minimalLength += 2;
                } elseif ($name !== '' && strlen($name) <= 10 && strspn($name, 'N') === strlen($name)) {
                    $counters++;
                    $parts[] = ['type' => 'counter', 'text' => '', 'width' => strlen($name)];
                    $minimalLength += strlen($name);
                } else {
                    throw new InvalidNumberPattern('unknown_token');
                }

                continue;
            }

            if (preg_match(self::LITERAL_PATTERN, $char) !== 1) {
                throw new InvalidNumberPattern('literal');
            }

            if ($kind === DocumentKind::Invoice && ! ctype_digit($char)) {
                throw new InvalidNumberPattern('invoice_digits');
            }

            $parts[] = ['type' => 'literal', 'text' => $char, 'width' => 0];
            $minimalLength++;
            $i++;
        }

        if ($counters !== 1) {
            throw new InvalidNumberPattern('counter_count');
        }

        if ($month && ! $year) {
            throw new InvalidNumberPattern('month_without_year');
        }

        if ($kind === DocumentKind::Invoice && $minimalLength > self::INVOICE_MAX_LENGTH) {
            throw new InvalidNumberPattern('invoice_length');
        }

        return new self($pattern, $kind, $parts, match (true) {
            $month => ResetPeriod::Monthly,
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
     *
     * @throws LogicException for the task pattern, whose counter is per project
     */
    public function scopeKey(DateTimeInterface $at): string
    {
        if ($this->kind === DocumentKind::Task) {
            throw new LogicException('A task counter is per project; use DocumentNumbering::nextTaskNumber().');
        }

        $local = self::local($at);

        return $this->kind->sequenceKind().':'.match ($this->reset) {
            ResetPeriod::Yearly => $local->format('Y'),
            ResetPeriod::Monthly => $local->format('Y-m'),
            ResetPeriod::Never => 'all',
        };
    }

    /**
     * Writes the counter value into the pattern, dates taken in Europe/Prague.
     *
     * @throws OverflowException when an invoice number would exceed 10 characters
     * @throws InvalidArgumentException for a counter below 1 or a task number without a valid project key
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
                'key' => self::validProjectKey($projectKey),
            };
        }

        if ($this->kind === DocumentKind::Invoice && strlen($result) > self::INVOICE_MAX_LENGTH) {
            throw new OverflowException('The invoice number would be longer than '.self::INVOICE_MAX_LENGTH.' characters.');
        }

        return $result;
    }

    private static function validProjectKey(?string $projectKey): string
    {
        if ($projectKey === null || preg_match(self::PROJECT_KEY_PATTERN, $projectKey) !== 1) {
            throw new InvalidArgumentException('A task number needs a project key of two to six capital letters.');
        }

        return $projectKey;
    }

    private static function local(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone(self::TIME_ZONE));
    }
}
