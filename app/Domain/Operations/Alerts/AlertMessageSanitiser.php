<?php

declare(strict_types=1);

namespace App\Domain\Operations\Alerts;

/**
 * Neutralises the parts of an exception message that must not leave the
 * infrastructure in an alert (mail, bell row): hosts, IP addresses, ports,
 * URLs and DSN fragments, e-mail addresses, file paths, quoted values and the
 * SQL, detail and binding tails of database errors.
 *
 * This is a best-effort filter, not a guarantee: it errs on the side of
 * removing too much. The full, unfiltered message stays in failed_jobs and the log.
 */
final class AlertMessageSanitiser
{
    public const string REDACTED = '[…]';

    /**
     * Characters of a line that are looked at; the rest is dropped before the patterns run (bounds the work).
     */
    public const int MAX_INPUT_LENGTH = 2000;

    /**
     * The first line of the message, sanitised and cut to $maxLength characters.
     */
    public static function firstLine(string $message, int $maxLength): string
    {
        $lines = preg_split('/\R/u', $message);
        $first = is_array($lines) ? ($lines[0] ?? '') : '';

        return mb_substr(self::sanitise($first), 0, max(0, $maxLength));
    }

    public static function sanitise(string $line): string
    {
        // The host-name pattern backtracks quadratically on a very long run of hyphenated tokens, so the
        // line is cut before any pattern runs. Redaction only shortens text, so the part dropped here can
        // never have reached the (much shorter) alert output.
        $truncated = mb_strlen($line) > self::MAX_INPUT_LENGTH;

        if ($truncated) {
            $line = mb_substr($line, 0, self::MAX_INPUT_LENGTH);
        }

        $redacted = preg_quote(self::REDACTED, '/');

        // Everything after a SQL, detail, binding or connection marker: the driver puts the statement and values there.
        $line = self::replace('/\(?\b(?:SQL|DETAIL|CONTEXT|Bindings?|Connection)\s*:.*$/isu', self::REDACTED, $line);

        $patterns = [
            // URLs and DSNs with a scheme (mysql://user:pass@host/db).
            '/[A-Za-z][A-Za-z0-9+.\-]*:\/\/\S+/u',
            // E-mail addresses.
            '/[^\s@"\'<>()]+@[^\s@"\'<>()]+/u',
            // Quoted values: host names, constraint names, key values, statements.
            '/"[^"]*"|\'[^\']*\'|`[^`]*`/u',
            // Key (column)=(value) from unique violations.
            '/\bKey\s*\([^)]*\)\s*=\s*\([^)]*\)/iu',
            // DSN and connection-string fragments: host=x, password=x, dbname=x.
            '/\b[A-Za-z_][A-Za-z0-9_]*\s*=\s*[^\s;,)]+/u',
            // IPv4 with an optional port.
            '/\b\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?\b/u',
            // IPv6 (full, compressed or bracketed).
            '/\[?(?<![\w:])(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}(?![\w:])\]?/u',
            // Host names with at least one dot and an optional port, and localhost.
            '/\b(?:[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}(?::\d+)?\b|\blocalhost(?::\d+)?\b/iu',
            // "port 5432", "port: 5432".
            '/\bport\s*:?\s*\d+/iu',
            // Absolute file paths.
            '/(?:\/[\w.\-@]+){2,}\/?/u',
        ];

        foreach ($patterns as $pattern) {
            $line = self::replace($pattern, self::REDACTED, $line);
        }

        // A quote left over after the quoted-value pass has lost its partner. On a cut line the partner may
        // have been in the dropped part, so everything from that quote on is treated as quoted as well.
        if ($truncated) {
            $line = self::replace('/["\'`].*$/su', self::REDACTED, $line);
        }

        // Collapse runs of redactions left by neighbouring matches.
        $line = self::replace('/(?:'.$redacted.'[\s,;:()]*){2,}/u', self::REDACTED.' ', $line);

        return trim($line);
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
