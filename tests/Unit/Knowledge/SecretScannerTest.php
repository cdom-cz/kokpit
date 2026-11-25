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

it('does not report a sentence that only names the private key header', function (): void {
    $header = '-----BEGIN '.'RSA PRIV'.'ATE KEY-----';

    expect(secretScannerScan('A file that starts with '.$header.' holds the key.'))->toBe([])
        ->and(secretScannerScan($header."\nshort\n".'-----END '.'RSA PRIV'.'ATE KEY-----'))->toBe([])
        ->and(secretScannerScan(''))->toBe([])
        ->and(secretScannerScan("\n\n"))->toBe([]);
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
