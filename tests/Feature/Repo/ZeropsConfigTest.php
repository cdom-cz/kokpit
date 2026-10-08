<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Static contract of the Zerops deploy manifest (D-18, FND-15). The file is
 * parsed, never run: nothing here needs a Zerops project or a network.
 */

/**
 * The setups of zerops.yml keyed by their name.
 *
 * @return array<string, array<string, mixed>>
 */
function zeropsSetups(): array
{
    $parsed = Yaml::parseFile(base_path('zerops.yml'));
    $setups = [];

    foreach ((array) ($parsed['zerops'] ?? []) as $setup) {
        if (is_array($setup) && is_string($setup['setup'] ?? null)) {
            $setups[$setup['setup']] = $setup;
        }
    }

    return $setups;
}

/**
 * Every initCommand of every setup as [setup, index, command].
 *
 * @return list<array{string, int, string}>
 */
function zeropsInitCommands(): array
{
    $commands = [];

    foreach (zeropsSetups() as $name => $setup) {
        foreach (array_values((array) ($setup['run']['initCommands'] ?? [])) as $index => $command) {
            $commands[] = [$name, $index, (string) $command];
        }
    }

    return $commands;
}

/**
 * Keys documented in .env.example, active or commented out.
 *
 * @return list<string>
 */
function zeropsDocumentedEnvKeys(): array
{
    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path('.env.example')), $matches);

    return array_values(array_unique($matches[1]));
}

it('describes exactly the three setups app, worker and scheduler', function (): void {
    expect(array_keys(zeropsSetups()))->toBe(['app', 'worker', 'scheduler']);
});

it('builds every setup identically from composer install without development packages', function (): void {
    $setups = zeropsSetups();

    foreach (['worker', 'scheduler'] as $name) {
        expect($setups[$name]['build'])->toEqual($setups['app']['build']);
    }

    $build = $setups['app']['build'];

    expect($build['base'])->toBe('php@8.5')
        ->and(implode(' ', (array) $build['buildCommands']))->toContain('composer install --no-dev');
});

it('runs every setup on php-nginx 8.5 and nothing is extended from another setup', function (): void {
    foreach (zeropsSetups() as $setup) {
        expect($setup['run']['base'])->toBe('php-nginx@8.5')
            ->and($setup)->not->toHaveKey('extends');
    }
});

it('migrates exactly once, in the app setup, through execOnce and before the caches', function (): void {
    $migrations = array_values(array_filter(
        zeropsInitCommands(),
        fn (array $entry): bool => str_contains($entry[2], 'artisan migrate'),
    ));

    expect($migrations)->toHaveCount(1);

    [$setup, $index, $command] = $migrations[0];

    expect($setup)->toBe('app')
        ->and($command)->toStartWith('zsc execOnce ${ZEROPS_appVersionId} --')
        ->and($command)->toContain('php artisan migrate --force')
        ->and($command)->not->toContain('retryUntilSuccessful');

    $cache = array_values(array_filter(
        zeropsInitCommands(),
        fn (array $entry): bool => $entry[0] === 'app' && str_contains($entry[2], 'config:cache'),
    ));

    expect($cache)->toHaveCount(1)
        ->and($index)->toBeLessThan($cache[0][1]);
});

it('builds the framework caches at container start and never in the build', function (): void {
    foreach (zeropsSetups() as $setup) {
        $build = implode("\n", (array) $setup['build']['buildCommands']);

        foreach (['config:cache', 'route:cache', 'view:cache'] as $cache) {
            expect($build)->not->toContain($cache);
        }
    }

    $app = implode("\n", (array) zeropsSetups()['app']['run']['initCommands']);

    foreach (['config:cache', 'route:cache', 'view:cache', 'filament:optimize'] as $cache) {
        expect($app)->toContain($cache);
    }
});

it('deploys every runtime directory and none of the development files', function (): void {
    $runtime = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'storage', 'vendor', 'artisan', 'composer.json'];

    foreach (zeropsSetups() as $setup) {
        $files = array_map(fn (mixed $path): string => rtrim((string) $path, '/'), (array) $setup['build']['deployFiles']);

        foreach ($runtime as $path) {
            if (file_exists(base_path($path))) {
                expect($files)->toContain($path);
            }
        }

        foreach (['tests', 'scripts', '.planning', '.github', '.git', '.env'] as $forbidden) {
            expect($files)->not->toContain($forbidden);
        }
    }
});

it('gates the app readiness on the deploy check and health-checks /up', function (): void {
    $app = zeropsSetups()['app'];

    expect($app['deploy']['readinessCheck']['exec']['command'])->toContain('php artisan kokpit:deploy:verify')
        ->and((string) $app['run']['healthCheck']['httpGet']['path'])->toBe('/up')
        ->and((int) $app['run']['healthCheck']['httpGet']['port'])->toBe(80);
});

it('serves the public directory from the app setup only', function (): void {
    $setups = zeropsSetups();

    expect($setups['app']['run']['documentRoot'])->toBe('public');
});

it('starts the queue worker and the scheduler in their own setups', function (): void {
    $setups = zeropsSetups();

    expect((string) $setups['worker']['run']['start'])->toContain('php artisan queue:work')
        ->and((string) $setups['scheduler']['run']['start'])->toContain('php artisan schedule:work')
        ->and($setups['app']['run'])->not->toHaveKey('start');
});

it('keeps migrations out of the worker and the scheduler', function (): void {
    foreach (['worker', 'scheduler'] as $name) {
        $commands = implode("\n", array_merge(
            (array) (zeropsSetups()[$name]['run']['initCommands'] ?? []),
            [(string) (zeropsSetups()[$name]['run']['start'] ?? '')],
        ));

        expect($commands)->not->toContain('migrate');
    }
});

it('gives every setup the same environment', function (): void {
    $setups = zeropsSetups();

    foreach (['worker', 'scheduler'] as $name) {
        expect($setups[$name]['run']['envVariables'])->toEqual($setups['app']['run']['envVariables']);
    }
});

it('sets the production switches the application guard demands', function (): void {
    $env = zeropsSetups()['app']['run']['envVariables'];

    expect((string) $env['APP_ENV'])->toBe('production')
        ->and((string) $env['APP_DEBUG'])->toBe('false')
        ->and((string) $env['LOG_CHANNEL'])->toBe('syslog')
        ->and((string) $env['QUEUE_CONNECTION'])->toBe('redis')
        ->and((string) $env['CACHE_STORE'])->toBe('redis')
        ->and((string) $env['SESSION_DRIVER'])->toBe('redis')
        ->and((string) $env['FILESYSTEM_DISK'])->toBe('s3')
        ->and((string) $env['AWS_USE_PATH_STYLE_ENDPOINT'])->toBe('true')
        ->and((string) $env['KOKPIT_REQUIRE_ADMIN_2FA'])->toBe('true')
        ->and((string) $env['KOKPIT_CANARY_HARNESS'])->toBe('false');
});

it('holds only references for every secret-like variable', function (): void {
    foreach (zeropsSetups() as $setup) {
        foreach ((array) $setup['run']['envVariables'] as $key => $value) {
            if (preg_match('/(PASSWORD|SECRET|KEY|KEY_ID|TOKEN)$/', (string) $key) === 1) {
                expect((string) $value)->toMatch('/^\$\{[A-Za-z0-9_]+\}$/');
            }
        }
    }
});

it('contains no application key and no key-shaped value', function (): void {
    $raw = (string) file_get_contents(base_path('zerops.yml'));

    expect($raw)->not->toMatch('/^\s*APP_KEY:/m')
        ->and($raw)->not->toContain('base64:');

    foreach (zeropsSetups() as $setup) {
        expect((array) $setup['run']['envVariables'])->not->toHaveKey('APP_KEY');

        foreach ((array) $setup['run']['envVariables'] as $value) {
            expect((string) $value)->not->toStartWith('base64:');
        }
    }
});

it('documents every environment variable of the manifest in .env.example', function (): void {
    $documented = zeropsDocumentedEnvKeys();

    foreach (zeropsSetups() as $setup) {
        foreach (array_keys((array) $setup['run']['envVariables']) as $key) {
            expect($documented)->toContain((string) $key);
        }
    }
});
