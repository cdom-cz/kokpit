<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Static contract of the versioned DDEV project. Nothing here needs Docker:
 * the files are parsed, never started.
 */
function ddevFile(string $name): string
{
    return base_path('.ddev/'.$name);
}

/**
 * @return array<string, mixed>
 */
function ddevYaml(string $name): array
{
    $parsed = Yaml::parseFile(ddevFile($name));

    return is_array($parsed) ? $parsed : [];
}

/**
 * Every scalar string inside a nested YAML structure, in document order.
 *
 * @param  array<mixed>  $data
 * @return list<string>
 */
function ddevStrings(array $data): array
{
    $strings = [];

    array_walk_recursive($data, function (mixed $value) use (&$strings): void {
        if (is_string($value)) {
            $strings[] = $value;
        }
    });

    return $strings;
}

it('parses the DDEV config and pins PHP and PostgreSQL', function () {
    $config = ddevYaml('config.yaml');

    expect($config['php_version'])->toBe('8.5')
        ->and($config['database']['type'])->toBe('postgres')
        ->and($config['database']['version'])->toBe('18');
});

it('runs exactly the queue worker and scheduler daemons', function () {
    $daemons = ddevYaml('config.yaml')['web_extra_daemons'] ?? [];

    expect(array_column($daemons, 'name'))->toBe(['queue-worker', 'scheduler']);
});

it('makes every daemon wait for vendor/autoload.php before it execs artisan', function () {
    $daemons = ddevYaml('config.yaml')['web_extra_daemons'] ?? [];

    expect($daemons)->toHaveCount(2);

    foreach ($daemons as $daemon) {
        $command = $daemon['command'];

        $wait = strpos($command, 'vendor/autoload.php');
        $exec = strpos($command, 'exec php artisan');

        expect($wait)->not->toBeFalse()
            ->and($exec)->not->toBeFalse()
            ->and($wait)->toBeLessThan($exec);
    }
});

it('creates the kokpit_test database in the post-start hook', function () {
    $hooks = ddevYaml('config.yaml')['hooks']['post-start'] ?? [];

    expect(implode("\n", ddevStrings($hooks)))->toContain('kokpit_test');
});

it('keeps instance-specific values and secrets out of the versioned DDEV files', function () {
    $forbidden = [
        '/Users/',
        '/home/',
        '/root/',
    ];

    $files = ['config.yaml', 'docker-compose.rustfs.yaml', 'docker-compose.redis.yaml'];

    foreach ($files as $file) {
        $contents = file_get_contents(ddevFile($file));

        foreach ($forbidden as $needle) {
            expect($contents)->not->toContain($needle);
        }

        // Any URL host must be a DDEV service name or loopback.
        preg_match_all('#https?://([A-Za-z0-9.-]+)#', $contents, $matches);
        foreach ($matches[1] as $host) {
            expect($host)->toBeIn(['localhost', '127.0.0.1', 'rustfs', 'redis', 'db', 'example.com']);
        }

        // No public-looking hostname anywhere in the file. The com.ddev.* label keys
        // are DDEV's own reverse-DNS label names, not hostnames.
        $withoutLabels = str_replace(['example.com', 'com.ddev.'], '', $contents);
        expect(preg_match('/\b[a-z0-9-]+\.(com|net|org|cz|io|dev|site|cloud|app)\b/i', $withoutLabels))->toBe(0);
    }

    expect(ddevYaml('config.yaml')['web_environment'] ?? [])->toBe([]);
});

it('defines the RustFS service pinned to a version and never to latest', function () {
    $services = ddevYaml('docker-compose.rustfs.yaml')['services'];

    expect($services)->toHaveKeys(['rustfs', 'rustfs-init'])
        ->and($services['rustfs']['image'])->toBe('rustfs/rustfs:1.0.1')
        ->and(implode("\n", ddevStrings($services)))->not->toContain(':latest');
});

it('keeps the bucket init container alive and creates the kokpit-dev bucket', function () {
    $init = ddevYaml('docker-compose.rustfs.yaml')['services']['rustfs-init'];
    $command = trim(implode(' ', (array) $init['command']));

    expect($command)->toEndWith('exec sleep infinity')
        ->and($command)->toContain('kokpit-dev');
});

it('ships the Redis service from the official add-on', function () {
    expect(file_exists(ddevFile('docker-compose.redis.yaml')))->toBeTrue();
});
