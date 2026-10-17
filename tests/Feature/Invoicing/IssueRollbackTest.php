<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
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
