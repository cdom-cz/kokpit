<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Knuckles\Scribe\Extracting\Shared\UrlParamsNormalizer;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The generated API documentation, for the tests.
 *
 * Generation runs `php artisan scribe:generate` in a subprocess, exactly as a container start does it, with
 * the database unreachable and the base URL set to a placeholder, so the tests prove both that generation
 * needs no database and what the documented host is. It runs once per PHP process; the output lands in the
 * gitignored paths of the real application (spec in storage/app/private/scribe, view in resources/views/scribe).
 */
final class ApiDocs
{
    /**
     * The generation command exactly as the last init command of zerops.yml spells it.
     */
    public const array COMMAND = ['php', 'artisan', 'scribe:generate', '--no-interaction'];

    private static bool $generated = false;

    /**
     * The APP_URL of the test generation, assembled at runtime so no line of this file holds a host.
     */
    public static function placeholderUrl(): string
    {
        return 'https://'.implode('.', ['kokpit', 'example', 'com']);
    }

    /**
     * Runs the generation and returns the finished process.
     *
     * @param  array<string, string>  $env  extra environment variables, overriding the defaults below
     */
    public static function generate(array $env = []): Process
    {
        $process = new Process(
            [PHP_BINARY, ...array_slice(self::COMMAND, 1)],
            base_path(),
            [
                'APP_URL' => self::placeholderUrl(),
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '1',
                ...$env,
            ],
        );
        $process->setTimeout(300);
        $process->run();

        return $process;
    }

    /**
     * Runs the generation in a process that registers one api/v1 route whose attribute cannot be read
     * (tests/Support/scribe-broken-route.php). Scribe finishes the run but exits non-zero, which is what makes a
     * docs failure fail the deploy. The output of the run holds the broken route, so callers generate again.
     *
     * @param  array<string, string>  $env  extra environment variables, overriding the defaults
     */
    public static function generateWithBrokenRoute(array $env = []): Process
    {
        $process = new Process(
            [PHP_BINARY, 'tests/Support/scribe-broken-route.php'],
            base_path(),
            [
                'APP_URL' => self::placeholderUrl(),
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '1',
                ...$env,
            ],
        );
        $process->setTimeout(300);
        $process->run();

        return $process;
    }

    /**
     * Every file a generation leaves behind: the page, the spec, Scribe's intermediate files and the published
     * theme assets. Scribe's own bookkeeping of file hashes is left out, it is a list of checksums.
     *
     * @return list<string> absolute paths
     */
    public static function generatedFiles(): array
    {
        self::ensureGenerated();

        $files = [resource_path('views/scribe/index.blade.php'), self::specPath()];

        foreach ([base_path('.scribe'), public_path('vendor/scribe')] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() !== '.filehashes') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The hosts a URL in the generated output may name: the placeholder base URL's host plus exactly the hosts that
     * occur in the stock theme's views (read now, so an upgrade that adds a CDN still passes while a real host can
     * never slip in), plus the documentation host Scribe links to in a comment of an intermediate file.
     *
     * @return list<string> lower case
     */
    public static function allowedHosts(): array
    {
        // Scribe writes a comment with a link to its own documentation into the intermediate file of the custom
        // endpoints (.scribe/endpoints/custom.0.yaml); the file is never published, the host holds no instance data.
        $hosts = [strtolower((string) parse_url(self::placeholderUrl(), PHP_URL_HOST)), implode('.', ['scribe', 'knuckles', 'wtf'])];

        $views = base_path('vendor/knuckleswtf/scribe/resources/views');
        Assert::assertDirectoryExists($views);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                preg_match_all('#https?://([A-Za-z0-9.-]+)#', (string) file_get_contents($file->getPathname()), $found);

                foreach ($found[1] as $host) {
                    $hosts[] = strtolower($host);
                }
            }
        }

        $hosts = array_values(array_unique($hosts));
        sort($hosts);

        return $hosts;
    }

    /**
     * Generates once per PHP process and fails with the command output when the generation does not exit 0.
     */
    public static function ensureGenerated(): void
    {
        if (self::$generated) {
            return;
        }

        $process = self::generate();

        Assert::assertSame(
            0,
            $process->getExitCode(),
            "scribe:generate failed:\n".$process->getOutput().$process->getErrorOutput(),
        );

        self::$generated = true;
    }

    public static function specPath(): string
    {
        return storage_path('app/private/scribe/openapi.yaml');
    }

    /**
     * The parsed OpenAPI spec.
     *
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        self::ensureGenerated();

        $spec = Yaml::parseFile(self::specPath());
        Assert::assertIsArray($spec);

        /** @var array<string, mixed> $spec */
        return $spec;
    }

    /**
     * The generated Blade view of the documentation page.
     */
    public static function view(): string
    {
        self::ensureGenerated();

        $contents = file_get_contents(resource_path('views/scribe/index.blade.php'));
        Assert::assertIsString($contents);

        return $contents;
    }

    /**
     * Every route under api/v1/.
     *
     * @return list<LaravelRoute>
     */
    public static function apiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * The documented operation of the route, or null when the spec has none for its path and method.
     *
     * @return array<string, mixed>|null
     */
    public static function operation(LaravelRoute $route): ?array
    {
        $paths = self::spec()['paths'] ?? [];
        $path = is_array($paths) ? ($paths['/'.self::documentedUri($route)] ?? null) : null;
        $operation = is_array($path) ? ($path[strtolower(self::method($route))] ?? null) : null;

        if (! is_array($operation)) {
            return null;
        }

        /** @var array<string, mixed> $operation */
        return $operation;
    }

    /**
     * The URI the generated spec lists the route under. Scribe renames the path parameter of a resource route
     * (a route named `tasks.show` with `{task}` is documented as `{id}`), so the lookup in the spec goes through
     * Scribe's own normalizer instead of the raw route URI.
     */
    public static function documentedUri(LaravelRoute $route): string
    {
        $controller = $route->getControllerClass();
        $action = $route->getActionMethod();

        if ($controller === null || ! method_exists($controller, $action)) {
            return $route->uri();
        }

        return UrlParamsNormalizer::normalizeParameterNamesInRouteUri($route, new ReflectionMethod($controller, $action));
    }

    /**
     * The one HTTP method the documentation lists for the route (HEAD and OPTIONS are not documented).
     */
    public static function method(LaravelRoute $route): string
    {
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                return $method;
            }
        }

        return 'GET';
    }

    /**
     * Documentation rules over the given routes, derived from the route table and the spec: a route is
     * documented with its method, the 401, 403 and 429 responses, the ability it requires, the status
     * codes its method implies, the query parameters its controller declares on a list route (a FILTERS and a
     * SORTS constant, the convention of the list controllers) and the Idempotency-Key header when it uses the
     * EnsureIdempotency middleware. Returns one sentence per violation; an empty list means documented.
     *
     * @param  list<LaravelRoute>  $routes
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    public static function violations(array $routes, array $spec): array
    {
        $violations = [];
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];

        foreach ($routes as $route) {
            $method = self::method($route);
            $label = $method.' /'.$route->uri();
            $path = $paths['/'.self::documentedUri($route)] ?? null;
            $operation = is_array($path) ? ($path[strtolower($method)] ?? null) : null;

            if (! is_array($operation)) {
                $violations[] = "$label is not in the spec";

                continue;
            }

            $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
            $middleware = array_values(array_filter($route->gatherMiddleware(), is_string(...)));

            $expected = [401, 403, 429, ...self::statusCodes($method, str_contains($route->uri(), '{'))];
            if (self::hasMiddleware($middleware, 'EnsureIdempotency')) {
                $expected = [...$expected, 409, 422];
            }
            foreach (array_unique($expected) as $status) {
                if (! array_key_exists($status, $responses)) {
                    $violations[] = "$label documents no $status response";
                }
            }

            $ability = self::ability($middleware);
            $description = is_string($operation['description'] ?? null) ? $operation['description'] : '';
            if ($ability === null) {
                $violations[] = "$label has no abilities: middleware to document";
            } elseif (! str_contains($description, '`'.$ability.'`')) {
                $violations[] = "$label does not name its ability `$ability` in the description";
            }

            $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
            $queryNames = [];
            $headerNames = [];
            foreach ($parameters as $parameter) {
                if (! is_array($parameter) || ! is_string($parameter['name'] ?? null)) {
                    continue;
                }
                if (($parameter['in'] ?? null) === 'query') {
                    $queryNames[] = $parameter['name'];
                }
                if (($parameter['in'] ?? null) === 'header') {
                    $headerNames[] = strtolower($parameter['name']);
                }
            }

            $controller = $route->getControllerClass();
            // Only a collection route (GET without a path parameter) is a list; the detail route of the same controller has no filters.
            if ($method === 'GET' && ! str_contains($route->uri(), '{') && $controller !== null && defined($controller.'::FILTERS')) {
                $filters = constant($controller.'::FILTERS');
                $wanted = ['cursor', 'per_page'];
                foreach (is_array($filters) ? $filters : [] as $filter) {
                    $wanted[] = 'filter['.(is_string($filter) ? $filter : '?').']';
                }
                if (defined($controller.'::SORTS')) {
                    $wanted[] = 'sort';
                }
                foreach ($wanted as $name) {
                    if (! in_array($name, $queryNames, true)) {
                        $violations[] = "$label documents no query parameter $name";
                    }
                }
            }

            if (self::hasMiddleware($middleware, 'EnsureIdempotency') && ! in_array('idempotency-key', $headerNames, true)) {
                $violations[] = "$label documents no Idempotency-Key header";
            }
        }

        return $violations;
    }

    /**
     * The status codes a method must document besides 401, 403 and 429.
     *
     * @return list<int>
     */
    private static function statusCodes(string $method, bool $hasPathParameter): array
    {
        return match ($method) {
            'GET' => $hasPathParameter ? [200, 404] : [200, 422],
            'POST' => [201, 422],
            'PATCH' => [200, 404, 409, 422],
            'DELETE' => [204, 404, 409],
            default => [],
        };
    }

    /**
     * @param  list<string>  $middleware
     */
    private static function hasMiddleware(array $middleware, string $shortName): bool
    {
        foreach ($middleware as $name) {
            $class = explode(':', $name)[0];

            if ($class === $shortName || str_ends_with($class, '\\'.$shortName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $middleware
     */
    private static function ability(array $middleware): ?string
    {
        foreach ($middleware as $name) {
            if (str_starts_with($name, 'abilities:')) {
                return substr($name, strlen('abilities:'));
            }
        }

        return null;
    }
}
