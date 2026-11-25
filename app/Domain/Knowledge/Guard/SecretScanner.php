<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Guard;

/**
 * Finds secrets in an article body and reports the rule and the line, never the value (SEC-01, D-03).
 *
 * The text is folded before the rules run (zero-width characters removed, compatibility normalisation),
 * line by line, so a fullwidth or zero-width evasion is still found on the editor's line number. Any
 * regular-expression failure and any invalid UTF-8 fail closed with one blocking scan_failed finding on
 * line 0: a failed scan never reads as clean. Every quantifier is bounded or possessive, so no rule
 * backtracks catastrophically on adversarial input.
 */
final class SecretScanner
{
    private const string LINE_BREAK = '/\r\n|\r|\n/';

    private const string ZERO_WIDTH = '/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u';

    /**
     * A private key block: the header, then at least two base64 lines of 16 or more characters. A prose
     * sentence that only names the header is not a finding.
     */
    private const string PEM_PRIVATE_KEY = '/-----BEGIN (?:[A-Z]{1,20} ){0,5}PRIVATE KEY(?: BLOCK)?-----[ \t]*\n'
        .'(?:[A-Za-z][A-Za-z-]{0,30}: [^\n]{0,200}\n){0,4}\n?'
        .'(?:[A-Za-z0-9+\/=]{16,}+[ \t]*(?:\n|\z)){2,}/';

    /**
     * @return list<SecretFinding> sorted by line, then rule; one finding per rule and line
     */
    public function scan(string $body): array
    {
        try {
            return $this->run($body);
        } catch (\RuntimeException) {
            return [new SecretFinding(SecretRule::ScanFailed, 0)];
        }
    }

    /**
     * @return list<SecretFinding>
     */
    private function run(string $body): array
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            throw new \RuntimeException('The text is not valid UTF-8.');
        }

        $folded = [];

        foreach ($this->checked(preg_split(self::LINE_BREAK, $body)) as $line) {
            $stripped = $this->checked(preg_replace(self::ZERO_WIDTH, '', (string) $line));
            $normalised = \Normalizer::normalize((string) $stripped, \Normalizer::FORM_KC);

            if ($normalised === false) {
                throw new \RuntimeException('The text could not be normalised.');
            }

            $folded[] = $normalised;
        }

        /** @var array<string, SecretFinding> $findings */
        $findings = [];
        $covered = $this->scanPrivateKeys($folded, $findings);

        $findings = array_values($findings);

        usort($findings, static fn (SecretFinding $a, SecretFinding $b): int => [$a->line, $a->rule->value] <=> [$b->line, $b->rule->value]);

        return $findings;
    }

    /**
     * The PEM rule runs over the whole folded text, because a key spans lines. Every line of a match is
     * marked covered, so the base64 lines of a key are never reported again by a line rule.
     *
     * @param  list<string>  $folded
     * @param  array<string, SecretFinding>  $findings
     * @return array<int, true> the 1-based numbers of the covered lines
     */
    private function scanPrivateKeys(array $folded, array &$findings): array
    {
        $text = implode("\n", $folded);
        $covered = [];

        foreach ($this->matches(self::PEM_PRIVATE_KEY, $text) as $match) {
            [$matched, $offset] = $match[0];

            $first = substr_count(substr($text, 0, $offset), "\n") + 1;
            $count = substr_count($matched, "\n") + (str_ends_with($matched, "\n") ? 0 : 1);

            for ($line = $first; $line < $first + $count; $line++) {
                $covered[$line] = true;
            }

            $findings[SecretRule::PemPrivateKey->value.'@'.$first] = new SecretFinding(SecretRule::PemPrivateKey, $first);
        }

        return $covered;
    }

    /**
     * Every match of a pattern with its capture groups and byte offsets. A PCRE failure fails the scan.
     *
     * @return list<array<int, array{0: string, 1: int}>>
     */
    private function matches(string $pattern, string $subject): array
    {
        $result = preg_match_all($pattern, $subject, $sets, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $this->checked($result);

        /** @var list<array<int, array{0: string, 1: int}>> $sets */
        return $sets;
    }

    /**
     * Returns a regular-expression result, or fails the scan when PCRE reported any error.
     *
     * @template T
     *
     * @param  T  $result
     * @return T
     */
    private function checked(mixed $result): mixed
    {
        if ($result === false || $result === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new \RuntimeException('The pattern engine failed.');
        }

        return $result;
    }
}
