<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\RegeneratePdf;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The life of the stored PDF of an issued document (IN-04, DO-03; D-13, D-21, D-23): it is deleted only with the
 * explicit confirmation, the deletion clears the Admin-only link through the foreign key without a write on the
 * issued `invoices` row, and the PDF can be created again from the frozen snapshots when it is missing. Every name,
 * number and amount is fictional.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function regenerateSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored PDF document of an issued invoice, read fresh as system.
 */
function regenerateStoredPdf(Invoice $issued): ?Document
{
    return regenerateSystem(static function () use ($issued): ?Document {
        $id = DB::table('invoice_admin')->where('invoice_id', $issued->id)->value('pdf_document_id');

        return $id === null ? null : Document::query()->whereKey($id)->first();
    });
}

/**
 * The raw `invoices` row, to prove that nothing wrote on it.
 *
 * @return array<string, mixed>
 */
function regenerateInvoiceRow(Invoice $issued): array
{
    return (array) DB::table('invoices')->where('id', $issued->id)->first();
}

/**
 * The paths of the media objects the fake disk holds.
 *
 * @return list<string>
 */
function regenerateStoredObjects(): array
{
    /** @var list<string> $paths */
    $paths = regenerateSystem(static fn (): array => Media::query()->get()->map(static fn (Media $media): string => $media->getPathRelativeToRoot())->all());

    return $paths;
}

/**
 * A small invented PNG upload.
 */
function regenerateUpload(int $seed): UploadedFile
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $seed % 256, ($seed * 7) % 256, ($seed * 13) % 256));

    ob_start();
    imagepng($image);

    return DocumentFixtures::upload('brand-'.$seed.'.png', (string) ob_get_clean());
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

describe('deleting the PDF of an issued document', function (): void {
    it('is refused without the confirmation flag and keeps the row and the object', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        $document = regenerateStoredPdf($issued);
        $objects = regenerateStoredObjects();

        expect($document)->toBeInstanceOf(Document::class)
            ->and($objects)->toHaveCount(1);

        $before = regenerateInvoiceRow($issued);

        expect(fn () => app(DeleteDocument::class)->handle(Canary::admin(), $document))
            ->toThrow(DomainException::class, __('invoices.errors.pdf_unconfirmed'));

        expect(regenerateStoredPdf($issued)?->id)->toBe($document->id)
            ->and(Storage::disk('local')->exists($objects[0]))->toBeTrue()
            ->and(regenerateStoredObjects())->toBe($objects)
            ->and(regenerateInvoiceRow($issued))->toBe($before);
    });

    it('goes through with the flag, clears the link by the foreign key and writes nothing on the invoices row', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        $document = regenerateStoredPdf($issued);
        $objects = regenerateStoredObjects();
        $before = regenerateInvoiceRow($issued);

        app(DeleteDocument::class)->handle(Canary::admin(), $document, true);

        expect(regenerateSystem(static fn (): int => Document::query()->count()))->toBe(0)
            ->and(regenerateStoredObjects())->toBe([])
            ->and(Storage::disk('local')->exists($objects[0]))->toBeFalse()
            ->and(DB::table('invoice_admin')->where('invoice_id', $issued->id)->value('pdf_document_id'))->toBeNull()
            ->and(regenerateInvoiceRow($issued))->toBe($before);
    });

    it('lets another file uploaded to the invoice go without the flag', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        $other = regenerateSystem(static function () use ($issued): Document {
            $admin = Canary::admin();

            return app(AttachDocuments::class)
                ->handle($admin, $issued, [DocumentFixtures::upload('notes.txt', 'Example note.')], false)
                ->firstOrFail();
        });

        app(DeleteDocument::class)->handle(Canary::admin(), $other);

        expect(regenerateSystem(static fn (): bool => Document::query()->whereKey($other->id)->exists()))->toBeFalse()
            ->and(regenerateStoredPdf($issued))->toBeInstanceOf(Document::class);
    });
});

describe('creating the PDF again', function (): void {
    it('stores a new document from the snapshot, links it and prints the same HTML', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '2', '500.00')]);
        $original = app(InvoicePdf::class)->html($issued);
        $document = regenerateStoredPdf($issued);
        $before = regenerateInvoiceRow($issued);

        app(DeleteDocument::class)->handle(Canary::admin(), $document, true);

        expect(regenerateStoredPdf($issued))->toBeNull();

        $fresh = regenerateSystem(static fn (): Invoice => Invoice::query()->whereKey($issued->id)->firstOrFail());
        $created = app(RegeneratePdf::class)->handle(Canary::admin(), $fresh);

        $linked = regenerateStoredPdf($issued);
        $objects = regenerateStoredObjects();

        expect($linked?->id)->toBe($created->id)
            ->and($created->id)->not->toBe($document->id)
            ->and($created->owner_type)->toBe('invoice')
            ->and($created->owner_id)->toBe($issued->id)
            ->and($created->name)->toBe('faktura-'.$issued->number.'.pdf')
            ->and($created->visible_to_client)->toBeFalse()
            ->and($objects)->toHaveCount(1)
            ->and(Storage::disk('local')->get($objects[0]))->toStartWith('%PDF-')
            ->and(app(InvoicePdf::class)->html($fresh))->toBe($original)
            ->and(regenerateInvoiceRow($issued))->toBe($before);
    });
});
