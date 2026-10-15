<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;

/**
 * The cells of a report export (RE-03, D-09; research Pitfall 1, Pattern 6).
 *
 * Client names (read from ARES), project names, task titles and user names are user text, and a spreadsheet
 * application turns text that starts with `=`, `+`, `-` or `@` into a live formula. OpenSpout's own
 * value-inferring cell factory does the same for a string starting with `=`. Report code therefore never lets
 * a library infer a cell type from user text: every text goes through {@see self::text()}, which returns an
 * explicit string cell after neutralising a dangerous start with a leading apostrophe, and every number is
 * written by {@see self::decimal()} or {@see self::integer()} from a value the application computed. The XLSX
 * workbook holds its numbers as real numeric cells through {@see self::number()}, which accepts a plain decimal
 * string only and so can never carry user text into a cell that a spreadsheet reads as a number or a formula.
 */
final class SpreadsheetCells
{
    /** The first characters a spreadsheet application may read as the start of a formula or a command. */
    private const array DANGEROUS_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /** The decimal sign when the configuration carries no usable value. */
    private const string DEFAULT_DECIMAL_SEPARATOR = ',';

    /**
     * A text cell that can never be read as a formula: a text whose first character, after any leading spaces, is
     * `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading apostrophe. A missing text is an empty cell.
     */
    public static function text(?string $value): StringCell
    {
        $text = $value ?? '';
        $first = substr(ltrim($text, ' '), 0, 1);

        if (in_array($first, self::DANGEROUS_STARTS, true)) {
            $text = "'".$text;
        }

        return new StringCell($text, null);
    }

    /**
     * A number computed by the application, written as text with the configured decimal sign: `1234.50` becomes
     * `1234,50`. The value is never a float and never user text.
     */
    public static function decimal(string $value): StringCell
    {
        $separator = config('kokpit.exports.csv.decimal_separator', self::DEFAULT_DECIMAL_SEPARATOR);

        return new StringCell(str_replace('.', is_string($separator) && $separator !== '' ? $separator : self::DEFAULT_DECIMAL_SEPARATOR, $value), null);
    }

    /**
     * A whole number, such as the seconds of an entry.
     */
    public static function integer(int $value): Cell
    {
        return new NumericCell($value, null);
    }

    /**
     * A real numeric cell from an exact decimal string the application computed (`1234.50`, `-3.25`, `0.0169`),
     * for the XLSX workbook. The string is checked against the plain decimal shape, so a text such as `=1+1` is
     * refused instead of becoming a cell; the number format of the cell is the style's.
     *
     * @throws InvalidArgumentException when the value is not a plain decimal number
     */
    public static function number(string $exact, ?Style $style = null): NumericCell
    {
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $exact) !== 1) {
            throw new InvalidArgumentException('Not a plain decimal number: '.$exact);
        }

        return new NumericCell((float) $exact, $style);
    }
}
