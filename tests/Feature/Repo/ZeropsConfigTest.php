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
 * The repository has no package.json and no lockfile, so the build carries no frontend toolchain: no Node, Bun or Deno
 * base image, no package-manager or Node command in any command group, no build environment map (its only entry was
 * the Vite app name) and no cache entry for node_modules or a lockfile. The maintainer re-adds a toolchain together
 * with this check when a frontend exists.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsFrontendProblems(array $manifest): array
{
    $problems = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        foreach (zeropsList(zeropsGet($setup, 'build.base')) as $base) {
            if (preg_match('#(?:^|/)(?:nodejs|bun|deno)@#', $base) === 1) {
                $problems[] = "setup {$name} build.base has a frontend runtime: {$base}";
            }
        }

        foreach (zeropsCommandGroups($setup) as $group => $commands) {
            foreach ($commands as $command) {
                if (preg_match('/(?:^|[\s;&|(])(?:pnpm|npm|npx|yarn|node|bun|vite)(?=$|[\s;&|)])/', $command) === 1) {
                    $problems[] = "setup {$name} {$group} runs a frontend tool: {$command}";
                }
            }
        }

        if (zeropsGet($setup, 'build.envVariables') !== null) {
            $problems[] = "setup {$name} has a build.envVariables map, the build reads no environment";
        }

        foreach (zeropsList(zeropsGet($setup, 'build.cache')) as $entry) {
            if (in_array(rtrim($entry, '/'), ['node_modules', 'pnpm-lock.yaml', 'package-lock.json', 'yarn.lock', 'package.json'], true)) {
                $problems[] = "setup {$name} build.cache has a frontend entry: {$entry}";
            }
        }
    }

    return $problems;
}

/**
 * The readiness gate (D-18): php artisan kokpit:deploy:verify runs exactly once in the manifest, as a plain init
 * command on every container (never inside execOnce, never with its exit status swallowed), after the migration and
 * before the framework caches and every Horizon or supervisor step. Zerops ends the deploy at the first failing init
 * command, so a failed verify keeps the previous version serving; the position decides what a failure can skip.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsVerifyProblems(array $manifest): array
{
    $found = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        foreach (zeropsCommandGroups($setup) as $group => $commands) {
            foreach ($commands as $command) {
                if (str_contains($command, 'kokpit:deploy:verify')) {
                    $found[] = [$name, $group, $command];
                }
            }
        }
    }

    if (count($found) !== 1) {
        return ['expected exactly one kokpit:deploy:verify command in the manifest, found '.count($found)];
    }

    [$name, $group, $command] = $found[0];
    $problems = [];

    if ($group !== 'run.initCommands') {
        return ["the verify of setup {$name} is in {$group}, expected run.initCommands"];
    }

    if ($command !== 'php artisan kokpit:deploy:verify') {
        $problems[] = "the verify is not exactly 'php artisan kokpit:deploy:verify': {$command}";
    }

    if (str_contains($command, 'execOnce')) {
        $problems[] = 'the verify is wrapped in execOnce, it must run on every container';
    }

    $init = zeropsCommandGroups(zeropsSetups($manifest)[$name])['run.initCommands'];
    $verifyIndex = (int) array_key_first(array_filter($init, fn (string $c): bool => str_contains($c, 'kokpit:deploy:verify')));
    $migrateIndexes = array_keys(array_filter($init, fn (string $c): bool => str_contains($c, 'artisan migrate')));

    if ($migrateIndexes === []) {
        $problems[] = 'run.initCommands has no artisan migrate command to run the verify after';
    } elseif ($verifyIndex < $migrateIndexes[0]) {
        $problems[] = 'the verify runs before the migration';
    }

    foreach ($init as $index => $other) {
        if ($index < $verifyIndex && preg_match('/filament:optimize|horizon|supervisor/', $other) === 1) {
            $problems[] = "the verify runs after: {$other}";
        }
    }

    return $problems;
}

/**
 * Horizon starts at container start: sudo supervisorctl start horizon is the last horizon or supervisor step of
 * run.initCommands, strictly after the migration and the verify, and plain (not wrapped in execOnce, so every
 * container starts its own Horizon). Horizon therefore only starts once the migration and the verify passed.
 *
 * @param  array<string|int, mixed>  $manifest
 * @return list<string>
 */
function zeropsHorizonProblems(array $manifest): array
{
    $problems = [];

    foreach (zeropsSetups($manifest) as $name => $setup) {
        $init = zeropsCommandGroups($setup)['run.initCommands'];
        $starts = array_keys(array_filter($init, fn (string $c): bool => str_contains($c, 'supervisorctl start horizon')));

        if (count($starts) !== 1) {
            $problems[] = "setup {$name} run.initCommands has ".count($starts).' supervisorctl start horizon commands, expected exactly one';

            continue;
        }

        $index = $starts[0];

        if ($init[$index] !== 'sudo supervisorctl start horizon') {
            $problems[] = "setup {$name} starts Horizon with something other than 'sudo supervisorctl start horizon': {$init[$index]}";
        }

        if (str_contains($init[$index], 'execOnce')) {
            $problems[] = "setup {$name} starts Horizon inside execOnce, every container must start its own";
        }

        $migrate = array_keys(array_filter($init, fn (string $c): bool => str_contains($c, 'artisan migrate')));
        $verify = array_keys(array_filter($init, fn (string $c): bool => str_contains($c, 'kokpit:deploy:verify')));

        if ($migrate === [] || $index < $migrate[0]) {
            $problems[] = "setup {$name} starts Horizon before the migration";
        }

        if ($verify === [] || $index < $verify[0]) {
            $problems[] = "setup {$name} starts Horizon before the verify";
        }

        foreach ($init as $other => $command) {
            if ($other > $index && preg_match('/horizon|supervisor/', $command) === 1) {
                $problems[] = "setup {$name} has a horizon or supervisor step after starting Horizon: {$command}";
            }
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
        'frontend' => zeropsFrontendProblems($manifest),
        'verify' => zeropsVerifyProblems($manifest),
        'horizon' => zeropsHorizonProblems($manifest),
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

it('builds without a frontend toolchain and without a build environment', function (): void {
    expect(zeropsFrontendProblems(zeropsManifest()))->toBe([]);
});

it('verifies the deploy once per container, after the migration and before the caches and Horizon', function (): void {
    expect(zeropsVerifyProblems(zeropsManifest()))->toBe([]);
});

it('starts Horizon at container start, last, after the migration and the verify', function (): void {
    expect(zeropsHorizonProblems(zeropsManifest()))->toBe([]);
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

    $verify = 'php artisan kokpit:deploy:verify';
    $startHorizon = 'sudo supervisorctl start horizon';
    $wrapped = fn (string $inner): string => 'sudo -E -u zerops -- zsc execOnce ${appVersionId} -- '.$inner;

    // Removes a command from the init commands and puts it back before or after the first command containing the
    // anchor, or at the end when there is no anchor.
    $placeInit = function (array $m, string $command, ?string $anchor, bool $after = false): array {
        $commands = array_values(array_filter(
            $m['zerops'][0]['run']['initCommands'],
            fn (string $c): bool => ! str_contains($c, $command),
        ));

        if ($anchor === null) {
            $commands[] = $command;
        } else {
            $position = null;

            foreach ($commands as $index => $candidate) {
                if (str_contains($candidate, $anchor)) {
                    $position = $index;

                    break;
                }
            }

            expect($position)->not->toBeNull("anchor '{$anchor}' is not in the init commands");
            array_splice($commands, (int) $position + ($after ? 1 : 0), 0, [$command]);
        }

        $m['zerops'][0]['run']['initCommands'] = $commands;

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
        'a literal build variable' => ['environment', function (array $m): array {
            $m['zerops'][0]['build']['envVariables']['APP_NAME'] = 'Example';

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
        'the verify removed' => ['verify', fn (array $m): array => $replaceInit($m, 'kokpit:deploy:verify', null)],
        'the verify before the migration' => ['verify', fn (array $m): array => $placeInit($m, $verify, 'artisan migrate')],
        'the verify inside execOnce' => ['verify', fn (array $m): array => $replaceInit($m, 'kokpit:deploy:verify', $wrapped($verify))],
        'the verify after filament:optimize' => ['verify', fn (array $m): array => $placeInit($m, $verify, 'filament:optimize', true)],
        'the verify after the supervisor steps' => ['verify', fn (array $m): array => $placeInit($m, $verify, null)],
        'a second verify' => ['verify', function (array $m) use ($verify): array {
            $commands = [];

            foreach ($m['zerops'][0]['run']['initCommands'] as $command) {
                $commands[] = $command;

                if ($command === $verify) {
                    $commands[] = $verify;
                }
            }

            $m['zerops'][0]['run']['initCommands'] = $commands;

            return $m;
        }],
        'the verify failure swallowed' => ['verify', fn (array $m): array => $replaceInit($m, 'kokpit:deploy:verify', $verify.' || true')],
        'start horizon removed' => ['horizon', fn (array $m): array => $replaceInit($m, 'supervisorctl start horizon', null)],
        'start horizon before the verify' => ['horizon', fn (array $m): array => $placeInit($m, $startHorizon, $verify)],
        'start horizon inside execOnce' => ['horizon', fn (array $m): array => $replaceInit($m, 'supervisorctl start horizon', $wrapped($startHorizon))],
        'a supervisor step after start horizon' => ['horizon', function (array $m): array {
            $m['zerops'][0]['run']['initCommands'][] = 'sudo supervisorctl status';

            return $m;
        }],
        'a pnpm step in the build' => ['frontend', function (array $m): array {
            $m['zerops'][0]['build']['buildCommands'][] = 'pnpm install';

            return $m;
        }],
        'a node base in the build' => ['frontend', function (array $m): array {
            $m['zerops'][0]['build']['base'][] = 'alpine/nodejs@24';

            return $m;
        }],
        'an npm step in the init commands' => ['frontend', function (array $m): array {
            $m['zerops'][0]['run']['initCommands'][] = 'npm run build';

            return $m;
        }],
        'a build environment map with a reference' => ['frontend', function (array $m): array {
            $m['zerops'][0]['build']['envVariables'] = ['APP_NAME' => '${RUNTIME_APP_NAME}'];

            return $m;
        }],
        'node_modules in the build cache' => ['frontend', function (array $m): array {
            $m['zerops'][0]['build']['cache'][] = 'node_modules';

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
