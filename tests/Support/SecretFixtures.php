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
     * Every line of the text with the prefix put in front of it.
     */
    public static function prefixedLines(string $text, string $prefix): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $prefix.$line,
            explode("\n", $text),
        ));
    }

    /**
     * A fictional service-account style object on one line, whose private key is a JSON string with
     * literal escaped line breaks. The encoder produces both spellings, nothing is typed.
     */
    public static function pemPrivateKeyJsonEscaped(bool $escapeSlashes = false): string
    {
        return (string) json_encode(
            ['type' => 'service_account', 'private_key' => self::pemPrivateKey()."\n"],
            $escapeSlashes ? 0 : JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * The shapes a private key takes inside a note: shape name to the text and the 0-based offset of the
     * header line inside that text. Every key is a fresh runtime-assembled fake (D-05).
     *
     * @return array<string, array{string, int}>
     */
    public static function pemPrivateKeyShapes(): array
    {
        $fence = '   '.str_repeat('`', 3);
        $lines = explode("\n", self::pemPrivateKey());
        $quoted = 'tls_key: "'.$lines[0].'\\n'."\n"
            .'  '.$lines[1].'\\n'."\n"
            .'  '.$lines[2].'\\n'."\n"
            .'  '.$lines[3].'"';

        return [
            'plain' => [self::pemPrivateKey(), 0],
            'yaml block scalar' => ["tls_key: |\n".self::prefixedLines(self::pemPrivateKey(), '  '), 1],
            'blockquote' => [self::prefixedLines(self::pemPrivateKey(), '> '), 0],
            'nested blockquote' => [self::prefixedLines(self::pemPrivateKey(), '> > '), 0],
            'list item fence' => ["- Example step\n".$fence."\n".self::prefixedLines(self::pemPrivateKey(), '   ')."\n".$fence, 2],
            'tab indent' => [self::prefixedLines(self::pemPrivateKey(), "\t"), 0],
            'json escaped' => [self::pemPrivateKeyJsonEscaped(), 0],
            'json escaped slashes' => [self::pemPrivateKeyJsonEscaped(true), 0],
            'yaml double quoted' => [$quoted, 0],
            'markdown escaped header' => ['\\'.self::pemPrivateKey(), 0],
        ];
    }

    /**
     * The secret with every underscore preceded by a backslash, as rich-text-to-Markdown converters write it.
     */
    public static function markdownEscaped(string $secret): string
    {
        return str_replace('_', '\\_', $secret);
    }

    /**
     * The secret with every underscore replaced by an HTML entity.
     */
    public static function entityEncoded(string $secret, string $entity = '&#95;'): string
    {
        return str_replace('_', $entity, $secret);
    }

    /**
     * The secret with the insert placed at the byte offset.
     */
    public static function withInserted(string $secret, string $insert, int $offset = 4): string
    {
        return substr($secret, 0, $offset).$insert.substr($secret, $offset);
    }

    /**
     * Tokens a reader still sees intact in the rendered article but the raw text hides: shape name to the
     * text and the rule key it must be found with. Invisible characters are written as code point escapes.
     *
     * @return array<string, array{string, string}>
     */
    public static function secretEvasionShapes(): array
    {
        $aws = self::awsAccessKey();
        $shapes = [
            'stripe live secret, escaped underscores' => [self::markdownEscaped(self::stripeLiveSecret()), 'stripe_secret'],
            'github token, escaped underscores' => [self::markdownEscaped(self::githubToken()), 'github_token'],
            'fine-grained github token, escaped underscores' => [self::markdownEscaped(self::githubFineGrainedToken()), 'github_token'],
            'stripe live secret, decimal entity' => [self::entityEncoded(self::stripeLiveSecret()), 'stripe_secret'],
            'stripe live secret, hex entity' => [self::entityEncoded(self::stripeLiveSecret(), '&#x5F;'), 'stripe_secret'],
            'stripe live secret, named entity' => [self::entityEncoded(self::stripeLiveSecret(), '&lowbar;'), 'stripe_secret'],
            'aws access key, soft hyphen entity' => [self::withInserted($aws, '&shy;'), 'aws_access_key'],
        ];

        foreach ([
            'soft hyphen' => "\u{00AD}",
            'combining grapheme joiner' => "\u{034F}",
            'arabic letter mark' => "\u{061C}",
            'left-to-right mark' => "\u{200E}",
            'right-to-left override' => "\u{202E}",
            'invisible times' => "\u{2062}",
            'left-to-right isolate' => "\u{2066}",
            'variation selector' => "\u{FE0F}",
            'tag character' => "\u{E0041}",
        ] as $name => $invisible) {
            $shapes['aws access key, '.$name] = [self::withInserted($aws, $invisible), 'aws_access_key'];
        }

        return $shapes;
    }

    /**
     * One random base64 run on a single line, the first characters of the encoding of fresh random bytes
     * (a new value on every call).
     */
    public static function longRandomRun(int $length = 336): string
    {
        return substr(base64_encode(random_bytes((int) ceil($length * 3 / 4) + 3)), 0, $length);
    }

    /**
     * A password assignment whose quoted value holds several short words (the unquoted form stops at the
     * first short word and finds nothing).
     */
    public static function quotedPasswordAssignment(string $quote = '"'): string
    {
        return 'db password = '.$quote.implode(' ', ['Zq9X', 'bT1m', 'Rw7L', 'cVd5']).$quote;
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
