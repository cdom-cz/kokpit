<?php

declare(strict_types=1);

namespace Tests\Support;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;

/**
 * Points the s3 disk at a dedicated test bucket on the S3-compatible service of
 * the environment (the RustFS service of DDEV, a pinned RustFS service in CI).
 *
 * The development bucket is never touched. An unreachable endpoint fails the
 * test with a message naming the endpoint host; it never skips, because a green
 * run that silently tested nothing is worse than a red one.
 */
final class S3TestDisk
{
    public const string BUCKET = 'kokpit-test';

    /**
     * Sets the bucket and makes sure it exists.
     */
    public static function use(string $bucket = self::BUCKET): void
    {
        config(['filesystems.disks.s3.bucket' => $bucket]);
        Storage::forgetDisk('s3');

        $client = self::client();
        $host = self::host();

        try {
            $client->headBucket(['Bucket' => $bucket]);
            self::purge('healthcheck/');

            return;
        } catch (AwsException $e) {
            if ($e->getStatusCode() !== 404) {
                Assert::fail(self::unreachable($host, $e));
            }
        }

        try {
            $client->createBucket(['Bucket' => $bucket]);
        } catch (AwsException $e) {
            Assert::fail(self::unreachable($host, $e));
        }
    }

    /**
     * Lets anyone read the objects of the bucket without a signature, which is
     * the misconfiguration the storage check has to catch.
     */
    public static function makePublic(string $bucket): void
    {
        self::client()->putBucketPolicy([
            'Bucket' => $bucket,
            'Policy' => (string) json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => ['*']],
                    'Action' => ['s3:GetObject'],
                    'Resource' => ['arn:aws:s3:::'.$bucket.'/*'],
                ]],
            ]),
        ]);
    }

    /**
     * Removes what an earlier, interrupted run may have left below a prefix, so
     * "nothing is left behind" is asserted against a known starting point.
     */
    private static function purge(string $prefix): void
    {
        foreach (self::objects($prefix) as $key) {
            self::client()->deleteObject([
                'Bucket' => (string) config('filesystems.disks.s3.bucket'),
                'Key' => $key,
            ]);
        }
    }

    /**
     * Names of the objects in the test bucket below a prefix.
     *
     * @return list<string>
     */
    public static function objects(string $prefix): array
    {
        $result = self::client()->listObjectsV2([
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
            'Prefix' => $prefix,
        ]);

        $keys = [];

        foreach ((array) ($result['Contents'] ?? []) as $object) {
            $keys[] = (string) $object['Key'];
        }

        return $keys;
    }

    public static function client(): S3Client
    {
        $disk = Storage::build((array) config('filesystems.disks.s3'));

        Assert::assertInstanceOf(FilesystemAdapter::class, $disk);

        /** @var S3Client $client */
        $client = $disk->getClient();

        return $client;
    }

    public static function host(): string
    {
        $host = parse_url((string) config('filesystems.disks.s3.endpoint'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '(no endpoint configured)';
    }

    private static function unreachable(string $host, AwsException $e): string
    {
        $code = $e->getAwsErrorCode();

        return 'The S3 test service at "'.$host.'" is not usable ('.($code ?? 'no response').'). '
            .'Start the DDEV project (ddev start) or the RustFS service of CI; S3 tests never skip.';
    }
}
