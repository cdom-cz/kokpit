<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The issued mode of the editor (IN-13, IN-14; D-04, D-05, D-08, D-09, D-10): the open issued invoice or proforma is
 * corrected in the same editor as a draft, reached from its detail, and the page says what the save did. The Actions
 * are tested in UpdateIssuedDocumentTest; here the screen follows them. Every name and amount is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * The editor of a document.
 */
function iedEditor(Invoice $invoice): Testable
{
    return Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The detail of a document.
 */
function iedDetail(Invoice $invoice): Testable
{
    return Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The repeater keys of the rows in the form, in order.
 *
 * @return list<string>
 */
function iedKeys(Testable $editor): array
{
    return array_map('strval', array_keys($editor->get('data.items')));
}

/**
 * The stored lines of the document in order.
 *
 * @return list<InvoiceItem>
 */
function iedLines(Invoice $invoice): array
{
    return array_values(app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get()->all()));
}

/**
 * The document row, read fresh.
 */
function iedRow(Invoice $invoice): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

/**
 * The stored PDF document id of the invoice.
 */
function iedPdfId(Invoice $invoice): ?string
{
    $id = DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id');

    return is_string($id) ? $id : null;
}

describe('the tracer: from the detail to a corrected invoice', function (): void {
    it('offers "Upravit" on the detail and saves a changed quantity with the message that nothing was sent', function (): void {
        Queue::fake();
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        $oldPdf = iedPdfId($issued);
        $viewUrl = InvoiceResource::getUrl('view', ['record' => $issued]);

        iedDetail($issued)
            ->assertActionVisible('edit')
            ->assertActionHasUrl('edit', InvoiceResource::getUrl('edit', ['record' => $issued]));

        $editor = iedEditor($issued);
        [$key] = iedKeys($editor);

        expect($editor->instance()->getCachedHeaderActions())->toBeEmpty();

        $editor->assertSee('Úprava dokladu '.$issued->number)
            ->assertSee('Vystaveno, lze upravit')
            ->assertSee('Uložit změny')
            ->assertSee('Číslo a druh dokladu se nemění.')
            ->set("data.items.{$key}.quantity", '3')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect($viewUrl)
            ->assertNotified('Změny jsou uložené. PDF bylo vytvořeno znovu, doklad nebyl odeslán.');

        $row = iedRow($issued);

        expect(iedLines($issued)[0]->line_total_minor)->toBe(150000)
            ->and($row->total_minor)->toBe(150000)
            ->and($row->number)->toBe($issued->number)
            ->and(iedPdfId($issued))->not->toBeNull()
            ->and(iedPdfId($issued))->not->toBe($oldPdf)
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Document::query()->where('owner_id', $issued->id)->count()))->toBe(1)
            ->and(DB::table('invoice_emails')->where('invoice_id', $issued->id)->count())->toBe(0);

        Queue::assertNothingPushed();
    });

    it('shows the amount of a line from the server while the quantity is typed', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        $editor = iedEditor($issued);
        [$key] = iedKeys($editor);

        $editor->assertSee(Money::ofMinor(100000, 'CZK')->format('cs'))
            ->set("data.items.{$key}.quantity", '3')
            ->assertSee(Money::ofMinor(150000, 'CZK')->format('cs'));
    });

    it('edits the printed note of an open proforma', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Advance', '1', '800.00')], InvoiceKind::Proforma);

        iedEditor($proforma)
            ->set('data.note', 'Corrected advance note.')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $proforma]));

        expect(iedRow($proforma)->note)->toBe('Corrected advance note.');
    });
});
