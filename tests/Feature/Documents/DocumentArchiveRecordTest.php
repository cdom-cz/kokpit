<?php

declare(strict_types=1);

use App\Domain\Documents\Enums\DocumentArchiveStatus;
use App\Domain\Documents\Models\DocumentArchive;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\Canary;

/*
 * The record behind every ZIP download (DO-02, D-08): owned by the Admin who asked, invisible to a second Admin
 * and to every Partner. Names, ids and paths are invented.
 */

/**
 * Creates an archive request for the signed-in user with two invented document ids.
 *
 * @param  array<string, mixed>  $overrides
 */
function documentArchiveCreate(array $overrides = []): DocumentArchive
{
    return DocumentArchive::query()->create([
        'summary' => '2 soubory, 3 MB',
        'document_ids' => [(string) Str::uuid(), (string) Str::uuid()],
        'document_count' => 2,
        'total_bytes' => 3145728,
        ...$overrides,
    ]);
}

describe('creating (Admin)', function (): void {
    it('stores a pending archive for the signed-in Admin with its ids, count and size', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);

        $archive = documentArchiveCreate()->refresh();

        expect($archive->user_id)->toBe($admin->getKey())
            ->and($archive->status)->toBe(DocumentArchiveStatus::Pending)
            ->and($archive->status->getLabel())->toBe('Připravuje se')
            ->and($archive->document_ids)->toHaveCount(2)
            ->and($archive->document_count)->toBe(2)
            ->and($archive->total_bytes)->toBe(3145728)
            ->and($archive->summary)->toBe('2 soubory, 3 MB')
            ->and($archive->path)->toBeNull()
            ->and($archive->completed_at)->toBeNull();
    });

    it('gives the archive a UUID v7 id by default', function (): void {
        $this->actingAs(Canary::admin());

        $id = documentArchiveCreate()->refresh()->getKey();

        expect(Str::isUuid($id))->toBeTrue()
            ->and($id[14])->toBe('7');
    });
});

describe('visibility', function (): void {
    it('lets the Admin who asked read the archive', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);
        $archive = documentArchiveCreate();

        expect(DocumentArchive::query()->count())->toBe(1)
            ->and(Gate::forUser($admin)->allows('viewAny', DocumentArchive::class))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('view', $archive))->toBeTrue();
    });

    it('shows a second Admin none of the archives of the first', function (): void {
        $first = Canary::admin();
        $second = Canary::admin();

        $this->actingAs($first);
        documentArchiveCreate();
        expect(DocumentArchive::query()->count())->toBe(1);

        $this->actingAs($second);
        expect(DocumentArchive::query()->count())->toBe(0);
    });

    it('shows a Partner with a client no archive and denies every ability', function (): void {
        [$clientA] = Canary::twoClients();
        $this->actingAs(Canary::admin());
        $archive = documentArchiveCreate();

        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);

        expect(DocumentArchive::query()->count())->toBe(0);

        foreach (['viewAny', 'create'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, DocumentArchive::class))->toBeFalse($ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, $archive))->toBeFalse($ability);
        }
    });

    it('shows a Partner without a client nothing', function (): void {
        $this->actingAs(Canary::admin());
        documentArchiveCreate();

        $this->actingAs(Canary::partnerFor(null));

        expect(DocumentArchive::query()->count())->toBe(0);
    });
});

describe('labels', function (): void {
    it('reads the Czech enum labels and colours', function (): void {
        expect(DocumentArchiveStatus::Pending->getLabel())->toBe('Připravuje se')
            ->and(DocumentArchiveStatus::Ready->getLabel())->toBe('Hotovo')
            ->and(DocumentArchiveStatus::Failed->getLabel())->toBe('Selhalo')
            ->and(DocumentArchiveStatus::Pending->getColor())->toBe('info')
            ->and(DocumentArchiveStatus::Ready->getColor())->toBe('success')
            ->and(DocumentArchiveStatus::Failed->getColor())->toBe('danger');
    });
});

describe('naming', function (): void {
    it('builds the storage path from the archive id only', function (): void {
        $this->actingAs(Canary::admin());
        $archive = documentArchiveCreate();

        expect($archive->storagePath())->toBe('document-archives/'.$archive->getKey().'.zip');
    });

    it('names the download after the Prague creation day', function (): void {
        $this->actingAs(Canary::admin());

        // 23:30 UTC on 16 October is already 17 October in Prague.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 23:30:00', 'UTC'));
        $archive = documentArchiveCreate(['summary' => 'Příklad Klient s.r.o., faktura.pdf'])->refresh();

        expect($archive->downloadFileName())->toBe('soubory-2026-10-17.zip')
            ->and($archive->downloadFileName())->not->toContain('Klient');

        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));

        expect(documentArchiveCreate()->refresh()->downloadFileName())->toBe('soubory-2026-10-16.zip');
    });
});

describe('lifecycle', function (): void {
    it('expires a new pending archive seven days after its creation', function (): void {
        $this->actingAs(Canary::admin());
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'UTC'));

        $archive = documentArchiveCreate()->refresh();

        expect($archive->expires_at->equalTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'UTC')))->toBeTrue();
    });

    it('marks an archive ready with the path, the completion time and a fresh expiry', function (): void {
        $this->actingAs(Canary::admin());
        $archive = documentArchiveCreate();
        $now = CarbonImmutable::parse('2026-10-01 10:00:00');

        $archive->markReady($archive->storagePath(), $now);
        $fresh = DocumentArchive::query()->findOrFail($archive->getKey());

        expect($fresh->status)->toBe(DocumentArchiveStatus::Ready)
            ->and($fresh->path)->toBe($archive->storagePath())
            ->and($fresh->completed_at?->equalTo($now))->toBeTrue()
            ->and($fresh->expires_at->equalTo($now->addDays(7)))->toBeTrue();
    });

    it('marks an archive failed and keeps the expiry of its creation', function (): void {
        $this->actingAs(Canary::admin());
        $archive = documentArchiveCreate();
        $expires = DocumentArchive::query()->findOrFail($archive->getKey())->expires_at;

        $archive->markFailed();
        $fresh = DocumentArchive::query()->findOrFail($archive->getKey());

        expect($fresh->status)->toBe(DocumentArchiveStatus::Failed)
            ->and($fresh->path)->toBeNull()
            ->and($fresh->completed_at)->toBeNull()
            ->and($fresh->expires_at->equalTo($expires))->toBeTrue();
    });

    it('is downloadable only when ready, with a path and strictly before its expiry', function (): void {
        $this->actingAs(Canary::admin());
        $now = CarbonImmutable::parse('2026-10-01 10:00:00');

        $pending = documentArchiveCreate();
        $failed = documentArchiveCreate();
        $failed->markFailed();
        $ready = documentArchiveCreate();
        $ready->markReady($ready->storagePath(), $now);
        $atExpiry = $ready->expires_at;
        $pathless = documentArchiveCreate()->forceFill(['status' => DocumentArchiveStatus::Ready, 'expires_at' => $now->addDay()]);

        expect($pending->isDownloadable($now))->toBeFalse()
            ->and($failed->isDownloadable($now))->toBeFalse()
            ->and($pathless->isDownloadable($now))->toBeFalse()
            ->and($ready->isDownloadable($now))->toBeTrue()
            ->and($ready->isDownloadable($atExpiry->subSecond()))->toBeTrue()
            ->and($ready->isDownloadable($atExpiry))->toBeFalse()
            ->and($ready->isDownloadable($atExpiry->addDay()))->toBeFalse();
    });

    it('reads the retention from the configuration and never returns less than one day', function (): void {
        expect(DocumentArchive::ttlDays())->toBe(7);

        config(['kokpit.documents.archive.ttl_days' => 3]);
        expect(DocumentArchive::ttlDays())->toBe(3);

        config(['kokpit.documents.archive.ttl_days' => 0]);
        expect(DocumentArchive::ttlDays())->toBe(1);

        config(['kokpit.documents.archive.ttl_days' => -5]);
        expect(DocumentArchive::ttlDays())->toBe(1);
    });

    it('uses the configured retention for a new record', function (): void {
        config(['kokpit.documents.archive.ttl_days' => 2]);
        $this->actingAs(Canary::admin());
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'UTC'));

        $archive = documentArchiveCreate()->refresh();

        expect($archive->expires_at->equalTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC')))->toBeTrue();
    });
});
