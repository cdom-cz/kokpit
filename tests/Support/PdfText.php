<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Reads back what a generated PDF says (plan 10-15, research Open Question 10, ruling R-15-5). The page count is a
 * structural fact that needs no tool; the text comes from Ghostscript's `txtwrite` device and is `null` when `gs`
 * is not installed, so a test that needs the text skips with a reason instead of failing.
 */
final class PdfText
{
    /**
     * The text of all pages as UTF-8, or null when Ghostscript is not installed or could not read the PDF.
     */
    public static function extract(string $pdf): ?string
    {
        $binary = (new ExecutableFinder)->find('gs');

        if ($binary === null) {
            return null;
        }

        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $file = tempnam($directory, 'pdf-');

        if ($file === false) {
            return null;
        }

        try {
            file_put_contents($file, $pdf);

            $result = Process::run([$binary, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=txtwrite', '-dTextFormat=3', '-sOutputFile=-', $file]);

            return $result->successful() ? $result->output() : null;
        } finally {
            @unlink($file);
        }
    }

    /**
     * The number of page objects in the PDF bytes.
     */
    public static function pageCount(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page\b#', $pdf);
    }

    /**
     * The text of the document with every run of white space (spaces, line breaks, no-break spaces) reduced to one
     * space, so an assertion does not depend on how Ghostscript breaks the lines. Null as {@see PdfText::extract()}.
     */
    public static function flat(string $pdf): ?string
    {
        $text = self::extract($pdf);

        return $text === null ? null : trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
