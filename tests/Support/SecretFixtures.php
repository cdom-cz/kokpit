<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Fake secrets for the knowledge secret guard tests, assembled at runtime from short fragments, repeated
 * blocks, base64 and random bytes, so no line of the repository looks like a real value (D-05, public
 * repository, fictional data only). Every host is example.com. Later tests that need a secret sample use
 * only this class.
 */
final class SecretFixtures
{
    /**
     * A PEM private key: header, two base64 lines of 64 characters, footer (four lines).
     */
    public static function pemPrivateKey(): string
    {
        $kind = 'RSA '.'PRIV'.'ATE KEY';

        return '-----BEGIN '.$kind."-----\n"
            .chunk_split(base64_encode(random_bytes(96)), 64, "\n")
            .'-----END '.$kind.'-----';
    }

    /**
     * An access key id of the AWS shape (not the documentation example).
     */
    public static function awsAccessKey(): string
    {
        return 'AK'.'IA'.'ABCDEFGH'.'IJKLMNOP';
    }

    /**
     * The widely published AWS documentation example key, which ends in EXAMPLE.
     */
    public static function awsDocumentationKey(): string
    {
        return 'AK'.'IA'.'IOSFOD'.'NN7'.'EXAM'.'PLE';
    }

    public static function stripeLiveSecret(): string
    {
        return 'sk_'.'live_'.str_repeat('a1B2', 7);
    }

    public static function stripeRestrictedLiveKey(): string
    {
        return 'rk_'.'live_'.str_repeat('c3D4', 7);
    }

    public static function stripeWebhookSecret(): string
    {
        return 'whsec'.'_'.str_repeat('e5F6', 8);
    }

    public static function stripeTestSecret(): string
    {
        return 'sk_'.'test_'.str_repeat('g7H8', 7);
    }

    public static function stripeRestrictedTestKey(): string
    {
        return 'rk_'.'test_'.str_repeat('j9K1', 7);
    }

    public static function githubToken(): string
    {
        return 'gh'.'p_'.str_repeat('aB3', 12);
    }

    public static function githubFineGrainedToken(): string
    {
        return 'github'.'_pat_'.str_repeat('Qw8_', 7);
    }

    /**
     * A JSON web token shape: two base64url parts that start with the base64 of an opening brace and a quote.
     */
    public static function jwt(): string
    {
        $part = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return implode('.', [$part('{"alg":"HS256"}'), $part('{"sub":"1"}'), 'abcdefgh'.'12345']);
    }

    public static function urlWithCredentials(): string
    {
        return 'postgres://app:'.'Xk29'.'sLq7@db.example.com/app';
    }

    public static function passwordAssignment(): string
    {
        return 'db password = '.'Tr0ub4dor'.'x9';
    }

    /**
     * Thirty-two distinct characters of three classes, so its entropy is five bits per character.
     */
    public static function highEntropyString(): string
    {
        return implode('', ['Zq9X', 'bT1m', 'Rw7L', 'cVd5', 'Nf2H', 'sJgP', 'y8Ae', '4UoQ']);
    }

    /**
     * A fictional multi-line article body with the secret on the given 1-based line. A multi-line secret
     * starts on that line; every other line is plain prose.
     */
    public static function bodyWith(string $secret, int $line): string
    {
        $lines = [];

        for ($number = 1; $number <= max($line + 3, 8); $number++) {
            $lines[] = $number === $line ? $secret : 'Example note number '.$number.' about the setup.';
        }

        return implode("\n", $lines);
    }

    /**
     * Three distinctive 12-character slices of the secret (taken from its longest line), for checks that
     * the value never reaches a finding, a message, an exception or a log.
     *
     * @return list<string>
     */
    public static function needles(string $secret): array
    {
        $longest = '';

        foreach (explode("\n", $secret) as $line) {
            if (strlen($line) > strlen($longest)) {
                $longest = $line;
            }
        }

        $last = strlen($longest) - 12;

        if ($last < 0) {
            throw new \InvalidArgumentException('The secret is too short to slice.');
        }

        return [substr($longest, 0, 12), substr($longest, intdiv($last, 2), 12), substr($longest, $last, 12)];
    }
}
