<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The deletion screens for the PDF of an issued document (DO-03, D-21; ruling R-36-1): the single delete asks for an
 * explicit tick before "Smazat PDF" is enabled, the bulk delete skips such PDFs and says so, and any other file of an
 * invoice deletes as usual. Every name, number and amount is fictional.
 */

/**
 * The central listing as the Admin sees it.
 */
function pdfDeletionListing(): Testable
{
    return Livewire::test(ListDocuments::class);
}

/**
 * The stored PDF document of an issued invoice.
 */
function pdfDeletionPdf(Invoice $issued): Document
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($issued): Document {
        $id = DB::table('invoice_admin')->where('invoice_id', $issued->id)->value('pdf_document_id');

        return Document::query()->whereKey($id)->firstOrFail();
    });
}

/**
 * Another file the Admin uploaded to the invoice, or to nothing.
 */
function pdfDeletionFile(string $name, ?Invoice $owner = null): Document
{
    return app(AttachDocuments::class)
        ->handle(Canary::admin(), $owner, [DocumentFixtures::upload($name, 'Example content.')], false)
        ->firstOrFail();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('shows the extended confirmation for the PDF of an issued document with the submit disabled until the tick', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
    $pdf = pdfDeletionPdf($issued);

    $listing = pdfDeletionListing()->mountAction(TestAction::make('delete')->table($pdf));
    $mounted = $listing->instance()->getMountedAction();

    expect((string) $mounted?->getModalHeading())->toBe('Smazat PDF vystaveného dokladu?')
        ->and((string) $mounted?->getModalDescription())->toBe(__('documents.actions.delete_issued_pdf.description', ['name' => $pdf->name, 'number' => $issued->number]))
        ->and($mounted?->getModalCancelActionLabel())->toBe('Ponechat PDF')
        ->and($mounted?->getModalSubmitAction()?->getLabel())->toBe('Smazat PDF')
        ->and($mounted?->getModalSubmitAction()?->isDisabled())->toBeTrue()
        ->and(Document::query()->whereKey($pdf->id)->exists())->toBeTrue();

    $listing->fillForm(['confirmIssuedPdf' => true], 'mountedActionSchema0');

    expect($listing->instance()->getMountedAction()?->getModalSubmitAction()?->isDisabled())->toBeFalse();
});

it('refuses the submit without the tick and keeps the PDF', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
    $pdf = pdfDeletionPdf($issued);

    pdfDeletionListing()
        ->callAction(TestAction::make('delete')->table($pdf), ['confirmIssuedPdf' => false])
        ->assertHasActionErrors(['confirmIssuedPdf']);

    expect(Document::query()->whereKey($pdf->id)->exists())->toBeTrue()
        ->and(pdfDeletionPdf($issued)->id)->toBe($pdf->id);
});

it('deletes the PDF with the tick, clears the link and says so', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
    $pdf = pdfDeletionPdf($issued);

    pdfDeletionListing()
        ->callAction(TestAction::make('delete')->table($pdf), ['confirmIssuedPdf' => true])
        ->assertHasNoActionErrors()
        ->assertNotified(__('documents.actions.delete_issued_pdf.deleted'));

    expect(Document::query()->whereKey($pdf->id)->exists())->toBeFalse()
        ->and(DB::table('invoice_admin')->where('invoice_id', $issued->id)->value('pdf_document_id'))->toBeNull();
});

it('asks nothing extra for another file uploaded to the invoice', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
    $other = pdfDeletionFile('invoice-note-example.txt', $issued);

    $listing = pdfDeletionListing()->mountAction(TestAction::make('delete')->table($other));

    expect((string) $listing->instance()->getMountedAction()?->getModalHeading())->toBe(__('documents.actions.delete_heading'));

    $listing->callMountedAction()->assertNotified(__('documents.notifications.deleted'));

    expect(Document::query()->whereKey($other->id)->exists())->toBeFalse()
        ->and(pdfDeletionPdf($issued)->id)->not->toBeNull();
});

it('skips the PDF of an issued document in a bulk delete, deletes the others and reports the skipped one', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
    $pdf = pdfDeletionPdf($issued);
    $one = pdfDeletionFile('bulk-one-example.txt');
    $two = pdfDeletionFile('bulk-two-example.txt');

    pdfDeletionListing()
        ->mountTableBulkAction('bulkDelete', [$pdf->getKey(), $one->getKey(), $two->getKey()])
        ->callMountedTableBulkAction()
        ->assertNotified(__('documents.notifications.bulk_deleted', ['deleted' => 2, 'failed' => 0]));

    expect(Document::query()->whereKey($pdf->id)->exists())->toBeTrue()
        ->and(Document::query()->whereKey([$one->id, $two->id])->exists())->toBeFalse()
        ->and(pdfDeletionPdf($issued)->id)->toBe($pdf->id);
});

it('tells the Admin how many PDFs of issued documents a bulk delete skipped', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
    $pdf = pdfDeletionPdf($issued);
    $one = pdfDeletionFile('bulk-body-example.txt');

    pdfDeletionListing()
        ->mountTableBulkAction('bulkDelete', [$pdf->getKey(), $one->getKey()])
        ->callMountedTableBulkAction()
        ->assertNotified(
            Notification::make()
                ->warning()
                ->title(__('documents.notifications.bulk_deleted', ['deleted' => 1, 'failed' => 0]))
                ->body(__('documents.actions.delete_issued_pdf.bulk_skipped', ['skipped' => 1])),
        );

    expect(Document::query()->whereKey($pdf->id)->exists())->toBeTrue();
});
