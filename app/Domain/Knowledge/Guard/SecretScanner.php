<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Guard;

/**
 * Finds secrets in an article body and reports the rule and the line, never the value (SEC-01, D-03).
 *
 * The text is folded before the rules run (zero-width characters removed, compatibility normalisation),
 * line by line, so a fullwidth or zero-width evasion is still found on the editor's line number. Any
 * regular-expression failure and any invalid UTF-8 fail closed with one blocking scan_failed finding on
 * line 0: a failed scan never reads as clean. Every quantifier is bounded or possessive and every
 * left boundary is a look-behind, so no rule backtracks catastrophically on adversarial input.
 *
 * Rules run in precedence order, every blocking rule before the two ambiguous ones. A match that overlaps
 * an earlier reported match on the same line is dropped, so one token never yields two findings. The
 * private key rule runs first over the whole text and covers the lines of the key.
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
        .'(?:[A-Za-z0-9+\/=]{16,}+[ \t]*(?:\n|\z)){2}/';

    /**
     * The characters of a base64 line. The lines of a key after the two the pattern proves are covered by
     * a plain loop, because a repeated group over thousands of lines would exhaust the PCRE stack.
     */
    private const string BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';

    /**
     * Access key ids of the AWS shape: a four-character prefix and sixteen base32 characters.
     */
    private const string AWS_ACCESS_KEY = '/(?<![A-Za-z0-9])(?:A3T[A-Z0-9]|AKIA|ASIA|ABIA|ACCA)[A-Z2-7]{16}(?![A-Za-z0-9])/';

    /**
     * The one exemption: the published AWS documentation key ends in this suffix, so it is no finding.
     */
    private const string AWS_DOCUMENTATION_SUFFIX = 'EXAMPLE';

    /**
     * Live-mode secret and restricted keys and webhook signing secrets.
     */
    private const string STRIPE_SECRET = '/(?<![A-Za-z0-9])(?:[sr]k_live_|whsec_)[A-Za-z0-9]{24,99}+/';

    /**
     * Test-mode secret and restricted keys. They block like live keys (owner ruling R-18-04-1), but have
     * their own rule so the label tells the author it is a test key.
     */
    private const string STRIPE_TEST_KEY = '/(?<![A-Za-z0-9])[sr]k_test_[A-Za-z0-9]{24,99}+/';

    private const string GITHUB_TOKEN = '/(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9]{36,255}+|github_pat_[A-Za-z0-9_]{22,255}+)/';

    /**
     * Header and payload both start with the base64 of an opening brace and a quote.
     */
    private const string JWT = '/(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{5,}+\.eyJ[A-Za-z0-9_-]{5,}+\.[A-Za-z0-9_-]{8,}+/';

    /**
     * A scheme, a user, a password and a host. Group 1 is the password, checked against the placeholders.
     */
    private const string URL_CREDENTIALS = '/(?<![A-Za-z0-9+.-])[A-Za-z][A-Za-z0-9+.-]{0,30}+:\/\/[^\s:@\/]{1,128}+:([^\s@\/]{1,256}+)@[A-Za-z0-9\[]/';

    /**
     * A secret-like name, a separator and a value. Group 1 is the value, checked against the placeholders.
     */
    private const string PASSWORD_ASSIGNMENT = '/(?<![A-Za-z0-9])(?:password|passwd|pwd|secret|token|api[ _-]?key|auth(?:orization)?)'
        .'["\']?[ \t]{0,20}[:=][ \t]{0,20}["\']?(?:(?:bearer|basic)[ \t]{1,20})?([^\s"\']{6,128})/i';

    /**
     * A maximal run of characters that tokens are made of. The length and the shape are checked in code.
     */
    private const string RUN = '/[A-Za-z0-9+\/=_-]{32,}+/';

    private const int RUN_MAX_LENGTH = 256;

    private const float RUN_MIN_ENTROPY = 4.2;

    /**
     * A whole value that stands for a secret instead of being one.
     */
    private const string PLACEHOLDER = '/\A(?:password|passwd|pass|secret|changeme|change-me|example|user|redacted|placeholder|dummy|null|none|true|false'
        .'|\*+|x+|<[^>]*>|\$\{[^}]*\}|\$[A-Za-z_][A-Za-z0-9_]*|\{\{[^}]*\}\}|%[^%]*%|your[-_].*)\z/is';

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

        foreach ($folded as $index => $line) {
            if (! isset($covered[$index + 1])) {
                $this->scanLine($line, $index + 1, $findings);
            }
        }

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

            $last = $first + $count - 1;

            while (isset($folded[$last]) && $this->isBase64Line($folded[$last])) {
                $last++;
            }

            for ($line = $first; $line <= $last; $line++) {
                $covered[$line] = true;
            }

            $findings[SecretRule::PemPrivateKey->value.'@'.$first] = new SecretFinding(SecretRule::PemPrivateKey, $first);
        }

        return $covered;
    }

    /**
     * The single-line rules in precedence order. A match that overlaps an earlier reported one is dropped.
     *
     * @param  array<string, SecretFinding>  $findings
     */
    private function scanLine(string $line, int $number, array &$findings): void
    {
        if (strlen($line) < 6) {
            return;
        }

        /** @var list<array{int, int}> $taken disjoint spans of the earlier rules, sorted by start */
        $taken = [];

        foreach ($this->candidateGroups($line) as [$rule, $candidates]) {
            $added = [];

            foreach ($candidates as [$start, $end]) {
                if ($this->overlaps($taken, $start, $end)) {
                    continue;
                }

                $added[] = [$start, $end];
                $findings[$rule->value.'@'.$number] = new SecretFinding($rule, $number);
            }

            if ($added !== []) {
                $taken = [...$taken, ...$added];
                usort($taken, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            }
        }
    }

    /**
     * The candidate spans (start and end byte offsets) of one rule at a time, in precedence order: every
     * blocking rule before the two ambiguous ones. The spans of one rule never overlap each other.
     *
     * @return \Generator<int, array{SecretRule, list<array{int, int}>}>
     */
    private function candidateGroups(string $line): \Generator
    {
        $aws = [];

        foreach ($this->matches(self::AWS_ACCESS_KEY, $line) as $match) {
            if (! str_ends_with($match[0][0], self::AWS_DOCUMENTATION_SUFFIX)) {
                $aws[] = $this->span($match);
            }
        }

        yield [SecretRule::AwsAccessKey, $aws];

        foreach ([
            [SecretRule::StripeSecret, self::STRIPE_SECRET],
            [SecretRule::StripeTestKey, self::STRIPE_TEST_KEY],
            [SecretRule::GithubToken, self::GITHUB_TOKEN],
            [SecretRule::Jwt, self::JWT],
        ] as [$rule, $pattern]) {
            yield [$rule, array_map($this->span(...), $this->matches($pattern, $line))];
        }

        foreach ([
            [SecretRule::UrlCredentials, self::URL_CREDENTIALS],
            [SecretRule::PasswordAssignment, self::PASSWORD_ASSIGNMENT],
        ] as [$rule, $pattern]) {
            $spans = [];

            foreach ($this->matches($pattern, $line) as $match) {
                if (! $this->isPlaceholder($match[1][0])) {
                    $spans[] = $this->span($match);
                }
            }

            yield [$rule, $spans];
        }

        $runs = [];

        foreach ($this->matches(self::RUN, $line) as $match) {
            if ($this->isRandomRun($match[0][0])) {
                $runs[] = $this->span($match);
            }
        }

        yield [SecretRule::HighEntropyString, $runs];
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $match
     * @return array{int, int}
     */
    private function span(array $match): array
    {
        return [$match[0][1], $match[0][1] + strlen($match[0][0])];
    }

    /**
     * Whether a span overlaps one of the disjoint spans sorted by start (binary search for the first span
     * that ends after the start).
     *
     * @param  list<array{int, int}>  $taken
     */
    private function overlaps(array $taken, int $start, int $end): bool
    {
        $low = 0;
        $high = count($taken);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($taken[$middle][1] > $start) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low < count($taken) && $taken[$low][0] < $end;
    }

    private function isBase64Line(string $line): bool
    {
        $line = rtrim($line, " \t");

        return $line !== '' && strspn($line, self::BASE64) === strlen($line);
    }

    private function isPlaceholder(string $value): bool
    {
        return $this->checked(preg_match(self::PLACEHOLDER, $value)) === 1;
    }

    /**
     * A run of 32 to 256 characters that is no hex digest or UUID, mixes lower case, upper case and digits
     * and has the entropy of a random token. Words, identifiers and digests do not reach all three.
     */
    private function isRandomRun(string $run): bool
    {
        $length = strlen($run);

        if ($length < 32 || $length > self::RUN_MAX_LENGTH || ctype_xdigit(str_replace('-', '', $run))) {
            return false;
        }

        $mixed = strcspn($run, 'abcdefghijklmnopqrstuvwxyz') < $length
            && strcspn($run, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') < $length
            && strcspn($run, '0123456789') < $length;

        return $mixed && $this->entropy($run) >= self::RUN_MIN_ENTROPY;
    }

    /**
     * Shannon entropy in bits per character.
     */
    private function entropy(string $text): float
    {
        $length = strlen($text);
        $entropy = 0.0;

        foreach (count_chars($text, 1) as $count) {
            $probability = $count / $length;
            $entropy -= $probability * log($probability, 2);
        }

        return $entropy;
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
     * @param  T|false|null  $result
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
