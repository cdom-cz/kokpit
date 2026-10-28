<?php

declare(strict_types=1);

use App\Domain\Invoicing\Pdf\ClosedDompdf;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Invoicing\Pdf\PdfParts;
use App\Domain\Settings\DocumentImage;

/*
 * The closed Dompdf engine shared by every PDF of the application (P15-08, ruling R-15-03-1; threat T-15-42):
 * the safety options are pinned here, so an extraction or a later edit cannot quietly open remote access, inline
 * PHP or JavaScript, or a wider chroot. Every text is fictional.
 */

describe('the closed engine', function (): void {
    it('runs Dompdf with remote access, PHP and JavaScript off', function (): void {
        $options = app(ClosedDompdf::class)->options();

        expect($options->getIsRemoteEnabled())->toBeFalse()
            ->and($options->getIsPhpEnabled())->toBeFalse()
            ->and($options->getIsJavascriptEnabled())->toBeFalse();
    });

    it('limits the chroot to the PDF views and the bundled fonts', function (): void {
        $options = app(ClosedDompdf::class)->options();

        expect($options->getChroot())->toBe([resource_path('views/pdf'), resource_path('fonts')]);
    });

    it('keeps the DejaVu Sans default font and the installed fonts under storage', function (): void {
        $options = app(ClosedDompdf::class)->options();

        expect($options->getDefaultFont())->toBe('DejaVu Sans')
            ->and($options->getFontDir())->toBe(storage_path('framework/dompdf'))
            ->and($options->getFontCache())->toBe(storage_path('framework/dompdf'))
            ->and($options->getTempDir())->toBe(storage_path('framework/dompdf'));
    });

    it('renders a minimal escaped page into PDF bytes', function (): void {
        $html = '<html><body><p>'.e('Example <b>text</b> & more').'</p></body></html>';

        expect(app(ClosedDompdf::class)->render($html))->toStartWith('%PDF-');
    });

    it('calls the page script once the layout is known', function (): void {
        $calls = 0;
        $script = function () use (&$calls): void {
            $calls++;
        };

        app(ClosedDompdf::class)->render('<html><body><p>Example page</p></body></html>', $script);

        expect($calls)->toBeGreaterThanOrEqual(1);
    });

    it('names the bundled fonts by file URL', function (): void {
        $engine = app(ClosedDompdf::class);

        expect($engine->fontUrl('normal'))->toStartWith('file://')->toEndWith('Roboto-Regular.ttf')
            ->and($engine->fontUrl('bold'))->toStartWith('file://')->toEndWith('Roboto-Bold.ttf');
    });

    it('shares one font lock between the invoice renderer and the engine', function (): void {
        expect(InvoicePdf::FONT_LOCK)->toBe(ClosedDompdf::FONT_LOCK);
    });
});

/*
 * The parts a receipt shares with the invoice (ruling R-15-03-2, D-11): the party block of a snapshot, the footer
 * lines, a sized image of the appearance snapshot and a trimmed text or null.
 */

/**
 * A fictional party snapshot; the keys are the ones the issued document freezes.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pdfEngineSnapshot(array $overrides = []): array
{
    return [
        'name' => 'Example Client s.r.o.',
        'street' => 'Example Street 1',
        'postal_code' => '110 00',
        'city' => 'Example City',
        'country' => 'CZ',
        'company_id' => '12345678',
        'vat_id' => null,
        'registration_note' => 'Registered at the example court',
        'email' => exampleEmail('billing'),
        'phone' => '+420 000 000 000',
        'website' => 'https://example.com',
        ...$overrides,
    ];
}

/**
 * A translator that echoes the key, so the test sees which words the block asked for.
 */
function pdfEngineTranslator(): Closure
{
    return static fn (string $key, array $replace = []): string => $key;
}

describe('the PDF parts', function (): void {
    it('trims a text and gives null for blank or non-string values', function (): void {
        expect(PdfParts::text('  Example note  '))->toBe('Example note')
            ->and(PdfParts::text(''))->toBeNull()
            ->and(PdfParts::text("  \n "))->toBeNull()
            ->and(PdfParts::text(null))->toBeNull()
            ->and(PdfParts::text(12))->toBeNull()
            ->and(PdfParts::text(['Example']))->toBeNull();
    });

    it('splits a footer into its non-empty trimmed lines', function (): void {
        expect(PdfParts::footer("  Example line one \n\n Example line two\r\n   \nExample line three"))
            ->toBe(['Example line one', 'Example line two', 'Example line three'])
            ->and(PdfParts::footer(null))->toBe([]);
    });

    it('builds the party block of a snapshot without a country line for CZ', function (): void {
        $party = PdfParts::party('Customer', pdfEngineSnapshot(), pdfEngineTranslator(), null);

        expect($party['label'])->toBe('Customer')
            ->and($party['name'])->toBe('Example Client s.r.o.')
            ->and($party['lines'])->toBe(['Example Street 1', '110 00 Example City'])
            ->and($party['ids'])->toBe([['key' => 'company_id', 'value' => '12345678']])
            ->and($party['vatNote'])->toBeNull();
    });

    it('adds a country line for a foreign party and the VAT id when present', function (): void {
        $party = PdfParts::party('Customer', pdfEngineSnapshot(['country' => 'SK', 'vat_id' => 'SK0000000000']), pdfEngineTranslator(), null);

        expect($party['lines'])->toBe(['Example Street 1', '110 00 Example City', 'SK'])
            ->and($party['ids'])->toBe([
                ['key' => 'company_id', 'value' => '12345678'],
                ['key' => 'vat_id', 'value' => 'SK0000000000'],
            ]);
    });

    it('prints the registration note and never the contact lines of a snapshot', function (): void {
        $snapshot = pdfEngineSnapshot();
        $supplier = PdfParts::party('Supplier', $snapshot, pdfEngineTranslator(), 'Not a VAT payer');
        $customer = PdfParts::party('Customer', $snapshot, pdfEngineTranslator(), null);

        expect($snapshot['email'])->not->toBeNull()
            ->and($snapshot['phone'])->not->toBeNull()
            ->and($snapshot['website'])->not->toBeNull()
            ->and($supplier['notes'])->toBe(['Registered at the example court'])
            ->and($customer['notes'])->toBe(['Registered at the example court'])
            ->and($supplier['vatNote'])->toBe('Not a VAT payer');
    });

    it('skips the missing parts of a sparse snapshot', function (): void {
        $party = PdfParts::party('Customer', ['name' => '  Example Sparse  '], pdfEngineTranslator(), null);

        expect($party['name'])->toBe('Example Sparse')
            ->and($party['lines'])->toBe([])
            ->and($party['ids'])->toBe([])
            ->and($party['notes'])->toBe([]);
    });

    it('gives no image for a reference without a path or a hash', function (): void {
        $images = app(DocumentImage::class);

        expect(PdfParts::image($images, null, 160, 40))->toBeNull()
            ->and(PdfParts::image($images, 'logo.png', 160, 40))->toBeNull()
            ->and(PdfParts::image($images, ['path' => 'example/logo.png'], 160, 40))->toBeNull()
            ->and(PdfParts::image($images, ['sha256' => str_repeat('0', 64)], 160, 40))->toBeNull();
    });
});
