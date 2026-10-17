<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use Carbon\CarbonImmutable;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\QrDecode;

/*
 * The invoice PDF (IN-04, IN-08; D-21, D-23, D-24; ruling R-15-5): the Blade HTML and structural PDF facts need
 * no external tool, Ghostscript text extraction is an extra check that skips without `gs`. Every name, number
 * and amount is fictional.
 */

/**
 * The data-URI images of an HTML document, in order.
 *
 * @return list<string>
 */
function pdfImages(string $html): array
{
    preg_match_all('#<img src="(data:image/png;base64,[^"]+)"#', $html, $matches);

    return $matches[1];
}

/**
 * The font files Dompdf ships with, for a before and after comparison.
 *
 * @return list<string>
 */
function vendorFonts(): array
{
    $files = glob(base_path('vendor/dompdf/dompdf/lib/fonts/*')) ?: [];
    sort($files);

    return $files;
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

it('prints an issued CZK invoice with its parties, lines, total and a payment QR that decodes to its SPAYD string', function (): void {
    $client = InvoiceFixtures::client(['name' => 'Ukázkový odběratel s.r.o.']);
    $issued = InvoiceFixtures::issued($client, [
        InvoiceFixtures::item('Consulting', '2', '500.00'),
        InvoiceFixtures::item('Licence', '1', '250.50', 'ks'),
    ]);

    $html = app(InvoicePdf::class)->html($issued);
    $images = pdfImages($html);

    expect($html)->toContain('FAKTURA')
        ->and($html)->toContain($issued->number)
        ->and($html)->toContain('Example Supplier s.r.o.')
        ->and($html)->toContain('Ukázkový odběratel s.r.o.')
        ->and($html)->toContain('Consulting')
        ->and($html)->toContain('Licence')
        ->and($html)->toContain($issued->total()->format('cs'))
        ->and($html)->toContain('Dodavatel není plátce DPH.')
        ->and($html)->toContain((string) $issued->variable_symbol)
        ->and($html)->not->toContain('DPH %')
        ->and($html)->not->toContain('Datum zdanitelného plnění')
        ->and($images)->toHaveCount(1)
        ->and(QrDecode::payload($images[0]))->toStartWith('SPD*1.0*ACC:CZ')
        ->and(QrDecode::payload($images[0]))->toContain('*AM:1250.50*');
});

it('renders the PDF in Roboto without writing into the vendor directory', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
    $before = vendorFonts();

    $pdf = app(InvoicePdf::class)->render($issued);

    expect($pdf)->toStartWith('%PDF')
        ->and($pdf)->toContain('Roboto')
        ->and(vendorFonts())->toBe($before)
        ->and(storage_path('framework/dompdf/installed-fonts.json'))->toBeFile();
});

it('prints markup and a file reference in a line description as escaped text', function (): void {
    $canary = '<b>canary</b><img src="file:///etc/hostname"><style>body{display:none}</style>';
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item($canary, '1', '100.00')]);

    $html = app(InvoicePdf::class)->html($issued);

    expect($html)->toContain(e($canary))
        ->and($html)->not->toContain('<b>canary</b>')
        ->and($html)->not->toContain('file:///etc/hostname"')
        ->and(app(InvoicePdf::class)->render($issued))->toStartWith('%PDF');
});

it('refuses to render a draft', function (): void {
    $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '1', '100.00')]);

    expect(fn () => app(InvoicePdf::class)->render($draft))->toThrow(LogicException::class)
        ->and(fn () => app(InvoicePdf::class)->html($draft))->toThrow(LogicException::class);
});

it('reads a document it was given as a model, so the Invoice class stays the only source', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

    expect($issued)->toBeInstanceOf(Invoice::class)
        ->and(app(InvoicePdf::class)->fileName($issued))->toBe('faktura-'.$issued->number.'.pdf');
});
