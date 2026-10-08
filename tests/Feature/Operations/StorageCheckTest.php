<?php

declare(strict_types=1);

use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\S3TestDisk;

/*
 * Runs the storage check against a real S3-compatible service (RustFS in DDEV
 * and in CI) on a dedicated test bucket. Never skipped: an unreachable endpoint
 * fails with a message naming the host (D-17, FND-16).
 */
pest()->group('s3');

beforeEach(function (): void {
    S3TestDisk::use();
});

/**
 * @return array{int, string}
 */
function runStorageCheck(): array
{
    $exit = Artisan::call('kokpit:storage:check');

    return [$exit, Artisan::output()];
}

it('uploads, reads through a temporary URL, refuses an unsigned read and deletes', function (): void {
    [$exit, $output] = runStorageCheck();

    expect($exit)->toBe(0)
        ->and(substr_count($output, 'v pořádku'))->toBe(5)
        ->and($output)->toContain(S3TestDisk::host())
        ->and($output)->toContain('healthcheck/')
        ->and($output)->not->toContain('selhalo');
});

it('leaves no healthcheck object in the bucket', function (): void {
    runStorageCheck();

    expect(S3TestDisk::objects('healthcheck/'))->toBe([]);
});

it('runs the check inside the system context', function (): void {
    $flags = [];

    Http::fake(function (Request $request) use (&$flags) {
        $flags[] = app(PartnerContext::class)->isSystem();

        return null; // fall through to the real request
    });

    runStorageCheck();

    expect($flags)->not->toBe([])
        ->and(array_unique($flags))->toBe([true])
        ->and(app(PartnerContext::class)->isSystem())->toBeFalse();
});

/**
 * A secret assembled at runtime, so no line of this file looks like a real value.
 */
function wrongSecret(): string
{
    return implode('-', ['not', 'the', 'secret', 'at', 'all']);
}

it('fails the write step with wrong credentials and runs no later step', function (): void {
    config(['filesystems.disks.s3.secret' => wrongSecret()]);

    [$exit, $output] = runStorageCheck();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Zápis objektu: selhalo')
        ->and($output)->toMatch('/UnableToWriteFile \([A-Za-z]+\)/')
        ->and($output)->toContain('Kontrola úložiště selhala v kroku „Zápis objektu“')
        ->and($output)->not->toContain('Čtení přes dočasný odkaz')
        ->and($output)->not->toContain(wrongSecret());
});

it('fails the write step when the endpoint is unreachable', function (): void {
    config(['filesystems.disks.s3.endpoint' => 'http://127.0.0.1:1']);

    [$exit, $output] = runStorageCheck();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Zápis objektu: selhalo')
        ->and($output)->toContain('127.0.0.1')
        ->and($output)->not->toContain('Čtení přes dočasný odkaz');
});

it('fails the private step for a publicly readable object and still deletes it', function (): void {
    S3TestDisk::use('kokpit-test-public');
    S3TestDisk::makePublic('kokpit-test-public');

    [$exit, $output] = runStorageCheck();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Odmítnutí čtení bez podpisu: selhalo')
        ->and($output)->toContain('publicly_readable')
        ->and($output)->toContain('Kontrola úložiště selhala v kroku „Odmítnutí čtení bez podpisu“')
        ->and($output)->toContain('Zápis objektu: v pořádku')
        ->and($output)->not->toContain('Smazání objektu')
        ->and(S3TestDisk::objects('healthcheck/'))->toBe([]);
});

it('fails the signed read step on a wrong body and still deletes the object', function (): void {
    Http::fake(function (Request $request) {
        return str_contains($request->url(), 'X-Amz-Signature') ? Http::response('another body', 200) : null;
    });

    [$exit, $output] = runStorageCheck();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Čtení přes dočasný odkaz: selhalo')
        ->and($output)->toContain('body_mismatch')
        ->and(S3TestDisk::objects('healthcheck/'))->toBe([]);
});

it('refuses a disk that is not an S3 disk', function (): void {
    $exit = Artisan::call('kokpit:storage:check', ['--disk' => 'local']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('není nastavený jako úložiště S3');
});

it('prints no signed URL, signature, credential or query string', function (): void {
    $queries = [];

    Http::fake(function (Request $request) use (&$queries) {
        $query = parse_url($request->url(), PHP_URL_QUERY);

        if (is_string($query) && $query !== '') {
            $queries[] = $query;
        }

        return null;
    });

    $secret = (string) config('filesystems.disks.s3.secret');
    $key = (string) config('filesystems.disks.s3.key');

    [, $success] = runStorageCheck();

    config(['filesystems.disks.s3.secret' => wrongSecret()]);
    [, $wrongCredentials] = runStorageCheck();

    config(['filesystems.disks.s3.secret' => $secret, 'filesystems.disks.s3.endpoint' => 'http://127.0.0.1:1']);
    [, $unreachable] = runStorageCheck();

    expect($queries)->not->toBe([]);

    foreach ([$success, $wrongCredentials, $unreachable] as $output) {
        expect($output)
            ->not->toContain('X-Amz-Signature')
            ->not->toContain('X-Amz-Credential')
            ->not->toContain($secret)
            ->not->toContain($key)
            ->not->toContain(wrongSecret())
            ->not->toContain('http://')
            ->not->toContain('https://');

        foreach ($queries as $query) {
            expect($output)->not->toContain($query);
        }
    }
});
