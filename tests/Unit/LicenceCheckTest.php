<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** Repository root; Unit tests boot no application, so base_path() is not available here. */
const LICENCE_CHECK_ROOT = __DIR__.'/../..';

/**
 * scripts/check-licenses.php reads `composer licenses --format=json` on stdin and decides per package:
 * pass when at least one declared licence is on the AGPL-compatible allowlist (a dual-licensed package lists
 * its alternatives separately), fail on a package without a licence or with none of its licences allowed.
 * Exit 0 = all allowed, 1 = offenders on stderr, 2 = malformed input.
 *
 * Every package below is synthetic and built at runtime.
 */

/**
 * Run the licence script with the given stdin.
 *
 * @return array{exit: int, stdout: string, stderr: string}
 */
function runLicenceCheck(string $stdin): array
{
    $process = new Process([PHP_BINARY, LICENCE_CHECK_ROOT.'/scripts/check-licenses.php']);
    $process->setInput($stdin);
    $process->setTimeout(60);
    $process->run();

    return [
        'exit' => (int) $process->getExitCode(),
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
}

/**
 * The JSON document `composer licenses --format=json` prints, for synthetic packages.
 *
 * @param  array<string, list<string>>  $packages  package name => declared licences
 */
function licenceReport(array $packages): string
{
    $dependencies = [];
    foreach ($packages as $name => $licences) {
        $dependencies[$name] = ['version' => '1.0.0', 'license' => $licences];
    }

    return json_encode(
        ['name' => 'example/project', 'version' => 'dev-main', 'license' => ['AGPL-3.0-only'], 'dependencies' => $dependencies],
        JSON_THROW_ON_ERROR,
    );
}

it('fails a package that declares only GPL-2.0-only and names it on stderr', function () {
    $result = runLicenceCheck(licenceReport(['example/gpl-two-only' => ['GPL-2.0-only']]));

    expect($result['exit'])->toBe(1)
        ->and($result['stderr'])->toContain('example/gpl-two-only')
        ->and($result['stderr'])->toContain('GPL-2.0-only');
});

it('passes a dual-licensed package when one of its alternatives is allowed', function () {
    $result = runLicenceCheck(licenceReport([
        'example/dual-licensed' => ['BSD-3-Clause', 'GPL-2.0-only', 'GPL-3.0-only'],
    ]));

    expect($result['exit'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});

it('fails a package with an empty licence list as having no licence declared', function () {
    $result = runLicenceCheck(licenceReport(['example/no-licence' => []]));

    expect($result['exit'])->toBe(1)
        ->and($result['stderr'])->toContain('example/no-licence')
        ->and($result['stderr'])->toContain('no licence declared');
});

it('fails a package that has no licence key at all', function () {
    $document = json_encode(['dependencies' => ['example/bare' => ['version' => '1.0.0']]], JSON_THROW_ON_ERROR);

    $result = runLicenceCheck($document);

    expect($result['exit'])->toBe(1)
        ->and($result['stderr'])->toContain('example/bare')
        ->and($result['stderr'])->toContain('no licence declared');
});

it('fails proprietary and unknown licences', function (string $licence) {
    $result = runLicenceCheck(licenceReport(['example/closed' => [$licence]]));

    expect($result['exit'])->toBe(1)
        ->and($result['stderr'])->toContain('example/closed')
        ->and($result['stderr'])->toContain($licence);
})->with(['proprietary', 'Proprietary', 'none', 'GPL-2.0-only', 'LicenseRef-Example-Terms']);

it('passes a plain MIT package with exit 0', function () {
    $result = runLicenceCheck(licenceReport(['example/permissive' => ['MIT']]));

    expect($result['exit'])->toBe(0)
        ->and($result['stderr'])->toBe('');
});

it('passes every licence on the allowlist', function (string $licence) {
    expect(runLicenceCheck(licenceReport(['example/allowed' => [$licence]]))['exit'])->toBe(0);
})->with([
    'MIT', 'MIT-0', 'BSD-2-Clause', 'BSD-3-Clause', '0BSD', 'ISC', 'Apache-2.0', 'Unlicense', 'CC0-1.0', 'MPL-2.0',
    'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later',
    'GPL-3.0-only', 'GPL-3.0-or-later', 'GPL-2.0-or-later', 'AGPL-3.0-only', 'AGPL-3.0-or-later',
]);

it('lists only the offenders when allowed and rejected packages are mixed', function () {
    $result = runLicenceCheck(licenceReport([
        'example/fine' => ['MIT'],
        'example/offender-one' => ['GPL-2.0-only'],
        'example/also-fine' => ['Apache-2.0'],
        'example/offender-two' => [],
    ]));

    expect($result['exit'])->toBe(1)
        ->and($result['stderr'])->toContain('example/offender-one')
        ->and($result['stderr'])->toContain('example/offender-two')
        ->and($result['stderr'])->not->toContain('example/fine')
        ->and($result['stderr'])->not->toContain('example/also-fine');
});

it('exits 2 on input that is not a licence report', function (string $input) {
    $result = runLicenceCheck($input);

    expect($result['exit'])->toBe(2)
        ->and($result['stderr'])->not->toBe('');
})->with([
    'malformed json' => '{"dependencies": ',
    'empty input' => '',
    'no dependencies key' => '{"name": "example/project"}',
    'dependencies is not an object' => '{"dependencies": "none"}',
    'a package entry is not an object' => '{"dependencies": {"example/odd": "MIT"}}',
    'licence is not a list' => '{"dependencies": {"example/odd": {"license": "MIT"}}}',
]);

it('accepts the real composer licenses output for the locked dependencies', function () {
    $composer = new Process(['composer', 'licenses', '--locked', '--format=json', '--no-interaction'], LICENCE_CHECK_ROOT);
    $composer->setTimeout(120);
    $composer->run();

    if (! $composer->isSuccessful()) {
        $this->markTestSkipped('composer is not available to list the locked licences');
    }

    $result = runLicenceCheck($composer->getOutput());

    expect($result['stderr'])->toBe('')
        ->and($result['exit'])->toBe(0);
});
