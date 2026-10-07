<?php

declare(strict_types=1);

/**
 * .env.example must stay in sync with the config files, in both directions.
 *
 * Reverse (strict): every documented key, active or commented, is read by an
 * env() call in some config file or sits in the allowlist below.
 * Forward (narrow): every env() call without a default has a documented key;
 * keys read with a default may stay undocumented.
 */

/**
 * Documented keys that no config file reads, with the reason.
 *
 * @var array<string, string>
 */
const ENV_EXAMPLE_ALLOWLIST = [
    'KOKPIT_ADMIN_PASSWORD' => 'read only by the kokpit:install command, never by a config file',
];

/**
 * Keys documented in an env file, active or commented out.
 *
 * @return list<string>
 */
function envExampleKeys(string $contents): array
{
    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', $contents, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Active (uncommented) key/value pairs of an env file.
 *
 * @return array<string, string>
 */
function envExampleValues(string $contents): array
{
    preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', $contents, $matches, PREG_SET_ORDER);

    $values = [];
    foreach ($matches as $match) {
        $values[$match[1]] = trim($match[2]);
    }

    return $values;
}

/**
 * Keys read through env() in config sources, mapped to whether a default is given.
 * Comments are stripped first so a commented-out call does not count.
 *
 * @param  list<string>  $configSources  PHP source of each config file
 * @return array<string, bool>
 */
function configEnvReads(array $configSources): array
{
    $reads = [];

    foreach ($configSources as $source) {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        preg_match_all("/\benv\(\s*'([A-Z][A-Z0-9_]*)'\s*(,|\))/", $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $hasDefault = $match[2] === ',';
            $reads[$match[1]] = ($reads[$match[1]] ?? false) || $hasDefault;
        }
    }

    return $reads;
}

/**
 * Reverse check: documented keys that nothing reads.
 *
 * @param  list<string>  $configSources
 * @param  array<string, string>  $allowlist
 * @return list<string>
 */
function envKeysNotReadByConfig(string $envContents, array $configSources, array $allowlist): array
{
    $reads = configEnvReads($configSources);

    return array_values(array_filter(
        envExampleKeys($envContents),
        fn (string $key): bool => ! array_key_exists($key, $reads) && ! array_key_exists($key, $allowlist),
    ));
}

/**
 * Forward check: env() keys read without a default that are not documented.
 *
 * @param  list<string>  $configSources
 * @return list<string>
 */
function envKeysMissingFromExample(string $envContents, array $configSources): array
{
    $documented = envExampleKeys($envContents);

    $noDefault = array_keys(array_filter(
        configEnvReads($configSources),
        fn (bool $hasDefault): bool => ! $hasDefault,
    ));

    return array_values(array_diff($noDefault, $documented));
}

/**
 * @return list<string>
 */
function realConfigSources(): array
{
    $files = glob(base_path('config/*.php')) ?: [];

    return array_map(fn (string $file): string => (string) file_get_contents($file), $files);
}

function realEnvExample(): string
{
    return (string) file_get_contents(base_path('.env.example'));
}

it('reads every documented key somewhere in the config or the allowlist', function () {
    expect(envKeysNotReadByConfig(realEnvExample(), realConfigSources(), ENV_EXAMPLE_ALLOWLIST))->toBe([]);
});

it('documents every env() key that is read without a default', function () {
    expect(envKeysMissingFromExample(realEnvExample(), realConfigSources()))->toBe([]);
});

it('carries the DDEV-ready values the application relies on', function () {
    $values = envExampleValues(realEnvExample());

    expect($values)->toMatchArray([
        'DB_CONNECTION' => 'pgsql',
        'CACHE_STORE' => 'redis',
        'SESSION_DRIVER' => 'redis',
        'QUEUE_CONNECTION' => 'redis',
        'APP_LOCALE' => 'cs',
        'KOKPIT_REQUIRE_ADMIN_2FA' => 'true',
        'KOKPIT_CANARY_HARNESS' => 'false',
        'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
    ]);

    expect($values['MAIL_FROM_ADDRESS'])->toEndWith('@example.com"');
});

it('keeps the install-only admin password commented out', function () {
    expect(realEnvExample())->toMatch('/^# KOKPIT_ADMIN_PASSWORD=$/m')
        ->and(envExampleValues(realEnvExample()))->not->toHaveKey('KOKPIT_ADMIN_PASSWORD');
});

it('reports a documented key that no config file reads', function () {
    $mutated = realEnvExample()."\nKOKPIT_UNREAD_PROBE=1\n";

    expect(envKeysNotReadByConfig($mutated, realConfigSources(), ENV_EXAMPLE_ALLOWLIST))
        ->toBe(['KOKPIT_UNREAD_PROBE']);
});

it('reports a no-default env() key that was removed from the example', function () {
    // Precondition: the real example documents the key, so the removal below is what makes it missing.
    expect(envExampleKeys(realEnvExample()))->toContain('DB_URL');

    $mutated = (string) preg_replace('/^#?\s*DB_URL=.*\R?/m', '', realEnvExample());

    expect(envKeysMissingFromExample($mutated, realConfigSources()))->toContain('DB_URL');
});

it('does not count a commented-out env() call as a read', function () {
    $sources = ["<?php\nreturn [\n    // 'x' => env('KOKPIT_COMMENTED_PROBE'),\n    'y' => env('KOKPIT_LIVE_PROBE', 'a'),\n];\n"];

    expect(configEnvReads($sources))->toBe(['KOKPIT_LIVE_PROBE' => true]);
});

it('flips the kokpit switches for the test suite in phpunit.xml', function () {
    expect(config('kokpit.require_admin_two_factor'))->toBeFalse()
        ->and(config('kokpit.canary_harness'))->toBeTrue();
});
