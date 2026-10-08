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
