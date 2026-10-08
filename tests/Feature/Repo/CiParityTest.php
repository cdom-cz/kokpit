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
 * The AWS_* values of .env.example, which the tests job boots from.
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
        ->and($service['RUSTFS_ACCESS_KEY'])->toBe($env['AWS_ACCESS_KEY_ID'])
        ->and($service['RUSTFS_SECRET_KEY'] ?? null)->toBe($env['AWS_SECRET_ACCESS_KEY'])
        ->and($ddev['RUSTFS_ACCESS_KEY'] ?? null)->toBe($env['AWS_ACCESS_KEY_ID'])
        ->and($ddev['RUSTFS_SECRET_KEY'] ?? null)->toBe($env['AWS_SECRET_ACCESS_KEY'])
        ->and($job['env']['AWS_ENDPOINT'] ?? null)->toBe('http://127.0.0.1:9000')
        ->and($env['AWS_USE_PATH_STYLE_ENDPOINT'])->toBe('true');
});

it('keeps the aggregator needs list unchanged because RustFS lives inside the tests job', function () {
    expect(ciJobs()['ci-passed']['needs'] ?? null)
        ->toBe(['scan', 'workflow-lint', 'tests', 'static-analysis', 'dependencies']);
});
