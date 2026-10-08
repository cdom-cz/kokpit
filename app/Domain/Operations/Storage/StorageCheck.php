<?php

declare(strict_types=1);

namespace App\Domain\Operations\Storage;

use Aws\Exception\AwsException;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Proves that private S3-compatible storage works from configuration alone (D-17).
 *
 * Five steps against a throwaway object under healthcheck/: write it, read it
 * back through a temporary URL, refuse a read without a signature, delete it and
 * confirm it is gone. The run stops at the first failed step and removes the
 * object on a best-effort basis.
 *
 * A signed URL is a credential for as long as it is valid, so it is never
 * returned, logged or put in an error: the steps carry the exception class and
 * the provider's error code only.
 */
final class StorageCheck
{
    public const string STEP_WRITE = 'write';

    public const string STEP_SIGNED_READ = 'signed_read';

    public const string STEP_PRIVATE = 'private';

    public const string STEP_DELETE = 'delete';

    public const string STEP_GONE = 'gone';

    private const int URL_VALID_MINUTES = 2;

    private const int HTTP_TIMEOUT_SECONDS = 10;

    private ?string $path = null;

    private string $disk = 's3';

    /**
     * @return list<StorageCheckStep>
     */
    public function run(string $disk = 's3'): array
    {
        $this->disk = $disk;
        $this->path = 'healthcheck/'.Str::uuid().'.txt';
        $path = $this->path;
        $body = 'Kontrola úložiště Kokpitu: příliš žluťoučký kůň úpěl ďábelské ódy. '.Str::uuid();

        $steps = [];
        $filesystem = null;
        $written = false;
        $deleted = false;

        $execute = function (string $name, Closure $action) use (&$steps): bool {
            $step = $this->measure($name, $action);
            $steps[] = $step;

            return $step->ok;
        };

        try {
            $filesystem = $this->filesystem();
        } catch (Throwable $e) {
            // Misconfiguration (for instance an unknown driver) fails the first step.
            $steps[] = new StorageCheckStep(self::STEP_WRITE, false, 0, $this->describe($e));

            return $steps;
        }

        try {
            if (! $execute(self::STEP_WRITE, function () use ($filesystem, $path, $body, &$written): ?string {
                $written = true;

                return $filesystem->put($path, $body) === false ? 'write_returned_false' : null;
            })) {
                return $steps;
            }

            if (! $execute(self::STEP_SIGNED_READ, fn (): ?string => $this->signedRead($filesystem, $path, $body))) {
                return $steps;
            }

            if (! $execute(self::STEP_PRIVATE, fn (): ?string => $this->unsignedRefused($filesystem, $path))) {
                return $steps;
            }

            if (! $execute(self::STEP_DELETE, function () use ($filesystem, $path, &$deleted): ?string {
                $result = $filesystem->delete($path);
                $deleted = $result;

                return $result ? null : 'delete_returned_false';
            })) {
                return $steps;
            }

            $execute(self::STEP_GONE, fn (): ?string => $filesystem->exists($path) ? 'still_exists' : null);

            return $steps;
        } finally {
            if ($written && ! $deleted) {
                $this->removeQuietly($filesystem, $path);
            }
        }
    }

    /**
     * The object path used by the last run, or null before any run.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Host of the configured endpoint, or a Czech description when the disk uses
     * the provider's default endpoint. Never the full URL.
     */
    public function endpointHost(?string $disk = null): string
    {
        $config = $this->diskConfig($disk ?? $this->disk);
        $endpoint = $config['endpoint'] ?? null;
        $host = is_string($endpoint) && $endpoint !== '' ? parse_url($endpoint, PHP_URL_HOST) : null;

        if (is_string($host) && $host !== '') {
            return $host;
        }

        return __('kokpit.storage_check.default_endpoint', ['region' => (string) ($config['region'] ?? '')]);
    }

    /**
     * Builds the disk from configuration with exceptions turned on, because a
     * disk with throw => false answers false to a failed write (research Pitfall 5).
     */
    private function filesystem(): FilesystemAdapter
    {
        $config = $this->diskConfig($this->disk);
        $config['throw'] = true;

        $filesystem = Storage::build($config);

        if (! $filesystem instanceof FilesystemAdapter) {
            throw new InvalidArgumentException('Unsupported filesystem adapter');
        }

        return $filesystem;
    }

    /**
     * @return array<string, mixed>
     */
    private function diskConfig(string $disk): array
    {
        $config = config('filesystems.disks.'.$disk);

        if (! is_array($config)) {
            throw new InvalidArgumentException('Unknown disk');
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    private function signedRead(FilesystemAdapter $filesystem, string $path, string $body): ?string
    {
        $url = $filesystem->temporaryUrl($path, now()->addMinutes(self::URL_VALID_MINUTES));

        $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->get($url);

        if ($response->status() !== 200) {
            return 'http_'.$response->status();
        }

        return $response->body() === $body ? null : 'body_mismatch';
    }

    private function unsignedRefused(FilesystemAdapter $filesystem, string $path): ?string
    {
        $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->get($filesystem->url($path));

        if (in_array($response->status(), [401, 403], true)) {
            return null;
        }

        return $response->successful() ? 'publicly_readable' : 'http_'.$response->status();
    }

    /**
     * @param  Closure(): ?string  $action  returns null on success or a fixed error code
     */
    private function measure(string $name, Closure $action): StorageCheckStep
    {
        $started = hrtime(true);

        try {
            $error = $action();
        } catch (Throwable $e) {
            $error = $this->describe($e);
        }

        $milliseconds = (int) round((hrtime(true) - $started) / 1_000_000);

        return new StorageCheckStep($name, $error === null, $milliseconds, $error);
    }

    /**
     * The exception class and, when an S3 error sits anywhere in the chain, the
     * provider's error code. The exception message is deliberately dropped: the
     * SDK puts the request URL and parts of the response into it.
     */
    private function describe(Throwable $e): string
    {
        $code = null;

        for ($current = $e; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof AwsException && $current->getAwsErrorCode() !== null) {
                $code = $current->getAwsErrorCode();

                break;
            }
        }

        $class = $e::class;

        return $code === null ? $class : $class.' ('.$code.')';
    }

    private function removeQuietly(FilesystemAdapter $filesystem, string $path): void
    {
        try {
            $filesystem->delete($path);
        } catch (Throwable) {
            // Best effort: the failed step is what the operator needs to see.
        }
    }
}
