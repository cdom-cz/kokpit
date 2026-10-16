<?php

declare(strict_types=1);

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Models\Document;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * How a stored file is handed to the browser (D-06, D-10). An uploaded file is untrusted content: HTML and
 * SVG would run script on the application origin if the browser rendered them. Only the five types below
 * are ever shown inline, and only when the caller asked for a preview; everything else is an attachment.
 */
final class DocumentDisposition
{
    /** The only stored types that may be rendered by the browser. */
    public const array INLINE_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    /** The type of everything that is not shown inline, so a browser never sniffs it into markup. */
    public const string DOWNLOAD_MIME_TYPE = 'application/octet-stream';

    /**
     * Whether the stored type of the document is on the inline allowlist. The type is the one detected
     * from the bytes at upload time, never the one the client declared.
     */
    public static function isInline(Document $document): bool
    {
        return in_array($document->mime_type, self::INLINE_MIME_TYPES, true);
    }

    /**
     * The name the browser saves the file under: the document name, plus `.<extension>` when the name does
     * not already end with it (a renamed document keeps its type).
     */
    public static function downloadName(Document $document): string
    {
        $name = $document->name;
        $extension = $document->extension;

        if (! is_string($extension) || $extension === '') {
            return $name;
        }

        $suffix = '.'.$extension;

        return str_ends_with(mb_strtolower($name), $suffix) ? $name : $name.$suffix;
    }

    /**
     * The Content-Disposition header value (RFC 6266): `filename` is a plain ASCII fallback, `filename*` the
     * UTF-8 name. The fallback never contains a quote, a backslash, a percent sign or a control character.
     */
    public static function header(string $disposition, string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\%]/', '', Str::ascii($name)) ?? '';
        $fallback = trim($fallback);

        if ($fallback === '' || $fallback === '.') {
            $fallback = 'file';
        }

        return HeaderUtils::makeDisposition($disposition, $name, $fallback);
    }
}
