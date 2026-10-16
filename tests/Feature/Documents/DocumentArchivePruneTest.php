<?php

declare(strict_types=1);

use App\Domain\Documents\Jobs\PruneExpiredDocumentArchives;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Canary;

/*
 * D-08: a finished ZIP archive is kept seven days on the private disk, then a daily job deletes the object and the
 * record, for every owner. A pending or failed record goes seven days after its creation. The disk is faked; every
 * value is invented.
 */

/**
 * Makes an archive of the given Admin whose expiry is set directly, with its object on the faked disk when asked.
 *
 * @param  'ready'|'failed'|'pending'  $state
 */
function pruneArchiveMake(User $owner, CarbonImmutable $expiresAt, string $state = 'ready', bool $withFile = true): DocumentArchive
{
    test()->actingAs($owner);

    $archive = (new DocumentArchive([
        'summary' => '2 soubory, 1 KB',
        'document_ids' => [(string) Str::uuid(), (string) Str::uuid()],
        'document_count' => 2,
        'total_bytes' => 1024,
    ]));
    $archive->save();

    if ($state === 'ready') {
        $archive->markReady($archive->storagePath(), CarbonImmutable::now());

        if ($withFile) {
            Storage::disk()->put($archive->storagePath(), 'Fictional archive bytes');
        }
    } elseif ($state === 'failed') {
        $archive->markFailed();
    }

    $archive->forceFill(['expires_at' => $expiresAt])->save();

    return $archive;
}

/**
 * The ids of every archive, whoever owns it, read as a system run.
 *
 * @return list<string>
 */
function pruneArchiveIds(): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => DocumentArchive::query()->orderBy('id')->pluck('id')->all(),
    );
}

beforeEach(function (): void {
    Storage::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
});

it('deletes the object and the record of an expired archive and keeps a fresh one with its object', function (): void {
    $admin = Canary::admin();
    $expired = pruneArchiveMake($admin, CarbonImmutable::now()->subDay());
    $fresh = pruneArchiveMake($admin, CarbonImmutable::now()->addDay());
    $expiredPath = $expired->storagePath();
    $freshPath = $fresh->storagePath();

    expect(Storage::disk()->exists($expiredPath))->toBeTrue();

    PruneExpiredDocumentArchives::dispatchSync();

    expect(Storage::disk()->exists($expiredPath))->toBeFalse()
        ->and(pruneArchiveIds())->toBe([$fresh->getKey()])
        ->and(Storage::disk()->exists($freshPath))->toBeTrue();
});

it('deletes an expired pending and failed record, and a ready record whose object is already gone', function (): void {
    $admin = Canary::admin();
    $failed = pruneArchiveMake($admin, CarbonImmutable::now()->subDay(), 'failed');
    $pending = pruneArchiveMake($admin, CarbonImmutable::now()->subHour(), 'pending');
    $objectGone = pruneArchiveMake($admin, CarbonImmutable::now()->subMinute(), 'ready', withFile: false);
    $fresh = pruneArchiveMake($admin, CarbonImmutable::now()->addHour(), 'failed');

    expect($failed->path)->toBeNull()
        ->and($pending->path)->toBeNull()
        ->and(Storage::disk()->exists($objectGone->storagePath()))->toBeFalse();

    PruneExpiredDocumentArchives::dispatchSync();

    expect(pruneArchiveIds())->toBe([$fresh->getKey()])
        ->and(DocumentArchive::withoutGlobalScopes()->whereKey($pending->getKey())->exists())->toBeFalse();
});

it('prunes the expired archives of two different Admins in the same run', function (): void {
    $first = Canary::admin();
    $second = Canary::admin();
    $firstArchive = pruneArchiveMake($first, CarbonImmutable::now()->subDay());
    $secondArchive = pruneArchiveMake($second, CarbonImmutable::now()->subDay());
    $secondFresh = pruneArchiveMake($second, CarbonImmutable::now()->addDay());

    // The job is dispatched with nobody signed in: only the system context makes the other owners visible.
    auth()->logout();

    PruneExpiredDocumentArchives::dispatchSync();

    expect(pruneArchiveIds())->toBe([$secondFresh->getKey()])
        ->and(Storage::disk()->exists($firstArchive->storagePath()))->toBeFalse()
        ->and(Storage::disk()->exists($secondArchive->storagePath()))->toBeFalse()
        ->and(Storage::disk()->exists($secondFresh->storagePath()))->toBeTrue();
});

it('deletes nothing more and raises nothing on a second run', function (): void {
    $admin = Canary::admin();
    pruneArchiveMake($admin, CarbonImmutable::now()->subDay());
    $fresh = pruneArchiveMake($admin, CarbonImmutable::now()->addDay());

    PruneExpiredDocumentArchives::dispatchSync();
    PruneExpiredDocumentArchives::dispatchSync();

    expect(pruneArchiveIds())->toBe([$fresh->getKey()])
        ->and(Storage::disk()->exists($fresh->storagePath()))->toBeTrue();
});

it('keeps an archive until the very instant of its expiry has passed', function (): void {
    $admin = Canary::admin();
    $atTheInstant = pruneArchiveMake($admin, CarbonImmutable::now());

    PruneExpiredDocumentArchives::dispatchSync();

    expect(pruneArchiveIds())->toBe([$atTheInstant->getKey()]);

    $this->travelTo(CarbonImmutable::now()->addSecond());
    PruneExpiredDocumentArchives::dispatchSync();

    expect(pruneArchiveIds())->toBe([]);
});

it('removes a ready archive seven days after its completion and a pending one seven days after its creation', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);

    $ready = (new DocumentArchive(['summary' => 'Fiktivní výběr', 'document_ids' => [(string) Str::uuid()], 'document_count' => 1, 'total_bytes' => 10]));
    $ready->save();
    $ready->markReady($ready->storagePath(), CarbonImmutable::now());
    Storage::disk()->put($ready->storagePath(), 'Fictional archive bytes');

    $pending = (new DocumentArchive(['summary' => 'Fiktivní výběr', 'document_ids' => [(string) Str::uuid()], 'document_count' => 1, 'total_bytes' => 10]));
    $pending->save();

    // Six days and 23 hours after creation: both are still there.
    $this->travelTo(CarbonImmutable::now()->addDays(6)->addHours(23));
    PruneExpiredDocumentArchives::dispatchSync();

    expect(pruneArchiveIds())->toHaveCount(2);

    // Seven days and one hour after creation: both are gone, with the object.
    $this->travelTo(CarbonImmutable::now()->addHours(2));
    PruneExpiredDocumentArchives::dispatchSync();

    expect(pruneArchiveIds())->toBe([])
        ->and(Storage::disk()->exists($ready->storagePath()))->toBeFalse();
});

it('deletes 250 expired records while reading them in chunks', function (): void {
    $admin = Canary::admin();
    pruneArchiveMake($admin, CarbonImmutable::now()->subDay(), 'failed');

    $rows = [];
    $expiredAt = CarbonImmutable::now()->subDay()->toDateTimeString();
    $now = CarbonImmutable::now()->toDateTimeString();

    for ($i = 0; $i < 249; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'user_id' => $admin->getKey(),
            'status' => 'failed',
            'summary' => 'Fiktivní výběr',
            'document_ids' => '[]',
            'document_count' => 0,
            'total_bytes' => 0,
            'expires_at' => $expiredAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    DB::table('document_archives')->insert($rows);
    expect(DB::table('document_archives')->count())->toBe(250);

    DB::enableQueryLog();
    PruneExpiredDocumentArchives::dispatchSync();
    $selects = array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, '"document_archives"'),
    );

    // Every read of the table carries a limit, so no single query loads all 250 records.
    expect(DB::table('document_archives')->count())->toBe(0)
        ->and($selects)->not->toBeEmpty()
        ->and(count($selects))->toBeGreaterThan(1);

    foreach ($selects as $sql) {
        expect($sql)->toContain('limit');
    }
});

it('never touches a file outside the archive area of the same disk', function (): void {
    $admin = Canary::admin();
    pruneArchiveMake($admin, CarbonImmutable::now()->subDay());
    Storage::disk()->put('invoices/keep-me.pdf', 'fictional');
    Storage::disk()->put('document-archives/not-a-record.zip', 'fictional');

    PruneExpiredDocumentArchives::dispatchSync();

    expect(Storage::disk()->exists('invoices/keep-me.pdf'))->toBeTrue()
        ->and(Storage::disk()->exists('document-archives/not-a-record.zip'))->toBeTrue()
        ->and(pruneArchiveIds())->toBe([]);
});
