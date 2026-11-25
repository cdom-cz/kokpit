<?php

declare(strict_types=1);

use App\Domain\Knowledge\Guard\SecretFinding;
use App\Domain\Knowledge\Guard\SecretRule;
use App\Domain\Knowledge\Guard\SecretScanner;
use App\Domain\Knowledge\Guard\Severity;
use Tests\Support\SecretFixtures;

/*
 * The secret scanner is a pure class: it reads a body and returns rule and line, never the value (D-03).
 * Every secret sample comes from Tests\Support\SecretFixtures, which assembles it at runtime from
 * fragments (D-05); every prose sentence is fictional. Unit tests run without the application.
 */

/**
 * @return list<SecretFinding>
 */
function secretScannerScan(string $body): array
{
    return (new SecretScanner)->scan($body);
}

/**
 * The findings as [rule, line] pairs, for compact comparisons.
 *
 * @param  list<SecretFinding>  $findings
 * @return list<array{string, int}>
 */
function secretScannerPairs(array $findings): array
{
    return array_map(static fn (SecretFinding $finding): array => [$finding->rule->value, $finding->line], $findings);
}

it('reports a fake private key by rule and line, as one blocking finding', function (): void {
    $findings = secretScannerScan(SecretFixtures::bodyWith(SecretFixtures::pemPrivateKey(), 4));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe(SecretRule::PemPrivateKey)
        ->and($findings[0]->line)->toBe(4)
        ->and($findings[0]->severity())->toBe(Severity::Blocking);
});

it('keeps only the rule and the line in a finding, never the matched value', function (): void {
    $secret = SecretFixtures::pemPrivateKey();
    $findings = secretScannerScan(SecretFixtures::bodyWith($secret, 2));

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(SecretFinding::class))->getProperties(),
    );

    expect($properties)->toBe(['rule', 'line']);

    $exported = var_export($findings, true);
    $encoded = json_encode([$findings, array_map(static fn (SecretFinding $finding): array => $finding->toArray(), $findings)]);

    foreach (SecretFixtures::needles($secret) as $needle) {
        expect($exported)->not->toContain($needle)
            ->and($encoded)->not->toContain($needle);
    }

    expect($findings[0]->toArray())->toBe(['rule' => 'pem_private_key', 'line' => 2]);
});

it('reports a very long key as one finding on the line of its header', function (): void {
    $kind = 'OPENSSH '.'PRIV'.'ATE KEY';
    $lines = array_map(
        static fn (int $number): string => base64_encode(random_bytes(48)),
        range(1, 3000),
    );
    $body = "Example heading\n-----BEGIN ".$kind."-----\n".implode("\n", $lines)."\n-----END ".$kind."-----\nExample footer";

    expect(secretScannerPairs(secretScannerScan($body)))->toBe([['pem_private_key', 2]]);
});

it('reports a key whose last line has no line ending and a key with header fields', function (): void {
    $kind = 'RSA '.'PRIV'.'ATE KEY';
    $row = base64_encode(random_bytes(48));

    expect(secretScannerPairs(secretScannerScan('-----BEGIN '.$kind."-----\n".$row."\n".$row)))->toBe([['pem_private_key', 1]])
        ->and(secretScannerPairs(secretScannerScan("Example\n-----BEGIN ".$kind."-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,0123456789\n\n".$row."\n".$row."\n-----END ".$kind.'-----')))
        ->toBe([['pem_private_key', 2]]);
});

it('reports a private key in every shape it takes in a note as one blocking finding on its header line', function (string $text, int $offset): void {
    $findings = secretScannerScan(SecretFixtures::bodyWith($text, 3));

    expect(secretScannerPairs($findings))->toBe([['pem_private_key', 3 + $offset]])
        ->and($findings[0]->severity())->toBe(Severity::Blocking);
})->with(fn (): array => SecretFixtures::pemPrivateKeyShapes());

it('does not report a sentence that only names the private key header', function (): void {
    $header = '-----BEGIN '.'RSA PRIV'.'ATE KEY-----';

    expect(secretScannerScan('A file that starts with '.$header.' holds the key.'))->toBe([])
        ->and(secretScannerScan($header."\nshort\n".'-----END '.'RSA PRIV'.'ATE KEY-----'))->toBe([])
        ->and(secretScannerScan(''))->toBe([])
        ->and(secretScannerScan("\n\n"))->toBe([]);
});

it('does not report an indented, quoted or escaped header without a key body', function (): void {
    $kind = 'RSA '.'PRIV'.'ATE KEY';
    $header = '-----BEGIN '.$kind.'-----';
    $footer = '-----END '.$kind.'-----';

    $texts = [
        'indented header, short line and footer' => '  '.$header."\n  short\n  ".$footer,
        'blockquoted sentence' => '> A file that starts with '.$header.' holds the key.',
        'json value with prose' => '{"note":"'.$header.'\\nsee the vault entry"}',
        'header and an escaped newline only' => '{"note":"'.$header.'\\n"}',
        'quoted header and a short line' => '> '.$header."\n> short\n> ".$footer,
    ];

    foreach ($texts as $text) {
        expect(secretScannerScan($text))->toBe([]);
    }
});

/**
 * The rule keys of the findings, for checks that a rule is absent while another rule may still fire.
 *
 * @param  list<SecretFinding>  $findings
 * @return list<string>
 */
function secretScannerRules(array $findings): array
{
    return array_map(static fn (SecretFinding $finding): string => $finding->rule->value, $findings);
}

it('applies the key body limits: one line of 40 or two lines of 16 characters, header fields and one blank line', function (): void {
    $kind = 'RSA '.'PRIV'.'ATE KEY';
    $header = '-----BEGIN '.$kind.'-----';
    $footer = '-----END '.$kind.'-----';
    $row = SecretFixtures::base64Line(...);
    $scan = static fn (string ...$lines): array => secretScannerScan(SecretFixtures::bodyWith(implode("\n", $lines), 3));
    $block = ['pem_private_key', 3];

    expect(secretScannerPairs($scan($header, $row(40), $footer)))->toBe([$block])
        ->and(secretScannerRules($scan($header, $row(39), $footer)))->not->toContain('pem_private_key')
        ->and(secretScannerPairs($scan($header, $row(16), $row(16), $footer)))->toBe([$block])
        ->and($scan($header, $row(16), $row(15), $footer))->toBe([])
        ->and(secretScannerPairs($scan(
            '# '.$header,
            '# Proc-Type: 4,ENCRYPTED',
            '# DEK-Info: AES-128-CBC,0123456789',
            '#',
            '# '.$row(64),
            '# '.$row(64),
            '# '.$footer,
        )))->toBe([$block])
        ->and(secretScannerPairs($scan($header, $row(20), '', $row(20), $footer)))->toBe([$block])
        ->and($scan($header, $row(20), '', '', $row(20), $footer))->toBe([])
        ->and(secretScannerRules($scan($header, '', '', $row(64), $footer)))->not->toContain('pem_private_key')
        ->and(secretScannerPairs($scan($header, 'A: b', 'C: d', 'E: f', 'G: h', '', $row(64), $footer)))->toBe([$block])
        ->and(secretScannerRules($scan($header, 'A: b', 'C: d', 'E: f', 'G: h', 'I: j', $row(64), $footer)))->not->toContain('pem_private_key');
});

it('does not report a private key header followed by prose, a commented sentence or a short line', function (): void {
    $kind = 'RSA '.'PRIV'.'ATE KEY';
    $header = '-----BEGIN '.$kind.'-----';
    $footer = '-----END '.$kind.'-----';

    $texts = [
        'prose' => $header."\nThe base64 body follows here.\n".$footer,
        'commented sentence' => $header."\n# see the vault entry\n".$footer,
        'short line' => $header."\n".SecretFixtures::base64Line(30)."\n".$footer,
        'quoted sentence' => $header."\n\"see the vault entry\",\n".$footer,
        'list item header and step' => '- '.$header."\n- Example step one\n".$footer,
    ];

    foreach ($texts as $text) {
        expect(secretScannerScan(SecretFixtures::bodyWith($text, 3)))->toBe([]);
    }
});

it('reports a random run longer than the window and a quoted password with spaces as ambiguous findings', function (): void {
    $cases = [
        [SecretFixtures::longRandomRun(336), 'high_entropy_string'],
        [SecretFixtures::longRandomRun(257), 'high_entropy_string'],
        [SecretFixtures::longRandomRun(256), 'high_entropy_string'],
        [SecretFixtures::quotedPasswordAssignment(), 'password_assignment'],
        [SecretFixtures::quotedPasswordAssignment("'"), 'password_assignment'],
    ];

    foreach ($cases as [$text, $rule]) {
        $findings = secretScannerScan(SecretFixtures::bodyWith($text, 3));

        expect(secretScannerPairs($findings))->toBe([[$rule, 3]])
            ->and($findings[0]->severity())->toBe(Severity::Ambiguous);
    }

    $quiet = [
        'hex digest' => bin2hex(random_bytes(200)),
        'single class run' => str_repeat('abcdefghij', 40),
        'quoted angle placeholder' => 'password = "<your password>"',
        'quoted variable placeholder' => 'password: "${DB_PASSWORD}"',
        'quoted changeme' => 'password = "changeme"',
        'quoted short value' => 'password = "abc"',
    ];

    foreach ($quiet as $text) {
        expect(secretScannerScan(SecretFixtures::bodyWith($text, 3)))->toBe([]);
    }
});

it('gives every rule the severity of the D-01 split', function (): void {
    $ambiguous = [SecretRule::PasswordAssignment, SecretRule::HighEntropyString];

    expect(SecretRule::cases())->toHaveCount(10);

    foreach (SecretRule::cases() as $rule) {
        expect($rule->severity())->toBe(in_array($rule, $ambiguous, true) ? Severity::Ambiguous : Severity::Blocking);
    }

    expect(SecretRule::StripeTestKey->severity())->toBe(Severity::Blocking)
        ->and(SecretRule::ScanFailed->severity())->toBe(Severity::Blocking);
});

/**
 * The text with every printable ASCII character replaced by its fullwidth form.
 */
function secretScannerFullwidth(string $text): string
{
    $out = '';

    foreach (str_split($text) as $char) {
        $code = ord($char);
        $out .= $code >= 0x21 && $code <= 0x7E ? mb_chr(0xFF01 + $code - 0x21) : $char;
    }

    return $out;
}

/**
 * The text with a zero-width space between every two characters.
 */
function secretScannerZeroWidth(string $text): string
{
    return implode("\u{200B}", str_split($text));
}

it('finds each blocking fixture on its line with the rule of the D-01 split', function (string $rule, string $fixture): void {
    $findings = secretScannerScan(SecretFixtures::bodyWith(SecretFixtures::$fixture(), 3));

    expect(secretScannerPairs($findings))->toBe([[$rule, 3]])
        ->and($findings[0]->severity())->toBe(Severity::Blocking);
})->with([
    'aws access key' => ['aws_access_key', 'awsAccessKey'],
    'stripe live secret' => ['stripe_secret', 'stripeLiveSecret'],
    'stripe live restricted key' => ['stripe_secret', 'stripeRestrictedLiveKey'],
    'stripe webhook secret' => ['stripe_secret', 'stripeWebhookSecret'],
    'stripe test secret' => ['stripe_test_key', 'stripeTestSecret'],
    'stripe test restricted key' => ['stripe_test_key', 'stripeRestrictedTestKey'],
    'github token' => ['github_token', 'githubToken'],
    'github fine-grained token' => ['github_token', 'githubFineGrainedToken'],
    'jwt' => ['jwt', 'jwt'],
    'url with credentials' => ['url_credentials', 'urlWithCredentials'],
]);

it('finds each ambiguous fixture on its line as an ambiguous finding', function (string $rule, string $fixture): void {
    $findings = secretScannerScan(SecretFixtures::bodyWith(SecretFixtures::$fixture(), 5));

    expect(secretScannerPairs($findings))->toBe([[$rule, 5]])
        ->and($findings[0]->severity())->toBe(Severity::Ambiguous);
})->with([
    'password assignment' => ['password_assignment', 'passwordAssignment'],
    'high entropy string' => ['high_entropy_string', 'highEntropyString'],
]);

it('treats every Stripe test-mode key as blocking, with the label of its own rule', function (): void {
    foreach ([SecretFixtures::stripeTestSecret(), SecretFixtures::stripeRestrictedTestKey()] as $key) {
        $findings = secretScannerScan('Key: '.$key);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->rule)->toBe(SecretRule::StripeTestKey)
            ->and($findings[0]->severity())->toBe(Severity::Blocking);
    }
});

it('does not report placeholders or prose', function (string $text): void {
    expect(secretScannerScan($text))->toBe([]);
})->with([
    'url with a placeholder password' => ['postgres://user:password@localhost/db'],
    'url with a variable password' => ['mysql://app:${DB_PASS}@db.example.com/app'],
    'changeme assignment' => ['password=changeme'],
    'template variable' => ['token: ${API_TOKEN}'],
    'angle bracket placeholder' => ['api_key = <your-key>'],
    'double brace placeholder' => ['password: {{ secret }}'],
    'percent placeholder' => ['password=%PASSWORD%'],
    'masked value' => ['password=********'],
    'your- placeholder' => ['secret: your-secret-here'],
    'prose about a token' => ['The token is stored in the session.'],
]);

it('does not report the AWS documentation key, ids, digests or identifiers', function (): void {
    $texts = [
        'Example key: '.SecretFixtures::awsDocumentationKey(),
        'id '.implode('-', ['123e4567', 'e89b', '12d3', 'a456', '426614174000']),
        'commit '.sha1('example'),
        'digest '.hash('sha256', 'example'),
        'name '.substr(str_repeat('stringlowercaseword', 3), 0, 40),
        'column '.substr(str_repeat('some_snake_case_name_', 3), 0, 40),
    ];

    foreach ($texts as $text) {
        expect(secretScannerScan($text))->toBe([]);
    }
});

it('reports one token only once, with the earlier blocking rule', function (): void {
    $github = secretScannerScan('token: '.SecretFixtures::githubToken());
    $assignment = secretScannerScan('password = '.SecretFixtures::highEntropyString());
    $stripe = secretScannerScan('STRIPE_KEY='.SecretFixtures::stripeLiveSecret());

    expect(secretScannerPairs($github))->toBe([['github_token', 1]])
        ->and(secretScannerPairs($assignment))->toBe([['password_assignment', 1]])
        ->and(secretScannerPairs($stripe))->toBe([['stripe_secret', 1]]);
});

it('finds a fullwidth or zero-width disguised key on the same line', function (): void {
    $key = SecretFixtures::awsAccessKey();

    expect(secretScannerPairs(secretScannerScan(SecretFixtures::bodyWith(secretScannerFullwidth($key), 3))))
        ->toBe([['aws_access_key', 3]])
        ->and(secretScannerPairs(secretScannerScan(SecretFixtures::bodyWith(secretScannerZeroWidth($key), 3))))
        ->toBe([['aws_access_key', 3]]);
});

it('finds a token hidden by Markdown escapes, entities or invisible characters with its blocking rule', function (string $text, string $rule): void {
    $findings = secretScannerScan(SecretFixtures::bodyWith($text, 3));

    expect(secretScannerPairs($findings))->toBe([[$rule, 3]])
        ->and($findings[0]->severity())->toBe(Severity::Blocking);
})->with(fn (): array => SecretFixtures::secretEvasionShapes());

it('keeps editor line numbers and one finding per rule and line in the rendered view', function (): void {
    $stripe = SecretFixtures::stripeLiveSecret();
    $lines = ['Example heading', '&#10;&#10;', 'Example prose line.', SecretFixtures::markdownEscaped($stripe), 'Example footer'];

    expect(secretScannerPairs(secretScannerScan(implode("\n", $lines))))->toBe([['stripe_secret', 4]])
        ->and(secretScannerPairs(secretScannerScan($stripe.' and '.SecretFixtures::markdownEscaped($stripe))))->toBe([['stripe_secret', 1]])
        ->and(secretScannerScan('Use \\*example\\* for emphasis, Tom &amp; Jerry&nbsp;meet here.'))->toBe([]);
});

it('keeps the editor line numbers with every kind of line ending', function (): void {
    $body = "one\r\ntwo\rthree\n".SecretFixtures::awsAccessKey()."\r\nfive";

    expect(secretScannerPairs(secretScannerScan($body)))->toBe([['aws_access_key', 4]]);
});

it('fails closed on invalid UTF-8 with one blocking finding on line 0', function (): void {
    $findings = secretScannerScan("Example text \xC3\x28 end");

    expect(secretScannerPairs($findings))->toBe([['scan_failed', 0]])
        ->and($findings[0]->severity())->toBe(Severity::Blocking);
});

it('fails closed when the pattern engine fails', function (): void {
    $jit = (string) ini_get('pcre.jit');
    $limit = (string) ini_get('pcre.backtrack_limit');

    try {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        $findings = secretScannerScan(SecretFixtures::bodyWith(SecretFixtures::urlWithCredentials(), 3));
    } finally {
        ini_set('pcre.jit', $jit);
        ini_set('pcre.backtrack_limit', $limit);
    }

    expect(secretScannerPairs($findings))->toBe([['scan_failed', 0]]);
});

it('sorts the findings by line, then rule, and reports a repeated token once per rule and line', function (): void {
    $github = SecretFixtures::githubToken();
    $body = implode("\n", [
        'Example heading',
        $github.' and '.$github,
        'Example prose line.',
        SecretFixtures::stripeLiveSecret().' '.SecretFixtures::awsAccessKey(),
        SecretFixtures::awsAccessKey(),
    ]);

    expect(secretScannerPairs(secretScannerScan($body)))->toBe([
        ['github_token', 2],
        ['aws_access_key', 4],
        ['stripe_secret', 4],
        ['aws_access_key', 5],
    ]);
});

it('finishes on 200 KB of adversarial input in under a second per rule, without failing', function (string $unit): void {
    $body = substr(str_repeat($unit, intdiv(200_000, strlen($unit)) + 1), 0, 200_000);

    $start = hrtime(true);
    $findings = secretScannerScan($body);
    $seconds = (hrtime(true) - $start) / 1e9;

    expect($seconds)->toBeLessThan(1.0)
        ->and(secretScannerPairs($findings))->not->toContain(['scan_failed', 0]);
})->with([
    'repeated A' => ['A'],
    'repeated jwt head' => ['eyJabcdefgh.'],
    'repeated scheme' => ['a://'],
    'repeated pem header' => ["-----BEGIN PRIVATE KEY-----\n"],
    'repeated password' => ['password='],
    'repeated colon pairs' => ['a:b:'],
    'repeated aws prefix' => ['AKIA'],
    'repeated stripe prefix' => ['sk_live_'],
    'repeated github prefix' => ['ghp_'],
    'repeated run characters' => ['aB3+/=_-'],
    'repeated spaces after a keyword' => ['password'.'                                        '],
    'repeated url user' => ['a://a:'],
    'repeated at signs' => ['a://b:c@'],
    'repeated indented quoted pem header' => ['  > -----BEGIN PRIV'."ATE KEY-----\n"],
    'repeated escaped pem header' => ['-----BEGIN PRIV'.'ATE KEY-----\\n'],
    'repeated prefix-only lines' => ["  >  > \n"],
    'repeated commented pem header' => ['# -----BEGIN PRIV'."ATE KEY-----\n"],
    'repeated header with blank and field lines' => ['-----BEGIN PRIV'."ATE KEY-----\n#\nA: b\n#\nA: b\n"],
    'repeated header and decorated short line' => ['-----BEGIN PRIV'."ATE KEY-----\n\"".str_repeat('aB3+', 5)."\",\n"],
    'repeated single-line key' => ['-----BEGIN PRIV'."ATE KEY-----\n".str_repeat('aB3+', 16)."\n"],
    'repeated decoration characters' => ['"#,\\'],
    'repeated high entropy run' => [SecretFixtures::highEntropyString()],
    'repeated open quoted password' => ['password = "a b '],
    'repeated backslash underscore' => ['\\_'],
    'repeated decimal entity' => ['&#95;'],
    'repeated named entity' => ['&lowbar;'],
    'repeated entity line break' => ['&#10;'],
    'repeated soft hyphen' => ["\u{00AD}"],
    'repeated start of heading' => ["\u{0001}"],
    'repeated escaped stripe prefix' => ['sk\\_live\\_'],
]);
