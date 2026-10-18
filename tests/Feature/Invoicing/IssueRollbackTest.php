<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The PDF as a part of issuing (IN-03, IN-04; D-13, D-21, D-23): stored as a document owned by the invoice inside the
 * issue transaction, linked from the Admin-only row, hidden from a Partner and kept when the client is archived, and
 * rolled back with everything else when any step fails. Every name, number and amount is fictional.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function rollbackSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The ids of the media objects stored on the fake disk, as system.
 *
 * @return list<string>
 */
function rollbackStoredObjects(): array
{
    /** @var list<string> $paths */
    $paths = rollbackSystem(static fn (): array => Media::query()->get()->map(static fn (Media $media): string => $media->getPathRelativeToRoot())->all());

    return $paths;
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

describe('the stored PDF', function (): void {
    it('stores one document owned by the invoice and links it from the Admin-only row', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

        $documents = Document::query()->get();

        expect($documents)->toHaveCount(1);

        /** @var Document $document */
        $document = $documents->first();

        expect($document->owner_type)->toBe('invoice')
            ->and($document->owner_id)->toBe($issued->id)
            ->and($document->client_id)->toBe($issued->client_id)
            ->and($document->project_id)->toBeNull()
            ->and($document->visible_to_client)->toBeFalse()
            ->and($document->mime_type)->toBe('application/pdf')
            ->and($document->extension)->toBe('pdf')
            ->and($document->name)->toBe('faktura-'.$issued->number.'.pdf');

        $admin = $issued->admin;

        expect($admin)->toBeInstanceOf(InvoiceAdmin::class)
            ->and($admin->pdf_document_id)->toBe($document->id);

        $objects = rollbackStoredObjects();

        expect($objects)->toHaveCount(1)
            ->and(Storage::disk('local')->get($objects[0]))->toStartWith('%PDF-');
    });

    it('names the file after the kind and the language of the document', function (): void {
        $proforma = InvoiceFixtures::issued(
            InvoiceFixtures::client(['invoice_language' => 'en']),
            [InvoiceFixtures::item('Advance', '1', '5000.00', 'pcs')],
            InvoiceKind::Proforma,
        );

        $czechProforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Advance', '1', '5000.00', 'ks')], InvoiceKind::Proforma);

        $names = Document::query()->pluck('name', 'owner_id');

        expect($names[$proforma->id])->toBe('proforma-'.$proforma->number.'.pdf')
            ->and($names[$czechProforma->id])->toBe('zalohova-faktura-'.$czechProforma->number.'.pdf');
    });

    it('keeps the issued row free of any write after the state flip', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

        // The frozen guard refuses every write on an issued row (plan 10-04): the issue above only succeeded because
        // the PDF step wrote the link on `invoice_admin` and nothing on `invoices`.
        $fresh = rollbackSystem(static fn (): Invoice => Invoice::query()->whereKey($issued->id)->firstOrFail());

        expect($fresh->isIssued())->toBeTrue()
            ->and($fresh->admin?->pdf_document_id)->not->toBeNull();
    });
});

describe('who sees the invoice file', function (): void {
    it('shows a Partner neither the document nor the Admin-only row', function (): void {
        $client = InvoiceFixtures::client();
        $issued = InvoiceFixtures::issued($client);

        $partner = Canary::partnerFor((string) $client->id);
        $this->actingAs($partner);

        $visible = Invoice::query()->whereKey($issued->id)->firstOrFail();

        expect(Document::query()->count())->toBe(0)
            ->and($visible->admin)->toBeNull();
    });

    it('keeps the file in the default Admin query when the client is archived', function (): void {
        $client = InvoiceFixtures::client();
        $issued = InvoiceFixtures::issued($client);

        $this->actingAs(Canary::admin());
        app(ArchiveClient::class)->handle($client);

        $default = Document::query()->pluck('owner_id')->all();

        expect($default)->toBe([$issued->id])
            ->and(Document::query()->withoutArchivedOwners()->pluck('owner_id')->all())->toBe([$issued->id])
            ->and(Document::query()->onlyArchivedOwners()->count())->toBe(0);
    });

    it('creates no document when a Partner tries to issue', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '1000.00')]);
        $partner = Canary::partnerFor((string) $client->id);
        $this->actingAs($partner);

        expect(fn () => app(IssueDocument::class)->handle($partner, $draft))->toThrow(AuthorizationException::class)
            ->and(Document::query()->count())->toBe(0);
    });
});

/**
 * A draft of three finished September entries of a fresh client and project, so the issue also bills time.
 *
 * @return array{draft: Invoice, entries: list<TimeEntry>}
 */
function rollbackTimeDraft(): array
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    $client = InvoiceFixtures::client();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Rollback project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1200',
    ]);

    $entries = [];

    foreach ([['2026-09-10 08:00:00', '2026-09-10 09:00:00'], ['2026-09-11 08:00:00', '2026-09-11 09:30:00']] as [$start, $end]) {
        $factory = TimeEntry::factory()->forProject($project);
        $entries[] = rollbackSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => $admin->id,
            'started_at' => CarbonImmutable::parse($start, 'UTC'),
            'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ])->id));
    }

    $draft = app(CreateDraft::class)->handle($admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
    ]);

    return ['draft' => $draft, 'entries' => $entries];
}

/**
 * The counter rows, in a stable order.
 *
 * @return list<array<string, mixed>>
 */
function rollbackSequences(): array
{
    return DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all();
}

/**
 * Asserts that a refused issue left nothing: the draft is a draft without a number, its entries are reserved for it,
 * the counters are unchanged, and there is no document row, no media row and no stored object.
 *
 * @param  list<array<string, mixed>>  $sequences  the counters before the issue
 * @param  list<TimeEntry>  $entries
 */
function rollbackAssertNothingLeft(Invoice $draft, array $entries, array $sequences): void
{
    $fresh = rollbackSystem(static fn (): Invoice => Invoice::query()->whereKey($draft->id)->firstOrFail());

    expect($fresh->isDraft())->toBeTrue()
        ->and($fresh->number)->toBeNull()
        ->and(rollbackSequences())->toBe($sequences)
        ->and(Document::query()->count())->toBe(0)
        ->and(rollbackSystem(static fn (): int => Media::query()->count()))->toBe(0)
        ->and(rollbackSystem(static fn (): ?string => InvoiceAdmin::query()->where('invoice_id', $draft->id)->value('pdf_document_id')))->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    foreach ($entries as $entry) {
        $stored = rollbackSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));

        expect($stored->billing_state)->toBe(BillingState::Unbilled)
            ->and($stored->invoice_id)->toBe($draft->id);
    }
}

describe('a failure rolls the whole issue back', function (): void {
    it('leaves nothing when the PDF cannot be rendered', function (): void {
        ['draft' => $draft, 'entries' => $entries] = rollbackTimeDraft();
        $sequences = rollbackSequences();

        View::composer('pdf.invoice', static function (): void {
            throw new RuntimeException('The rendering failed.');
        });

        expect(fn () => app(IssueDocument::class)->handle(Canary::admin(), $draft))->toThrow(RuntimeException::class, 'The rendering failed.');

        rollbackAssertNothingLeft($draft, $entries, $sequences);
    });

    it('leaves no row and no object when the storage fails after the object was written', function (): void {
        ['draft' => $draft, 'entries' => $entries] = rollbackTimeDraft();
        $sequences = rollbackSequences();

        Event::listen(MediaHasBeenAddedEvent::class, static function (): void {
            throw new RuntimeException('The storage failed after the write.');
        });

        expect(fn () => app(IssueDocument::class)->handle(Canary::admin(), $draft))->toThrow(RuntimeException::class, 'The storage failed after the write.');

        rollbackAssertNothingLeft($draft, $entries, $sequences);
    });

    it('removes the stored object when a step after the upload fails', function (): void {
        ['draft' => $draft, 'entries' => $entries] = rollbackTimeDraft();
        $sequences = rollbackSequences();

        InvoiceAdmin::saving(static function (InvoiceAdmin $admin): void {
            if ($admin->pdf_document_id !== null) {
                // The object is on the disk and the document row exists at this point.
                expect(Storage::disk('local')->allFiles())->toHaveCount(1);

                throw new RuntimeException('The link failed.');
            }
        });

        expect(fn () => app(IssueDocument::class)->handle(Canary::admin(), $draft))->toThrow(RuntimeException::class, 'The link failed.');

        rollbackAssertNothingLeft($draft, $entries, $sequences);
    });

    it('removes the temporary file of the upload whether the issue succeeds or fails', function (): void {
        $before = glob(sys_get_temp_dir().'/kokpit-generated-*') ?: [];

        InvoiceFixtures::issued(InvoiceFixtures::client());

        expect(glob(sys_get_temp_dir().'/kokpit-generated-*') ?: [])->toBe($before);

        ['draft' => $draft] = rollbackTimeDraft();

        InvoiceAdmin::saving(static function (InvoiceAdmin $admin): void {
            if ($admin->pdf_document_id !== null) {
                throw new RuntimeException('The link failed.');
            }
        });

        expect(fn () => app(IssueDocument::class)->handle(Canary::admin(), $draft))->toThrow(RuntimeException::class);
        expect(glob(sys_get_temp_dir().'/kokpit-generated-*') ?: [])->toBe($before);
    });
});
