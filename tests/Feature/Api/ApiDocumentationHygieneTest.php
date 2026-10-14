<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\ApiDocs;

/*
 * T-07-52, D-17, OD-6b: the public docs reveal nothing of the instance beyond the base URL taken from APP_URL at
 * generation time. Every generated file (the page, the spec and Scribe's intermediate files) is scanned for real
 * hosts, e-mail addresses, token shapes, absolute paths and the values of the instance environment; the published
 * theme assets are proven to be the package's own files. Every probe string is assembled at runtime from fragments, so no line of this file looks like a real
 * value, and the scan is proven able to fail by feeding it polluted copies.
 */

/**
 * The problems one generated file's contents show, one sentence each.
 *
 * @param  list<string>  $allowedHosts  the hosts a URL in the output may name
 * @param  array<string, string>  $secrets  label => value of the instance environment; a value shorter than 4 characters
 *                                          is skipped because it would match ordinary words
 * @return list<string>
 */
function apiDocsHygieneProblems(string $contents, array $allowedHosts, array $secrets): array
{
    $problems = [];

    preg_match_all('#https?://([A-Za-z0-9.-]+)#', $contents, $urls);
    foreach (array_unique($urls[1]) as $host) {
        if (! in_array(strtolower($host), $allowedHosts, true)) {
            $problems[] = "a URL names the host {$host}";
        }
    }

    foreach ([implode('', ['.ddev', '.site']), implode('', ['local', 'host'])] as $needle) {
        if (stripos($contents, $needle) !== false) {
            $problems[] = "the output contains {$needle}";
        }
    }

    // An IPv4 literal. A dotted sequence that is part of a longer dotted number (a version string) is not one.
    if (preg_match('/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/', $contents, $match) === 1) {
        $problems[] = "the output contains the IPv4 literal {$match[0]}";
    }

    // An e-mail address is anything with a local part, an at sign and a dotted domain that is not a file name.
    preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.([A-Za-z]{2,}))/', $contents, $mails, PREG_SET_ORDER);
    foreach ($mails as $mail) {
        $isAsset = in_array(strtolower($mail[2]), ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'css', 'js', 'map'], true);

        if (! $isAsset && strtolower($mail[1]) !== implode('.', ['example', 'com'])) {
            $problems[] = "the output contains the e-mail address {$mail[0]}";
        }
    }

    // A token: a Sanctum plain value (`<id>|<40 characters>`), any run of 40 or more letters and digits, an encoded key.
    if (preg_match('/\b\d+\|[A-Za-z0-9]{20,}/', $contents) === 1) {
        $problems[] = 'the output contains a token of the id|secret shape';
    }
    // A run of 40 or more letters and digits is a token unless it is a hex digest (a Sanctum secret is random over all 62
    // characters, so it is never pure hex) or the base64 of a JSON object (the example pagination cursor).
    preg_match_all('/[A-Za-z0-9]{40,}/', $contents, $runs);
    foreach ($runs[0] as $run) {
        $decoded = base64_decode($run, true);

        if (preg_match('/^[0-9a-f]{40,}$/', $run) === 1 || ($decoded !== false && is_array(json_decode($decoded, true)))) {
            continue;
        }

        $problems[] = 'the output contains a run of 40 or more letters and digits';
    }
    if (str_contains($contents, implode('', ['base', '64:']))) {
        $problems[] = 'the output contains an encoded key prefix';
    }
    if (preg_match('/Bearer\s+(?!\{YOUR_API_TOKEN\})\S/', $contents) === 1) {
        $problems[] = 'the output contains a Bearer value other than the placeholder';
    }

    foreach ([['/var/', 'www'], ['/Users/', ''], ['/home/', '']] as $fragments) {
        $path = implode('', $fragments);
        if (str_contains($contents, $path)) {
            $problems[] = "the output contains the absolute path {$path}";
        }
    }

    foreach ($secrets as $label => $value) {
        if (strlen($value) < 4) {
            continue;
        }

        if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($value, '/').'(?![A-Za-z0-9])/', $contents) === 1) {
            $problems[] = "the output contains the instance value {$label}";
        }
    }

    return $problems;
}

/**
 * The instance values that must never reach the output, each only when configured.
 *
 * @return array<string, string>
 */
function apiDocsInstanceSecrets(): array
{
    $appKey = (string) config('app.key');
    $database = (array) config('database.connections.'.config('database.default'));
    $s3 = (array) config('filesystems.disks.s3');

    $values = [
        'the application key' => $appKey,
        'the application key without its prefix' => str_replace(implode('', ['base', '64:']), '', $appKey),
        'the database host' => (string) ($database['host'] ?? ''),
        'the database password' => (string) ($database['password'] ?? ''),
        'the object storage key' => (string) ($s3['key'] ?? ''),
        'the object storage secret' => (string) ($s3['secret'] ?? ''),
        'the object storage bucket' => (string) ($s3['bucket'] ?? ''),
    ];

    return array_filter($values, fn (string $value): bool => $value !== '');
}

beforeEach(function (): void {
    ApiDocs::ensureGenerated();
});

it('scans every file Scribe generates', function (): void {
    $files = array_map(fn (string $file): string => str_replace(base_path().'/', '', $file), ApiDocs::generatedFiles());

    expect($files)->toContain('resources/views/scribe/index.blade.php')
        ->and($files)->toContain('storage/app/private/scribe/openapi.yaml')
        ->and($files)->toContain('.scribe/intro.md')
        ->and(count(array_filter($files, fn (string $file): bool => str_starts_with($file, 'public/vendor/scribe/'))))->toBeGreaterThan(0)
        ->and(count(array_filter($files, fn (string $file): bool => str_starts_with($file, '.scribe/endpoints'))))->toBeGreaterThan(0);
});

it('allows only the placeholder host and the hosts of the stock theme in a generated URL', function (): void {
    $hosts = ApiDocs::allowedHosts();

    expect($hosts)->toContain(implode('.', ['kokpit', 'example', 'com']))
        ->and($hosts)->toContain('fonts.googleapis.com')
        ->and($hosts)->toContain('cdn.jsdelivr.net')
        ->and($hosts)->toContain('unpkg.com')
        ->and($hosts)->toContain('cdnjs.cloudflare.com')
        ->and($hosts)->not->toContain(implode('', ['local', 'host']));
});

it('keeps every generated file free of real hosts, e-mail addresses, tokens, absolute paths and instance values', function (): void {
    $hosts = ApiDocs::allowedHosts();
    $secrets = apiDocsInstanceSecrets();

    expect($secrets)->toHaveKey('the application key');

    foreach (ApiDocs::generatedFiles() as $file) {
        if (str_starts_with($file, public_path('vendor/scribe').'/')) {
            continue; // third-party theme files, proven byte for byte against the package below
        }

        expect(apiDocsHygieneProblems((string) file_get_contents($file), $hosts, $secrets))
            ->toBe([], 'in '.str_replace(base_path().'/', '', $file));
    }
});

it('publishes the theme assets byte for byte as the package ships them, so no instance data can be in them', function (): void {
    $assets = array_filter(ApiDocs::generatedFiles(), fn (string $file): bool => str_starts_with($file, public_path('vendor/scribe').'/'));

    expect($assets)->not->toBeEmpty();

    foreach ($assets as $asset) {
        // The script is published with the package version in its name (theme-default-5.11.0.js).
        $name = preg_replace('/-\d+\.\d+\.\d+(?=\.js$)/', '', basename($asset));
        $source = base_path('vendor/knuckleswtf/scribe/resources/'.basename(dirname($asset)).'/'.$name);

        expect(is_file($source))->toBeTrue("no package source for {$asset}")
            ->and(hash_file('sha256', $asset))->toBe(hash_file('sha256', $source), "{$asset} differs from the package file");
    }
});

it('is accepted by the repository sensitive-content scanner', function (): void {
    $process = new Process([base_path('scripts/check-sensitive.sh'), '--', ...ApiDocs::generatedFiles()], base_path());
    $process->setTimeout(300);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});

it('reports every kind of leak in a polluted copy (the scan can fail)', function (): void {
    $hosts = ApiDocs::allowedHosts();
    $secret = 'fixture-'.'secret-value';
    $clean = "Base URL https://kokpit.example.com\nAuthorization: Bearer {YOUR_API_TOKEN}\nContact jane@example.com\n";

    expect(apiDocsHygieneProblems($clean, $hosts, ['x' => $secret]))->toBe([]);

    // A hex digest and the base64 of a JSON object are not tokens.
    $digest = str_repeat('a1b2c3d4e5', 4);
    $cursor = base64_encode((string) json_encode(['id' => '0199e3a1-6c8d', 'next' => true, 'padding' => 'abcdefghij']));
    expect(apiDocsHygieneProblems($clean."{$digest}\n{$cursor}\n", $hosts, ['x' => $secret]))->toBe([]);

    $dot = '.';
    $at = '@';
    $polluted = [
        'a real URL host' => ['https://'.implode($dot, ['api', 'shop', 'test']).'/docs', 'names the host'],
        'a developer host' => ['https://'.implode($dot, ['kokpit', 'ddev']).'.site', 'ddev'],
        'a loopback host' => ['http://'.'local'.'host:8000', 'local'.'host'],
        'an IPv4 literal' => ['server '.implode($dot, ['10', '1', '2', '3']), 'IPv4'],
        'an e-mail address' => ['write to '.implode($at, ['jane', implode($dot, ['corp', 'test'])]), 'e-mail'],
        'a Sanctum token' => ['token '.'12|'.str_repeat('aB3', 14), 'id|secret'],
        'a long key-like run' => ['value '.str_repeat('Qw7', 14), '40 or more'],
        'an encoded key' => ['key '.'base'.'64:abc', 'encoded key'],
        'a Bearer value' => ['Authorization: Bearer '.'abc.def', 'Bearer value'],
        'a server path' => ['file '.implode('/', ['', 'var', 'www']).'/html/app.php', 'absolute path'],
        'a user path' => ['file '.'/Users'.'/someone/app.php', 'absolute path'],
        'a home path' => ['file '.'/home'.'/someone/app.php', 'absolute path'],
        'an instance value' => ['value '.$secret, 'instance value'],
    ];

    foreach ($polluted as $label => [$line, $needle]) {
        $problems = apiDocsHygieneProblems($clean.$line."\n", $hosts, ['x' => $secret]);

        expect(str_contains(implode(' | ', $problems), $needle))->toBeTrue("the scan did not report: {$label}");
    }
});

it('skips instance values too short to be told from ordinary words', function (): void {
    expect(apiDocsHygieneProblems('the db is up', ApiDocs::allowedHosts(), ['host' => 'db']))->toBe([]);
});
