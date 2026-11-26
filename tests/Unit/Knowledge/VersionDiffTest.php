<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\DiffRow;
use App\Domain\Knowledge\Support\VersionDiff;
use App\Domain\Knowledge\Support\VersionDiffResult;
use Tests\Support\KnowledgeHostileCorpus;

/*
 * The diff engine turns two stored bodies into numbered rows (VER-04, D-09): lines are cut like the secret guard cuts
 * them, unchanged runs collapse to three lines of context, rows are capped at 1,500 and a changed middle above
 * 10,000 lines is reported too large without calling the library (owner decision OD-2). Unit tests run without the
 * application. Every text is fictional.
 */

/**
 * @param  list<string>  $lines
 */
function versionDiffBody(array $lines): string
{
    return implode("\n", $lines);
}

/**
 * Lines "<prefix>1" to "<prefix><count>".
 *
 * @return list<string>
 */
function versionDiffLines(string $prefix, int $count, int $first = 1): array
{
    return array_map(static fn (int $n): string => $prefix.$n, range($first, $first + $count - 1));
}

/**
 * The rows as plain tuples: [type, old line, new line, text, hidden count].
 *
 * @return list<array{string, int|null, int|null, string, int}>
 */
function versionDiffTuples(VersionDiffResult $result): array
{
    return array_map(
        static fn (DiffRow $row): array => [$row->type, $row->oldLine, $row->newLine, $row->text, $row->collapsed],
        $result->rows,
    );
}

function versionDiffCompare(string $from, string $to): VersionDiffResult
{
    return (new VersionDiff)->compare($from, $to);
}

it('splits a body at CRLF, CR and LF', function (): void {
    expect(VersionDiff::lines("a\r\nb\rc\nd"))->toBe(['a', 'b', 'c', 'd'])
        ->and(VersionDiff::lines(''))->toBe(['']);
});

it('reports identical bodies without rows or counts', function (): void {
    $result = versionDiffCompare("one\ntwo", "one\ntwo");

    expect($result->identical)->toBeTrue()
        ->and($result->rows)->toBe([])
        ->and($result->added)->toBe(0)
        ->and($result->removed)->toBe(0)
        ->and($result->truncated)->toBeFalse()
        ->and($result->tooLarge)->toBeFalse();
});

it('numbers a changed line on both sides', function (): void {
    $result = versionDiffCompare("one\ntwo\nthree", "one\nTWO\nthree");

    expect(versionDiffTuples($result))->toBe([
        ['context', 1, 1, 'one', 0],
        ['removed', 2, null, 'two', 0],
        ['added', null, 2, 'TWO', 0],
        ['context', 3, 3, 'three', 0],
    ])
        ->and($result->added)->toBe(1)
        ->and($result->removed)->toBe(1)
        ->and($result->identical)->toBeFalse();
});

it('keeps the numbers right for an added last line, a removed first line and a pure insertion', function (): void {
    $appended = versionDiffCompare("one\ntwo", "one\ntwo\nthree");
    $dropped = versionDiffCompare("one\ntwo\nthree", "two\nthree");
    $inserted = versionDiffCompare("one\nthree", "one\ntwo\nthree");

    expect(versionDiffTuples($appended))->toBe([
        ['context', 1, 1, 'one', 0],
        ['context', 2, 2, 'two', 0],
        ['added', null, 3, 'three', 0],
    ])
        ->and(versionDiffTuples($dropped))->toBe([
            ['removed', 1, null, 'one', 0],
            ['context', 2, 1, 'two', 0],
            ['context', 3, 2, 'three', 0],
        ])
        ->and(versionDiffTuples($inserted))->toBe([
            ['context', 1, 1, 'one', 0],
            ['added', null, 2, 'two', 0],
            ['context', 2, 3, 'three', 0],
        ]);
});

it('collapses long unchanged stretches around one change to three lines of context', function (): void {
    $before = versionDiffLines('head', 20);
    $after = versionDiffLines('tail', 20);

    $from = versionDiffBody([...$before, 'old', ...$after]);
    $to = versionDiffBody([...$before, 'new', ...$after]);
    $result = versionDiffCompare($from, $to);

    expect(versionDiffTuples($result))->toBe([
        ['collapsed', null, null, '', 17],
        ['context', 18, 18, 'head18', 0],
        ['context', 19, 19, 'head19', 0],
        ['context', 20, 20, 'head20', 0],
        ['removed', 21, null, 'old', 0],
        ['added', null, 21, 'new', 0],
        ['context', 22, 22, 'tail1', 0],
        ['context', 23, 23, 'tail2', 0],
        ['context', 24, 24, 'tail3', 0],
        ['collapsed', null, null, '', 17],
    ]);
});

it('keeps a run of six unchanged lines between two changes and collapses seven', function (): void {
    $six = versionDiffLines('mid', 6);
    $seven = versionDiffLines('mid', 7);

    $withSix = versionDiffCompare(
        versionDiffBody(['a', ...$six, 'b']),
        versionDiffBody(['A', ...$six, 'B']),
    );
    $withSeven = versionDiffCompare(
        versionDiffBody(['a', ...$seven, 'b']),
        versionDiffBody(['A', ...$seven, 'B']),
    );

    $kinds = static fn (VersionDiffResult $result): array => array_map(static fn (DiffRow $row): string => $row->type, $result->rows);

    expect($kinds($withSix))->toBe(['removed', 'added', ...array_fill(0, 6, 'context'), 'removed', 'added'])
        ->and($kinds($withSeven))->toBe(['removed', 'added', 'context', 'context', 'context', 'collapsed', 'context', 'context', 'context', 'removed', 'added'])
        ->and(array_values(array_map(
            static fn (DiffRow $row): int => $row->collapsed,
            array_filter($withSeven->rows, static fn (DiffRow $row): bool => $row->type === DiffRow::COLLAPSED),
        )))->toBe([1]);
});

it('cuts the rows at the row cap and still counts the whole diff', function (): void {
    $from = versionDiffBody(versionDiffLines('old', 1000));
    $to = versionDiffBody(versionDiffLines('new', 1000));
    $result = versionDiffCompare($from, $to);

    expect($result->rows)->toHaveCount(VersionDiff::MAX_ROWS)
        ->and($result->truncated)->toBeTrue()
        ->and($result->added)->toBe(1000)
        ->and($result->removed)->toBe(1000)
        ->and($result->tooLarge)->toBeFalse();
});

it('does not mark a diff that fits the row cap as truncated', function (): void {
    $result = versionDiffCompare(versionDiffBody(versionDiffLines('old', 700)), versionDiffBody(versionDiffLines('new', 700)));

    expect($result->rows)->toHaveCount(1400)
        ->and($result->truncated)->toBeFalse();
});

it('reports a differing middle above the work budget as too large without rows', function (): void {
    $from = versionDiffBody(['start', ...versionDiffLines('old', 5001), 'end']);
    $to = versionDiffBody(['start', ...versionDiffLines('new', 5000), 'end']);
    $result = versionDiffCompare($from, $to);

    expect($result->tooLarge)->toBeTrue()
        ->and($result->rows)->toBe([])
        ->and($result->added)->toBe(0)
        ->and($result->removed)->toBe(0)
        ->and($result->identical)->toBeFalse()
        ->and($result->truncated)->toBeFalse();
});

it('compares a middle of exactly the work budget', function (): void {
    $same = versionDiffLines('same', 4998);

    $from = versionDiffBody(['start', 'first old', ...$same, 'last old', 'end']);
    $to = versionDiffBody(['start', 'first new', ...$same, 'last new', 'end']);
    $result = versionDiffCompare($from, $to);

    expect($result->tooLarge)->toBeFalse()
        ->and($result->added)->toBe(2)
        ->and($result->removed)->toBe(2)
        ->and(array_count_values(array_map(static fn (DiffRow $row): string => $row->type, $result->rows)))
        ->toMatchArray(['added' => 2, 'removed' => 2, 'collapsed' => 1]);
});

it('treats CRLF and LF endings of the same lines as identical', function (): void {
    $result = versionDiffCompare("one\r\ntwo\r\nthree", "one\ntwo\nthree");

    expect($result->identical)->toBeTrue();
});

it('returns only the four row types and no line break inside a row text', function (): void {
    $result = versionDiffCompare("a\r\nb\rc\nd\r\ne", "a\nx\r\nc\ny\rz");

    expect($result->rows)->not->toBeEmpty();

    foreach ($result->rows as $row) {
        expect($row->type)->toBeIn([DiffRow::CONTEXT, DiffRow::ADDED, DiffRow::REMOVED, DiffRow::COLLAPSED])
            ->and($row->text)->not->toMatch('/[\r\n]/');
    }
});

it('passes every hostile text through unchanged', function (): void {
    foreach (KnowledgeHostileCorpus::cases() as $label => $text) {
        $result = versionDiffCompare('plain line', $text);
        $texts = array_map(static fn (DiffRow $row): string => $row->text, $result->rows);

        foreach (VersionDiff::lines($text) as $line) {
            expect(in_array($line, $texts, true))->toBeTrue($label);
        }
    }
});
