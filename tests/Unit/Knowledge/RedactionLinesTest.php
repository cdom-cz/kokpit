<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\RedactionLines;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The line list of the redaction form (Phase 19, plan 19-09; VER-05, D-12): single numbers and ranges, counted from 1,
 * sorted and unique. The field errors need the translator, so the application boots; nothing touches the database.
 */

/**
 * The message of the field error `lines` the callback throws, or null when it throws none.
 */
function redactionLinesError(Closure $callback): ?string
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors()['lines'][0] ?? null;
    }

    return null;
}

it('reads single numbers and ranges into sorted unique line numbers', function (): void {
    expect(RedactionLines::parse('3, 7-9', 10))->toBe([3, 7, 8, 9])
        ->and(RedactionLines::parse('9,3', 10))->toBe([3, 9])
        ->and(RedactionLines::parse(' 2 - 4 ,3,4-5', 10))->toBe([2, 3, 4, 5])
        ->and(RedactionLines::parse('5,5,5', 10))->toBe([5])
        ->and(RedactionLines::parse('1-10', 10))->toBe(range(1, 10));
});

it('refuses an empty list', function (): void {
    $message = __('knowledge.redact.errors.lines_required');

    expect(redactionLinesError(static fn () => RedactionLines::parse('', 10)))->toBe($message)
        ->and(redactionLinesError(static fn () => RedactionLines::parse("  \t ", 10)))->toBe($message);
});

it('refuses a token that is no number or range, a backwards range and line 0', function (): void {
    $message = __('knowledge.redact.errors.lines_invalid');

    foreach (['9-7', '0', '0-3', 'abc', '3;4', '3,,4', '3,', ',3', '-3', '3-', '1.5', '3-4-5', '1 2', '9999999999'] as $input) {
        expect(redactionLinesError(static fn () => RedactionLines::parse($input, 100000)))->toBe($message, $input);
    }
});

it('refuses a list longer than the limit before reading it', function (): void {
    $long = implode(',', array_fill(0, 600, '1'));

    expect(mb_strlen($long))->toBeGreaterThan(RedactionLines::MAX_INPUT_LENGTH)
        ->and(redactionLinesError(static fn () => RedactionLines::parse($long, 10)))->toBe(__('knowledge.redact.errors.lines_invalid'));
});

it('names the first line the version does not have', function (): void {
    expect(redactionLinesError(static fn () => RedactionLines::parse('11', 10)))->toBe(__('knowledge.redact.errors.line_missing', ['n' => 11]))
        ->and(redactionLinesError(static fn () => RedactionLines::parse('8-20', 10)))->toBe(__('knowledge.redact.errors.line_missing', ['n' => 11]))
        ->and(redactionLinesError(static fn () => RedactionLines::parse('15-20', 10)))->toBe(__('knowledge.redact.errors.line_missing', ['n' => 15]))
        ->and(RedactionLines::parse('10', 10))->toBe([10]);
});
