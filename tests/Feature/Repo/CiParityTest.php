<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Static contract between the versioned DDEV project and the Hygiene workflow.
 * Nothing here needs Docker or a network: both files are parsed, never run.
 */

/**
 * @return array<string, mixed>
 */
function ciParseYaml(string $relativePath): array
{
    $parsed = Yaml::parseFile(base_path($relativePath));

    return is_array($parsed) ? $parsed : [];
}

/**
 * The jobs of the Hygiene workflow, keyed by job id.
 *
 * @return array<string, array<string, mixed>>
 */
function ciJobs(): array
{
    $workflow = ciParseYaml('.github/workflows/hygiene.yml');
    $jobs = $workflow['jobs'] ?? [];

    return is_array($jobs) ? $jobs : [];
}

/**
 * The first step of a job that uses the given action (any ref), or an empty array.
 *
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function ciStepUsing(array $job, string $action): array
{
    foreach ((array) ($job['steps'] ?? []) as $step) {
        if (is_array($step) && str_starts_with((string) ($step['uses'] ?? ''), $action.'@')) {
            return $step;
        }
    }

    return [];
}

it('runs the tests job on the PHP version of the DDEV project', function () {
    $ddev = ciParseYaml('.ddev/config.yaml');
    $setup = ciStepUsing(ciJobs()['tests'] ?? [], 'shivammathur/setup-php');

    expect($setup)->not->toBe([])
        ->and((string) $ddev['php_version'])->not->toBe('')
        ->and((string) ($setup['with']['php-version'] ?? ''))->toBe((string) $ddev['php_version']);
});

it('runs the tests job on the PostgreSQL major version of the DDEV project', function () {
    $ddev = ciParseYaml('.ddev/config.yaml');
    $image = (string) (ciJobs()['tests']['services']['postgres']['image'] ?? '');

    expect($ddev['database']['type'])->toBe('postgres')
        ->and($image)->toMatch('/^postgres:\d+$/')
        ->and(substr($image, strlen('postgres:')))->toBe((string) $ddev['database']['version']);
});

it('points the tests job at a throwaway test database that its service creates', function () {
    $job = ciJobs()['tests'] ?? [];
    $service = $job['services']['postgres']['env'] ?? [];

    expect((string) ($job['env']['DB_DATABASE'] ?? ''))->toEndWith('_test')
        ->and($job['env']['DB_DATABASE'])->toBe($service['POSTGRES_DB'])
        ->and($job['env']['DB_USERNAME'])->toBe($service['POSTGRES_USER'])
        ->and($job['env']['DB_PASSWORD'])->toBe($service['POSTGRES_PASSWORD'])
        ->and($job['env']['DB_CONNECTION'])->toBe('pgsql');
});

it('boots the tests job from .env.example before Pest runs', function () {
    $commands = [];
    foreach ((array) (ciJobs()['tests']['steps'] ?? []) as $step) {
        if (is_array($step) && isset($step['run'])) {
            $commands[] = (string) $step['run'];
        }
    }

    $boot = null;
    $pest = null;
    foreach ($commands as $index => $command) {
        $boot ??= str_contains($command, 'scripts/boot-from-env-example.sh') ? $index : null;
        $pest ??= str_contains($command, 'vendor/bin/pest') ? $index : null;
    }

    expect($boot)->not->toBeNull()
        ->and($pest)->not->toBeNull()
        ->and($boot)->toBeLessThan($pest);
});

it('keeps the boot script executable and free of printed secrets', function () {
    $path = base_path('scripts/boot-from-env-example.sh');
    $source = (string) file_get_contents($path);

    expect(is_executable($path))->toBeTrue()
        ->and($source)->toContain('kokpit:install')
        ->and($source)->not->toMatch('/\b(echo|printf)\b[^\n]*KOKPIT_ADMIN_PASSWORD/');
});

it('gives every job a pinned runner, least-privilege token and SHA-pinned actions', function () {
    $jobs = ciJobs();

    expect($jobs)->not->toBeEmpty();

    foreach ($jobs as $id => $job) {
        expect($job['runs-on'] ?? null)->toBe('ubuntu-24.04', "job {$id} runner");

        if ($id === 'ci-passed') {
            expect($job['permissions'] ?? null)->toBe([], 'ci-passed permissions');
        } else {
            expect($job['permissions'] ?? null)->toBe(['contents' => 'read'], "job {$id} permissions");
        }

        foreach ((array) ($job['steps'] ?? []) as $step) {
            $uses = (string) ($step['uses'] ?? '');

            if ($uses === '') {
                continue;
            }

            expect($uses)->toMatch('/@[0-9a-f]{40}$/', "job {$id} pins {$uses}");

            if (str_starts_with($uses, 'actions/checkout@')) {
                expect($step['with']['persist-credentials'] ?? null)->toBeFalse("job {$id} checkout credentials");
            }
        }
    }
});

/**
 * The S3_* values of .env.example, which the tests job boots from.
 *
 * @return array<string, string>
 */
function ciEnvExample(): array
{
    $parsed = Dotenv\Dotenv::parse((string) file_get_contents(base_path('.env.example')));

    return array_map(static fn (?string $value): string => (string) $value, $parsed);
}

it('runs a RustFS service in the tests job on the image of the DDEV project', function () {
    $ddev = ciParseYaml('.ddev/docker-compose.rustfs.yaml');
    $ddevImage = (string) ($ddev['services']['rustfs']['image'] ?? '');
    $service = ciJobs()['tests']['services']['rustfs'] ?? [];

    expect($ddevImage)->toMatch('~^rustfs/rustfs:\d+\.\d+\.\d+$~')
        ->and((string) ($service['image'] ?? ''))->toBe($ddevImage)
        ->and($service['ports'] ?? [])->toContain('9000:9000')
        ->and((string) ($service['options'] ?? ''))->toContain('curl --fail http://localhost:9000/health')
        ->and((string) ($service['env']['RUSTFS_ADDRESS'] ?? ''))->toBe((string) ($ddev['services']['rustfs']['environment']['RUSTFS_ADDRESS'] ?? 'missing'))
        ->and((string) ($service['env']['RUSTFS_VOLUMES'] ?? ''))->toBe((string) ($ddev['services']['rustfs']['environment']['RUSTFS_VOLUMES'] ?? 'missing'));
});

it('gives the RustFS service the key pair of .env.example and points the job at it', function () {
    $env = ciEnvExample();
    $job = ciJobs()['tests'] ?? [];
    $service = $job['services']['rustfs']['env'] ?? [];
    $ddev = ciParseYaml('.ddev/docker-compose.rustfs.yaml')['services']['rustfs']['environment'] ?? [];

    expect((string) ($service['RUSTFS_ACCESS_KEY'] ?? ''))->not->toBe('')
        ->and($service['RUSTFS_ACCESS_KEY'])->toBe($env['S3_ACCESS_KEY_ID'])
        ->and($service['RUSTFS_SECRET_KEY'] ?? null)->toBe($env['S3_SECRET_ACCESS_KEY'])
        ->and($ddev['RUSTFS_ACCESS_KEY'] ?? null)->toBe($env['S3_ACCESS_KEY_ID'])
        ->and($ddev['RUSTFS_SECRET_KEY'] ?? null)->toBe($env['S3_SECRET_ACCESS_KEY'])
        ->and($job['env']['S3_ENDPOINT'] ?? null)->toBe('http://127.0.0.1:9000')
        ->and($env['S3_USE_PATH_STYLE_ENDPOINT'])->toBe('true');
});

it('keeps the aggregator needs list unchanged because RustFS lives inside the tests job', function () {
    expect(ciJobs()['ci-passed']['needs'] ?? null)
        ->toBe(['scan', 'workflow-lint', 'tests', 'static-analysis', 'dependencies']);
});

/**
 * The Pest steps of the tests job that run a shard, in workflow order: step name, step index and the parsed command.
 *
 * @return list<array{name: string, index: int, run: string, suites: list<string>, total: int|null}>
 */
function ciShardedPestSteps(): array
{
    $found = [];
    foreach ((array) (ciJobs()['tests']['steps'] ?? []) as $index => $step) {
        $run = is_array($step) ? (string) ($step['run'] ?? '') : '';

        if (! str_starts_with($run, 'vendor/bin/pest') || ! str_contains($run, '--shard')) {
            continue;
        }

        $matched = preg_match('~^vendor/bin/pest --testsuite=([A-Za-z,]+) --shard="\$\{PEST_SHARD\}/(\d+)"$~', $run, $parts) === 1;

        $found[] = [
            'name' => (string) $step['name'],
            'index' => (int) $index,
            'run' => $run,
            'suites' => $matched ? explode(',', $parts[1]) : [],
            'total' => $matched ? (int) $parts[2] : null,
        ];
    }

    return $found;
}

/**
 * The names of the test suites of phpunit.xml, in file order.
 *
 * @return list<string>
 */
function ciPhpunitSuites(): array
{
    $xml = simplexml_load_file(base_path('phpunit.xml'));
    $names = [];

    foreach ($xml->testsuites->testsuite ?? [] as $suite) {
        $names[] = (string) $suite['name'];
    }

    return $names;
}

it('runs every suite except Audit in the sharded Pest steps, each exactly once, from the cheapest to the most expensive', function () {
    $steps = ciShardedPestSteps();
    $suites = array_merge(...array_column($steps, 'suites'));

    // Each step is a single sharded command of the exact shape the matrix feeds through PEST_SHARD.
    foreach ($steps as $step) {
        expect($step['suites'])->not->toBe([], "step {$step['name']} must be: vendor/bin/pest --testsuite=<names> --shard=\"\${PEST_SHARD}/<total>\"")
            ->and($step['total'])->toBe($steps[0]['total'], "step {$step['name']} shard total");
    }

    // Together the steps are the unsharded suite: every phpunit.xml suite but Audit, none twice, none left out.
    $expected = array_values(array_diff(ciPhpunitSuites(), ['Audit']));
    $actual = $suites;
    sort($expected);
    sort($actual);

    expect($steps)->not->toBe([])
        ->and($actual)->toBe($expected)
        ->and(count($suites))->toBe(count(array_unique($suites)));

    // Cheap first, so a cheap failure surfaces early: Unit and Arch, then Feature, then Isolation and Concurrency.
    $rank = ['Unit' => 0, 'Arch' => 0, 'Feature' => 1, 'Isolation' => 2, 'Concurrency' => 2];
    $ranks = array_map(static fn (string $suite): int => $rank[$suite] ?? PHP_INT_MAX, $suites);
    $sorted = $ranks;
    sort($sorted);

    expect($ranks)->toBe($sorted, 'the sharded Pest steps must run Unit and Arch before Feature before Isolation and Concurrency')
        ->and(max($ranks))->not->toBe(PHP_INT_MAX, 'a suite without a cost rank in this test was added to phpunit.xml');
});

it('keeps the shard matrix equal to the shard total of the Pest steps, so no part of the suite is left unrun', function () {
    $job = ciJobs()['tests'] ?? [];
    $total = ciShardedPestSteps()[0]['total'] ?? null;

    expect($total)->toBeInt()->toBeGreaterThan(1)
        ->and($job['strategy']['fail-fast'] ?? null)->toBeFalse()
        ->and($job['strategy']['matrix']['shard'] ?? null)->toBe(range(1, (int) $total))
        ->and($job['env']['PEST_SHARD'] ?? null)->toBe('${{ matrix.shard }}')
        ->and((string) ($job['name'] ?? ''))->toContain('shard ${{ matrix.shard }}/'.$total);
});

it('keeps the recorded class times that balance the shards by time instead of by class count', function () {
    $path = base_path('tests/.pest/shards.json');
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    expect($data)->toBeArray()
        ->and($data['timings'] ?? null)->toBeArray()->not->toBeEmpty()
        ->and(array_key_exists('Tests\\Feature\\Repo\\CiParityTest', (array) $data['timings']))->toBeTrue();
});

it('runs the Partner audit exactly once, in the first shard and after the sharded steps, and keeps the composer scripts unsharded', function () {
    $names = [];
    $audits = [];
    foreach ((array) (ciJobs()['tests']['steps'] ?? []) as $index => $step) {
        if (is_array($step) && isset($step['name'])) {
            $names[(string) $step['name']] = $index;
        }

        if (is_array($step) && str_contains((string) ($step['run'] ?? ''), '--testsuite=Audit')) {
            $audits[] = $step;
        }
    }

    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $scripts = (array) ($composer['scripts'] ?? []);
    $lastShardedStep = max(array_column(ciShardedPestSteps(), 'index'));

    expect($audits)->toHaveCount(1)
        ->and($audits[0]['name'] ?? null)->toBe('Partner audit')
        ->and($audits[0]['run'] ?? null)->toBe($scripts['partner-audit'] ?? null)
        ->and($audits[0]['if'] ?? null)->toBe('matrix.shard == 1')
        ->and($names['Partner audit'] ?? -1)->toBeGreaterThan($names['Boot the application from .env.example alone'] ?? PHP_INT_MAX)
        ->and($names['Partner audit'] ?? -1)->toBeGreaterThan($lastShardedStep)
        ->and($scripts['test'] ?? null)->toBe('vendor/bin/pest --exclude-testsuite=Audit')
        ->and($scripts['partner-audit'] ?? null)->toBe('vendor/bin/pest --testsuite=Audit')
        ->and($scripts['ci'] ?? null)->toBe(['@test', '@partner-audit', '@lint', '@stan', '@check-licenses']);
});

it('starts the test shards only after the cheap jobs have passed', function () {
    expect(ciJobs()['tests']['needs'] ?? null)
        ->toEqualCanonicalizing(['scan', 'workflow-lint', 'static-analysis', 'dependencies']);
});
