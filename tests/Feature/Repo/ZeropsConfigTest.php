<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Static contract of the Zerops deploy manifest (D-18, FND-15). The file is parsed, never run: nothing here needs a
 * Zerops project or a network. Every check is a function over the parsed manifest (and, where needed, the raw text or a
 * file reader) that returns a list of problems, so the self-check at the end can feed mutated in-memory copies and
 * prove each check really reports a weakening. The manifest itself is owned by the maintainer and is never edited by
 * the tests.
 */

/**
 * @return array<string|int, mixed>
 */
function zeropsManifest(): array
{
    $parsed = Yaml::parseFile(base_path('zerops.yml'));

    return is_array($parsed) ? $parsed : [];
}

function zeropsRaw(): string
{
    return (string) file_get_contents(base_path('zerops.yml'));
}

/**
 * The reader the site check uses: the contents of a repository file, or null when it is missing.
 */
function zeropsReadFile(string $relative): ?string
{
    $path = base_path($relative);

    return is_file($path) ? (string) file_get_contents($path) : null;
}

/**
 * The setups of the manifest keyed by their name.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return array<string, array<string, mixed>>
 */
function zeropsSetups(array $manifest): array
{
    $setups = [];

    foreach ((array) ($manifest['zerops'] ?? []) as $setup) {
        if (is_array($setup) && is_string($setup['setup'] ?? null)) {
            $setups[$setup['setup']] = $setup;
        }
    }

    return $setups;
}

/**
 * A value at a dot path, or null when any step is missing.
 *
 * @param  array<string|int, mixed>  $data
 */
function zeropsGet(array $data, string $path): mixed
{
    $value = $data;

    foreach (explode('.', $path) as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            return null;
        }

        $value = $value[$segment];
    }

    return $value;
}

/**
 * A missing list is empty and a scalar is a one-element list.
 *
 * @return list<string>
 */
function zeropsList(mixed $value): array
{
    if ($value === null) {
        return [];
    }

    return array_values(array_map('strval', is_array($value) ? $value : [$value]));
}

/**
 * Every command string of a setup grouped by where it runs.
 *
 * @param  array<string, mixed>  $setup
 * @return array<string, list<string>>
 */
function zeropsCommandGroups(array $setup): array
{
    $crontab = array_values(array_filter((array) zeropsGet($setup, 'run.crontab'), 'is_array'));

    return [
        'build.prepareCommands' => zeropsList(zeropsGet($setup, 'build.prepareCommands')),
        'build.buildCommands' => zeropsList(zeropsGet($setup, 'build.buildCommands')),
        'run.prepareCommands' => zeropsList(zeropsGet($setup, 'run.prepareCommands')),
        'run.initCommands' => zeropsList(zeropsGet($setup, 'run.initCommands')),
        'run.start' => zeropsList(zeropsGet($setup, 'run.start')),
        'run.crontab' => array_map(fn (array $entry): string => (string) ($entry['command'] ?? ''), $crontab),
    ];
}

/**
 * The manifest has exactly one setup, named backend.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsSetupProblems(array $manifest): array
{
    $names = array_keys(zeropsSetups($manifest));

    return $names === ['backend'] ? [] : ['the setups are ['.implode(', ', $names).'], expected exactly backend'];
}

/**
 * PHP 8.5 is fixed by the project brief: the build image and the runtime image must both carry it.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsBaseProblems(array $manifest): array
{
    $problems = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        $build = zeropsList(zeropsGet($setup, 'build.base'));

        if (array_filter($build, fn (string $base): bool => preg_match('#(?:^|/)php@8\.5$#', $base) === 1) === []) {
            $problems[] = "setup {$name} build.base has no php@8.5 entry";
        }

        if (preg_match('#(?:^|/)php-nginx@8\.5$#', (string) zeropsGet($setup, 'run.base')) !== 1) {
            $problems[] = "setup {$name} run.base is not php-nginx@8.5";
        }
    }

    return $problems;
}

/**
 * The build installs production dependencies only and builds no framework cache: a cache built in the build would
 * bake the build-time environment into the config cache, and the build has no database.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsBuildProblems(array $manifest): array
{
    $problems = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        $groups = zeropsCommandGroups($setup);
        $install = array_filter(
            $groups['build.buildCommands'],
            fn (string $command): bool => str_starts_with($command, 'composer install') && in_array('--no-dev', explode(' ', $command), true),
        );

        if ($install === []) {
            $problems[] = "setup {$name} has no build command that starts with composer install and carries --no-dev";
        }

        foreach (array_merge($groups['build.prepareCommands'], $groups['build.buildCommands']) as $command) {
            foreach (['artisan migrate', 'artisan optimize', 'config:cache', 'route:cache', 'view:cache', 'event:cache', 'filament:optimize', 'icons:cache'] as $forbidden) {
                if (str_contains($command, $forbidden)) {
                    $problems[] = "setup {$name} runs {$forbidden} in the build: {$command}";
                }
            }
        }
    }

    return $problems;
}

/**
 * The migration runs once per deploy: one command in the whole manifest, in run.initCommands, through zsc execOnce
 * keyed on the app version id. Both spellings of the id are accepted on purpose: which name Zerops expands in
 * initCommands is an open rehearsal item.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsMigrationProblems(array $manifest): array
{
    $found = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        foreach (zeropsCommandGroups($setup) as $group => $commands) {
            foreach ($commands as $command) {
                if (str_contains($command, 'artisan migrate')) {
                    $found[] = [$name, $group, $command];
                }
            }
        }
    }

    if (count($found) !== 1) {
        return ['expected exactly one artisan migrate command in the manifest, found '.count($found)];
    }

    [$name, $group, $command] = $found[0];
    $problems = [];

    if ($group !== 'run.initCommands') {
        $problems[] = "the migration of setup {$name} is in {$group}, expected run.initCommands";
    }

    if (preg_match('/zsc execOnce \$\{(?:ZEROPS_)?appVersionId\} -- php artisan migrate --force$/', $command) !== 1) {
        $problems[] = "the migration is not 'zsc execOnce \${appVersionId} -- php artisan migrate --force': {$command}";
    }

    foreach (['retryUntilSuccessful', '--seed', 'migrate:fresh'] as $forbidden) {
        if (str_contains($command, $forbidden)) {
            $problems[] = "the migration command contains {$forbidden}";
        }
    }

    return $problems;
}

/**
 * The framework caches are built at container start, where the real environment exists.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsCacheProblems(array $manifest): array
{
    $problems = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        $init = zeropsCommandGroups($setup)['run.initCommands'];

        foreach (['php artisan optimize', 'php artisan filament:optimize'] as $expected) {
            if (array_filter($init, fn (string $command): bool => preg_match('/\b'.preg_quote($expected, '/').'$/', $command) === 1) === []) {
                $problems[] = "setup {$name} run.initCommands has no command ending in {$expected}";
            }
        }
    }

    return $problems;
}

/**
 * Every environment value is set in the Zerops UI only: the file may hold references, never values.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsEnvironmentProblems(array $manifest): array
{
    $problems = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        foreach (['build.envVariables', 'run.envVariables'] as $path) {
            $variables = zeropsGet($setup, $path);

            if ($variables === null) {
                continue;
            }

            foreach ((array) $variables as $key => $value) {
                if (! is_scalar($value) || preg_match('/^\$\{[A-Za-z][A-Za-z0-9_]*\}$/', (string) $value) !== 1) {
                    $problems[] = "setup {$name} {$path}.{$key} is not exactly one \${...} reference";
                }

                if ($key === 'APP_KEY') {
                    $problems[] = "setup {$name} {$path} sets APP_KEY";
                }
            }
        }
    }

    return $problems;
}

/**
 * No key material anywhere in the raw file, comments included.
 *
 * @return list<string>
 */
function zeropsRawProblems(string $raw): array
{
    $problems = [];

    foreach (['APP_KEY', 'base64:', '-----BEGIN'] as $needle) {
        if (str_contains($raw, $needle)) {
            $problems[] = "the manifest contains {$needle}";
        }
    }

    if (preg_match('/[A-Za-z0-9+\/=_-]{32,}/', $raw) === 1) {
        $problems[] = 'the manifest contains a key-shaped token (32 or more key characters in a row)';
    }

    return $problems;
}

/**
 * The site config points to an existing file whose document root is the public directory.
 *
 * @param  array<string|int, mixed>  $manifest
 * @param  callable(string): ?string  $reader
 * @return list<string>
 */
function zeropsSiteProblems(array $manifest, callable $reader): array
{
    $problems = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        $path = zeropsGet($setup, 'run.siteConfigPath');

        if (! is_string($path) || $path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            $problems[] = "setup {$name} run.siteConfigPath is not a relative path inside the repository";

            continue;
        }

        $contents = $reader($path);

        if ($contents === null) {
            $problems[] = "setup {$name} run.siteConfigPath {$path} does not exist";
        } elseif (preg_match('/^\s*root\s+\/var\/www\/public\s*;/m', $contents) !== 1) {
            $problems[] = "{$path} does not have the line 'root /var/www/public;'";
        }
    }

    return $problems;
}

/**
 * The scheduler runs from the crontab every minute.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsCrontabProblems(array $manifest): array
{
    $problems = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        $entries = array_filter((array) zeropsGet($setup, 'run.crontab'), 'is_array');
        $schedule = array_filter(
            $entries,
            fn (array $entry): bool => preg_match('/php artisan schedule:run$/', (string) ($entry['command'] ?? '')) === 1
                && ($entry['timing'] ?? null) === '* * * * *',
        );

        if ($schedule === []) {
            $problems[] = "setup {$name} has no crontab entry that runs php artisan schedule:run every minute";
        }
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $manifest
 * @param  callable(string): ?string  $reader
 * @return array<string, list<string>>
 */
function zeropsAllProblems(array $manifest, string $raw, callable $reader): array
{
    return [
        'setups' => zeropsSetupProblems($manifest),
        'bases' => zeropsBaseProblems($manifest),
        'build' => zeropsBuildProblems($manifest),
        'migration' => zeropsMigrationProblems($manifest),
        'caches' => zeropsCacheProblems($manifest),
        'environment' => zeropsEnvironmentProblems($manifest),
        'raw' => zeropsRawProblems($raw),
        'site' => zeropsSiteProblems($manifest, $reader),
        'crontab' => zeropsCrontabProblems($manifest),
    ];
}

it('describes exactly one setup, named backend', function (): void {
    expect(zeropsSetupProblems(zeropsManifest()))->toBe([]);
});

it('builds and runs on PHP 8.5', function (): void {
    expect(zeropsBaseProblems(zeropsManifest()))->toBe([]);
});

it('installs production dependencies in the build and builds no framework cache there', function (): void {
    expect(zeropsBuildProblems(zeropsManifest()))->toBe([]);
});

it('migrates exactly once, in the init commands, through execOnce keyed on the app version', function (): void {
    expect(zeropsMigrationProblems(zeropsManifest()))->toBe([]);
});

it('builds the framework caches at container start', function (): void {
    expect(zeropsCacheProblems(zeropsManifest()))->toBe([]);
});

it('holds only references in every environment map', function (): void {
    expect(zeropsEnvironmentProblems(zeropsManifest()))->toBe([]);
});

it('contains no application key and no key-shaped value', function (): void {
    expect(zeropsRawProblems(zeropsRaw()))->toBe([]);
});

it('points the site config at an existing file that serves the public directory', function (): void {
    expect(zeropsSiteProblems(zeropsManifest(), zeropsReadFile(...)))->toBe([]);
});

it('runs the scheduler from the crontab every minute', function (): void {
    expect(zeropsCrontabProblems(zeropsManifest()))->toBe([]);
});

/*
 * Self-check: each mutation weakens one property of an in-memory copy and must be reported by the check that guards
 * it, otherwise that check would pass vacuously. Key-shaped and secret-shaped values are assembled at runtime from
 * fragments so no line of this file looks like a key.
 */
it('reports every weakening of the manifest', function (): void {
    $reader = zeropsReadFile(...);
    $manifest = zeropsManifest();
    $raw = zeropsRaw();

    expect(zeropsAllProblems($manifest, $raw, $reader))->each->toBe([]);

    $appKey = 'APP_'.'KEY';
    $base64 = 'base'.'64:';
    $migrate = 'php artisan migrate --force';

    $withoutMigration = function (array $m): array {
        $m['zerops'][0]['run']['initCommands'] = array_values(array_filter(
            $m['zerops'][0]['run']['initCommands'],
            fn (string $command): bool => ! str_contains($command, 'artisan migrate'),
        ));

        return $m;
    };

    $replaceInit = function (array $m, string $needle, ?string $replacement): array {
        $commands = [];

        foreach ($m['zerops'][0]['run']['initCommands'] as $command) {
            if (str_contains($command, $needle)) {
                if ($replacement !== null) {
                    $commands[] = $replacement;
                }

                continue;
            }

            $commands[] = $command;
        }

        $m['zerops'][0]['run']['initCommands'] = $commands;

        return $m;
    };

    $moveToBuild = function (array $m, string $needle) use ($replaceInit): array {
        $command = (string) current(array_filter($m['zerops'][0]['run']['initCommands'], fn (string $c): bool => str_contains($c, $needle)));
        $m = $replaceInit($m, $needle, null);
        $m['zerops'][0]['build']['buildCommands'][] = $command;

        return $m;
    };

    // Each mutation is [category, manifest mutator, raw mutator, reader override].
    $none = fn (mixed $x): mixed => $x;
    $mutations = [
        'a second setup' => ['setups', function (array $m): array {
            $second = $m['zerops'][0];
            $second['setup'] = 'worker';
            $m['zerops'][] = $second;

            return $m;
        }],
        'the setup renamed' => ['setups', function (array $m): array {
            $m['zerops'][0]['setup'] = 'app';

            return $m;
        }],
        'build base php 8.4' => ['bases', function (array $m): array {
            $m['zerops'][0]['build']['base'] = array_map(fn (string $b): string => str_replace('php@8.5', 'php@8.4', $b), $m['zerops'][0]['build']['base']);

            return $m;
        }],
        'run base php-nginx 8.4' => ['bases', function (array $m): array {
            $m['zerops'][0]['run']['base'] = str_replace('8.5', '8.4', $m['zerops'][0]['run']['base']);

            return $m;
        }],
        'composer install without --no-dev' => ['build', function (array $m): array {
            $m['zerops'][0]['build']['buildCommands'] = array_map(fn (string $c): string => str_replace(' --no-dev', '', $c), $m['zerops'][0]['build']['buildCommands']);

            return $m;
        }],
        'config:cache in the build' => ['build', function (array $m): array {
            $m['zerops'][0]['build']['buildCommands'][] = 'php artisan config:cache';

            return $m;
        }],
        'the migration moved into the build' => ['migration', fn (array $m): array => $moveToBuild($m, 'artisan migrate')],
        'the migration without execOnce' => ['migration', fn (array $m): array => $replaceInit($m, 'artisan migrate', $migrate)],
        'a second migration in the init commands' => ['migration', function (array $m) use ($migrate): array {
            $m['zerops'][0]['run']['initCommands'][] = $migrate;

            return $m;
        }],
        'no migration at all' => ['migration', $withoutMigration],
        'the migration with --seed' => ['migration', function (array $m): array {
            $m['zerops'][0]['run']['initCommands'] = array_map(
                fn (string $c): string => str_contains($c, 'artisan migrate') ? $c.' --seed' : $c,
                $m['zerops'][0]['run']['initCommands'],
            );

            return $m;
        }],
        'optimize removed from the init commands' => ['caches', function (array $m): array {
            $m['zerops'][0]['run']['initCommands'] = array_values(array_filter(
                $m['zerops'][0]['run']['initCommands'],
                fn (string $c): bool => preg_match('/php artisan optimize$/', $c) !== 1,
            ));

            return $m;
        }],
        'filament:optimize moved into the build' => ['caches', fn (array $m): array => $moveToBuild($m, 'filament:optimize')],
        'a literal APP_ENV value in run.envVariables' => ['environment', function (array $m): array {
            $m['zerops'][0]['run']['envVariables']['APP_ENV'] = 'production';

            return $m;
        }],
        'VITE_APP_NAME as a literal' => ['environment', function (array $m): array {
            $m['zerops'][0]['build']['envVariables']['VITE_APP_NAME'] = 'Example';

            return $m;
        }],
        'an application key reference in the environment' => ['environment', function (array $m) use ($appKey): array {
            $m['zerops'][0]['run']['envVariables'][$appKey] = '${APP_SOURCE}';

            return $m;
        }],
        'an appended application key line' => ['raw', $none, fn (string $r): string => $r."\n".$appKey.': placeholder'."\n"],
        'an appended encoded key value' => ['raw', $none, fn (string $r): string => $r."\n# ".$base64.str_repeat('A', 8)."\n"],
        'an appended 40-character token' => ['raw', $none, fn (string $r): string => $r."\n# ".str_repeat('Q', 40)."\n"],
        'an appended PEM block' => ['raw', $none, fn (string $r): string => $r."\n# ".str_repeat('-', 5).'BEGIN'."\n"],
        'a missing site config file' => ['site', $none, $none, fn (string $path): ?string => null],
        'a site config with another root' => ['site', $none, $none, fn (string $path): ?string => "server {\n    root /srv/other;\n}\n"],
        'the crontab removed' => ['crontab', function (array $m): array {
            unset($m['zerops'][0]['run']['crontab']);

            return $m;
        }],
        'a crontab that runs every hour' => ['crontab', function (array $m): array {
            $m['zerops'][0]['run']['crontab'][0]['timing'] = '0 * * * *';

            return $m;
        }],
    ];

    foreach ($mutations as $label => $mutation) {
        [$category, $mutate] = $mutation;
        $mutateRaw = $mutation[2] ?? $none;
        $mutatedReader = $mutation[3] ?? $reader;

        $problems = zeropsAllProblems($mutate($manifest), $mutateRaw($raw), $mutatedReader);

        expect($problems[$category])->not->toBe([], "mutation '{$label}' was not reported by '{$category}'");
    }
});
