<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;

/**
 * The line diff of two stored article bodies (VER-04, D-09), built on sebastian/diff.
 *
 * Split rule: a body is cut into lines at CRLF, CR or LF, exactly as the secret guard cuts it
 * (SecretScanner::LINE_BREAK), so a line number the Admin reads here is the line number a refusal of the guard
 * names. The line ending is dropped, so CRLF text and LF text with the same lines compare equal. The library is
 * always fed lists of lines, never strings: a string input keeps the line breaks in its tokens and may add
 * line-ending warning entries, which are dropped here anyway.
 *
 * Collapse rule: the unchanged run before the first change keeps its last CONTEXT_LINES lines, the run after the
 * last change its first CONTEXT_LINES lines, a run between two changes CONTEXT_LINES lines on each side. A run
 * longer than what it keeps becomes one collapsed row that carries the number of hidden lines, so a run of exactly
 * twice CONTEXT_LINES between two changes stays expanded and one more line collapses to a separator of 1.
 *
 * Caps (owner decision OD-2 of 2026-11-25): at most MAX_ROWS rows are returned (the counts of added and removed
 * lines still cover the whole diff, and `truncated` says that rows were cut), and two bodies whose differing
 * middles (what is left after the common start and the common end are trimmed) together hold more than
 * MAX_COMPARED_LINES lines are reported `tooLarge` without calling the library. The Myers algorithm runs in
 * linear space but its time grows with the edit distance, so the budget bounds the work an adversarial pair of
 * bodies (up to 100,000 characters each) can cause. The worst case at the budget, 5,000 completely different
 * lines against 5,000 others, is measured in DDEV after the merge and recorded in the plan summary.
 *
 * The texts are the stored lines verbatim; nothing is escaped here, the view escapes.
 */
final class VersionDiff
{
    /** Unchanged lines kept on each side of a change. */
    public const int CONTEXT_LINES = 3;

    /** Rows returned at most; the rest is reported as truncated. */
    public const int MAX_ROWS = 1500;

    /** Lines of the two differing middles together that are compared at most. */
    public const int MAX_COMPARED_LINES = 10000;

    private const string LINE_BREAK = '/\r\n|\r|\n/';

    /**
     * The lines of a body; an empty body is one empty line.
     *
     * @return list<string>
     */
    public static function lines(string $body): array
    {
        return preg_split(self::LINE_BREAK, $body) ?: [$body];
    }

    public function compare(string $from, string $to): VersionDiffResult
    {
        $fromLines = self::lines($from);
        $toLines = self::lines($to);

        if ($fromLines === $toLines) {
            return VersionDiffResult::identical();
        }

        if ($this->middleLines($fromLines, $toLines) > self::MAX_COMPARED_LINES) {
            return VersionDiffResult::tooLarge();
        }

        $entries = (new Differ(new DiffOnlyOutputBuilder('')))->diffToArray($fromLines, $toLines);

        return $this->rowsOf($entries);
    }

    /**
     * The number of lines the two bodies hold after their common start and common end are trimmed.
     *
     * @param  list<string>  $from
     * @param  list<string>  $to
     */
    private function middleLines(array $from, array $to): int
    {
        $fromCount = count($from);
        $toCount = count($to);
        $shortest = min($fromCount, $toCount);

        $start = 0;

        while ($start < $shortest && $from[$start] === $to[$start]) {
            $start++;
        }

        $end = 0;

        while ($end < $shortest - $start && $from[$fromCount - 1 - $end] === $to[$toCount - 1 - $end]) {
            $end++;
        }

        return ($fromCount - $start - $end) + ($toCount - $start - $end);
    }

    /**
     * @param  list<array{0: mixed, 1: int}>  $entries
     */
    private function rowsOf(array $entries): VersionDiffResult
    {
        $rows = [];
        $total = 0;
        $added = 0;
        $removed = 0;
        $oldLine = 0;
        $newLine = 0;
        $hasChange = false;

        /** @var list<array{int, int, string}> $run the unchanged lines since the last change: [old, new, text] */
        $run = [];

        $emit = static function (DiffRow $row) use (&$rows, &$total): void {
            $total++;

            if ($total <= self::MAX_ROWS) {
                $rows[] = $row;
            }
        };

        foreach ($entries as [$token, $type]) {
            $text = (string) $token;

            if ($type === Differ::OLD) {
                $oldLine++;
                $newLine++;
                $run[] = [$oldLine, $newLine, $text];

                continue;
            }

            if ($type !== Differ::ADDED && $type !== Differ::REMOVED) {
                continue;
            }

            $this->flushRun($run, ! $hasChange, false, $emit);
            $run = [];
            $hasChange = true;

            if ($type === Differ::REMOVED) {
                $oldLine++;
                $removed++;
                $emit(DiffRow::removed($oldLine, $text));
            } else {
                $newLine++;
                $added++;
                $emit(DiffRow::added($newLine, $text));
            }
        }

        $this->flushRun($run, ! $hasChange, true, $emit);

        return new VersionDiffResult($rows, $added, $removed, false, $total > self::MAX_ROWS, false);
    }

    /**
     * Emits an unchanged run: whole when short, else its kept head, one collapsed row and its kept tail.
     *
     * @param  list<array{int, int, string}>  $run
     * @param  bool  $first  no change came before the run: only its last lines are kept
     * @param  bool  $last  no change comes after the run: only its first lines are kept
     * @param  callable(DiffRow): void  $emit
     */
    private function flushRun(array $run, bool $first, bool $last, callable $emit): void
    {
        $count = count($run);

        if ($count === 0) {
            return;
        }

        $keepHead = $first ? 0 : self::CONTEXT_LINES;
        $keepTail = $last ? 0 : self::CONTEXT_LINES;

        if ($count <= $keepHead + $keepTail) {
            foreach ($run as [$old, $new, $text]) {
                $emit(DiffRow::context($old, $new, $text));
            }

            return;
        }

        foreach (array_slice($run, 0, $keepHead) as [$old, $new, $text]) {
            $emit(DiffRow::context($old, $new, $text));
        }

        $emit(DiffRow::collapsed($count - $keepHead - $keepTail));

        foreach (array_slice($run, $count - $keepTail) as [$old, $new, $text]) {
            $emit(DiffRow::context($old, $new, $text));
        }
    }
}
