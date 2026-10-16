<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\RequestDocumentArchive;
use App\Domain\Documents\Jobs\BuildDocumentArchive;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\DocumentArchiveResource;
use App\Filament\Resources\DocumentArchiveResource\Pages\ListDocumentArchives;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The Admin's "ZIP archivy" list and the one checked route that streams a finished archive (DO-02, DO-03, D-08,
 * D-10). Every name and content is fictional.
 */

/**
 * Builds a finished archive of two fictional files for the signed-in Admin through the real request and the
 * real job, so the ZIP on the faked disk is a genuine one.
 */
function archiveListReady(): DocumentArchive
{
    $admin = auth()->user();
    assert($admin instanceof User);

    $documents = app(AttachDocuments::class)->handle($admin, null, [
        DocumentFixtures::upload('first-note-example.txt', implode(' ', ['Fictional', 'first', Str::random(8)])),
        DocumentFixtures::upload('second-note-example.txt', implode(' ', ['Fictional', 'second', Str::random(8)])),
    ]);

    Queue::fake();

    $archive = app(RequestDocumentArchive::class)->handle($admin, $documents->map(static fn ($document): string => $document->getKey())->all());

    $job = new BuildDocumentArchive($archive->getKey());

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (BuildDocumentArchive $job): mixed => app()->call($job->handle(...)));

    return DocumentArchive::query()->findOrFail($archive->getKey());
}

/**
 * A pending archive record of the signed-in Admin that nobody has built.
 */
function archiveListPending(): DocumentArchive
{
    return (new DocumentArchive(['summary' => 'Fiktivní výběr', 'document_ids' => [(string) Str::uuid()], 'document_count' => 1, 'total_bytes' => 10]))
        ->forceFill(['user_id' => auth()->id()]);
}

/**
 * A signed link for any archive id, as the application would sign it for the given user.
 */
function archiveListLink(string $archiveId, string $userId): string
{
    return URL::temporarySignedRoute(DocumentUrl::ARCHIVE_ROUTE, now()->addSeconds((int) config('kokpit.documents.signed_url_ttl_seconds')), [
        'archive' => $archiveId,
        'u' => $userId,
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

it('tracer: the Admin opens the archives list and downloads a ready ZIP through the signed route', function (): void {
    $archive = archiveListReady();

    $this->get(DocumentArchiveResource::getUrl('index'))
        ->assertOk()
        ->assertSee('ZIP archivy')
        ->assertSee('Hotové archivy se uchovávají 7 dní, potom se smažou.');

    $page = Livewire::test(ListDocumentArchives::class)
        ->assertCanSeeTableRecords([$archive])
        ->assertSee($archive->summary)
        ->assertSee('Hotovo')
        ->assertSee('21. 10. 2026 12:00')
        ->assertTableActionVisible('download', $archive)
        ->callTableAction('download', $archive);

    $target = (string) data_get($page->effects, 'redirect');

    expect(parse_url($target, PHP_URL_PATH))->toBe('/admin/files/archives/'.$archive->getKey())
        ->and($target)->toContain('signature=');

    $stored = Storage::disk()->get($archive->path);

    $response = $this->get($target)->assertOk();

    expect($response->streamedContent())->toBe($stored)
        ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('soubory-2026-10-14.zip')
        ->and($response->headers->get('Content-Type'))->toBe('application/zip')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($stored));
});

it('renders no signed link into the list HTML', function (): void {
    archiveListReady();

    $html = (string) $this->get(DocumentArchiveResource::getUrl('index'))->assertOk()->getContent();

    expect(str_contains($html, 'signature='))->toBeFalse()
        ->and(str_contains($html, '/admin/files/archives/'))->toBeFalse();

    $livewire = Livewire::test(ListDocumentArchives::class)->html();

    expect(str_contains($livewire, 'signature='))->toBeFalse();
});

it('names the route filament.admin.files.archive and keeps the bucket out of the link', function (): void {
    $archive = archiveListReady();

    $url = DocumentUrl::archive($archive, $this->admin);

    expect(DocumentUrl::ARCHIVE_ROUTE)->toBe('filament.admin.files.archive')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/admin/files/archives/'.$archive->getKey())
        ->and($url)->not->toContain($archive->path);
});

describe('who gets an archive, and when', function (): void {
    it('answers 404 to another Admin even with a URL signed for that Admin', function (): void {
        $archive = archiveListReady();
        $second = Canary::admin();

        $this->actingAs($second);

        $this->get(archiveListLink($archive->getKey(), $second->getKey()))->assertNotFound();
    });

    it('answers 404 and streams nothing for a pending, a failed and an expired archive', function (): void {
        $ready = archiveListReady();

        $pending = archiveListPending();
        $pending->save();

        $failed = archiveListPending();
        $failed->save();
        $failed->markFailed();

        foreach ([$pending, $failed] as $record) {
            $response = $this->get(archiveListLink($record->getKey(), $this->admin->getKey()));

            expect($response->getStatusCode())->toBe(404);
        }

        // The ZIP exists on the disk, but the retention has run out.
        $this->travelTo($ready->expires_at->addSecond());

        $expired = $this->get(archiveListLink($ready->getKey(), $this->admin->getKey()));

        expect($expired->getStatusCode())->toBe(404)
            ->and(Storage::disk()->exists($ready->path))->toBeTrue();
    });

    it('answers 404 when the object is missing from the disk', function (): void {
        $archive = archiveListReady();

        Storage::disk()->delete($archive->path);

        $this->get(archiveListLink($archive->getKey(), $this->admin->getKey()))->assertNotFound();
    });

    it('answers 403 to an unsigned, tampered, expired-signature or other-user URL, with no referrer', function (): void {
        $archive = archiveListReady();
        $second = Canary::admin();

        $unsigned = route(DocumentUrl::ARCHIVE_ROUTE, ['archive' => $archive->getKey(), 'u' => $this->admin->getKey()]);
        $signed = DocumentUrl::archive($archive, $this->admin);
        $tampered = substr($signed, 0, -1).(str_ends_with($signed, 'a') ? 'b' : 'a');
        $foreign = DocumentUrl::archive($archive, $second);

        foreach ([$unsigned, $tampered, $foreign] as $link) {
            $response = $this->get($link);

            expect($response->getStatusCode())->toBe(403, $link)
                ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
        }

        $this->travelTo(CarbonImmutable::now()->addSeconds((int) config('kokpit.documents.signed_url_ttl_seconds') + 1));

        expect($this->get($signed)->getStatusCode())->toBe(403);
    });

    it('answers 404 to a malformed and to an unknown archive id', function (): void {
        foreach (['not-a-uuid', (string) Str::uuid7()] as $id) {
            $this->get(archiveListLink($id, $this->admin->getKey()))->assertNotFound();
        }
    });

    it('sends a guest to the panel login', function (): void {
        $archive = archiveListReady();
        $link = DocumentUrl::archive($archive, $this->admin);

        auth()->forgetGuards();

        $this->get($link)->assertRedirect(Filament::getLoginUrl());
    });

    it('refuses a Partner the list with 403 and the route with 404 for a link signed for the Partner', function (): void {
        $archive = archiveListReady();
        $partner = Canary::partnerFor(ApiFixtures::client()->id);

        $this->actingAs($partner);

        $this->get(DocumentArchiveResource::getUrl('index'))->assertForbidden();
        Livewire::test(ListDocumentArchives::class)->assertForbidden();

        $this->get(archiveListLink($archive->getKey(), $partner->getKey()))->assertNotFound();
    });
});

describe('the list', function (): void {
    it('shows pending and failed rows without a download and says so in plain words', function (): void {
        $pending = archiveListPending();
        $pending->save();
        $failed = archiveListPending();
        $failed->save();
        $failed->markFailed();

        Livewire::test(ListDocumentArchives::class)
            ->assertCanSeeTableRecords([$pending, $failed])
            ->assertSee('Připravuje se')
            ->assertSee('Selhalo')
            ->assertTableActionHidden('download', $pending)
            ->assertTableActionHidden('download', $failed);
    });

    it('hides the download of an archive that expired', function (): void {
        $archive = archiveListReady();

        $this->travelTo($archive->expires_at->addMinute());

        Livewire::test(ListDocumentArchives::class)
            ->assertCanSeeTableRecords([$archive])
            ->assertTableActionHidden('download', $archive);
    });

    it('answers a click on an archive that lost its file with a toast and no redirect', function (): void {
        $archive = archiveListReady();

        $page = Livewire::test(ListDocumentArchives::class)->assertTableActionVisible('download', $archive);

        Storage::disk()->delete($archive->path);

        $page->callTableAction('download', $archive)
            ->assertNoRedirect()
            ->assertNotified(Notification::make()->danger()->title('Archiv už není k dispozici. Vyberte soubory a požádejte o něj znovu.'));
    });

    it('does not list the archive of another Admin', function (): void {
        $second = Canary::admin();
        $this->actingAs($second);
        $foreign = archiveListReady();

        $this->actingAs($this->admin);
        $own = archiveListReady();

        Livewire::test(ListDocumentArchives::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    });

    it('polls every 5 s while an archive is pending and not otherwise', function (): void {
        archiveListReady();

        expect(Livewire::test(ListDocumentArchives::class)->instance()->getTable()->getPollingInterval())->toBeNull();

        archiveListPending()->save();

        expect(Livewire::test(ListDocumentArchives::class)->instance()->getTable()->getPollingInterval())->toBe('5s');
    });

    it('shows the expiry for a ready row only', function (): void {
        $pending = archiveListPending();
        $pending->save();

        $column = Livewire::test(ListDocumentArchives::class)->instance()->getTable()->getColumn('expires_at');

        expect($column->record($pending)->getState())->toBeNull();
    });

    it('shows the empty state when there is no archive', function (): void {
        Livewire::test(ListDocumentArchives::class)
            ->assertSee('Zatím tu nejsou žádné archivy')
            ->assertSee('Archiv vznikne z vybraných souborů akcí Stáhnout jako ZIP v Souborech.');
    });

    it('has only a list page, no create, no bulk action and no global search', function (): void {
        $table = Livewire::test(ListDocumentArchives::class)->instance()->getTable();

        expect(array_keys(DocumentArchiveResource::getPages()))->toBe(['index'])
            ->and(DocumentArchiveResource::canCreate())->toBeFalse()
            ->and(array_keys($table->getFlatRecordActions()))->toBe(['download'])
            ->and($table->getBulkActions())->toBe([])
            ->and(DocumentArchiveResource::canGloballySearch())->toBeFalse()
            ->and(DocumentArchiveResource::getNavigationSort())->toBe(56)
            ->and(DocumentArchiveResource::getSlug())->toBe('document-archives');
    });

    it('shows the Admin the navigation item', function (): void {
        $labels = collect(Filament::getNavigation())
            ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => (string) $item->getLabel())->all())
            ->all();

        expect($labels)->toContain('ZIP archivy');
    });

    it('shows a Partner no navigation item', function (): void {
        $this->actingAs(Canary::partnerFor(ApiFixtures::client()->id));

        $labels = collect(Filament::getNavigation())
            ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => (string) $item->getLabel())->all())
            ->all();

        expect($labels)->not->toContain('ZIP archivy');
    });
});
