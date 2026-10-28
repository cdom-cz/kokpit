<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Pdf\DocumentPalette;
use App\Domain\Invoicing\Pdf\InvoicePageDecoration;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Invoicing\Pdf\InvoicePdfData;
use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;
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

it('prints the quantity alone for a line without a unit and the quantity with its unit otherwise', function (InvoiceKind $kind): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [
        InvoiceFixtures::item('Example flat fee', '3', '100.00', ''),
        InvoiceFixtures::item('Example consulting', '2', '500.00'),
    ], $kind);

    $html = app(InvoicePdf::class)->html($issued);
    $lines = $issued->items->values();

    expect($html)->toContain('<td class="quantity">3</td>')
        ->and($html)->toContain('<td class="quantity">2 h</td>')
        ->and($lines[0]->unit)->toBeNull()
        ->and($lines[0]->printedQuantity('cs'))->toBe('3')
        ->and($lines[1]->printedQuantity('cs'))->toBe('2 h')
        ->and((new InvoiceItem)->forceFill(['quantity' => '1.5000', 'unit' => null])->printedQuantity('en'))->toBe('1.5');
})->with([
    'invoice' => [InvoiceKind::Invoice],
    'proforma' => [InvoiceKind::Proforma],
]);

it('lays the payment facts out two to a row in a compact box', function (InvoiceKind $kind): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '1000.00')], $kind);

    $facts = InvoicePdfData::fromInvoice($issued)->payment['facts'] ?? [];
    $html = app(InvoicePdf::class)->html($issued);
    $images = pdfImages($html);

    expect(count($facts))->toBeGreaterThanOrEqual(4)
        ->and(preg_match('#<table class="facts">(.*?)</table>#s', $html, $match))->toBe(1);

    $table = $match[1];

    expect(substr_count($table, '<tr'))->toBe(intdiv(count($facts) + 1, 2))
        ->and(substr_count($table, '<tr'))->toBeLessThan(count($facts))
        ->and($images)->toHaveCount(1)
        ->and($html)->toContain('width: 72pt; height: 72pt')
        ->and(QrDecode::payload($images[0]))->toStartWith('SPD*1.0*ACC:CZ');

    foreach ($facts as $fact) {
        expect($table)->toContain(e($fact['key']))
            ->and($table)->toContain(e($fact['value']));
    }
})->with([
    'invoice' => [InvoiceKind::Invoice],
    'proforma' => [InvoiceKind::Proforma],
]);

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

/**
 * A small invented PNG upload; the colour is drawn from the seed so two seeds are two different images.
 */
function pdfUpload(int $seed): UploadedFile
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $seed % 256, ($seed * 7) % 256, ($seed * 13) % 256));

    ob_start();
    imagepng($image);

    return DocumentFixtures::upload('brand-'.$seed.'.png', (string) ob_get_clean());
}

/**
 * Writes settings as the system (the settings model is closed to a guest) and drops the scoped instances.
 *
 * @param  Closure(): void  $write
 */
function pdfSettings(Closure $write): void
{
    app(PartnerContext::class)->runAsSystem($write);
    app()->forgetScopedInstances();
}

describe('the copy', function (): void {
    it('has the same keys in Czech and English', function (): void {
        $czech = require lang_path('cs/invoice_pdf.php');
        $english = require lang_path('en/invoice_pdf.php');

        expect(array_keys(Arr::dot($czech)))->toBe(array_keys(Arr::dot($english)));
    });

    it('carries every string of the UI-SPEC PDF table exactly', function (string $key, string $czech, string $english): void {
        expect(trans('invoice_pdf.'.$key, [], 'cs'))->toBe($czech)
            ->and(trans('invoice_pdf.'.$key, [], 'en'))->toBe($english);
    })->with([
        'invoice heading' => ['headings.invoice', 'FAKTURA', 'INVOICE'],
        'proforma heading' => ['headings.proforma', 'ZÁLOHOVÁ FAKTURA', 'PROFORMA INVOICE'],
        'credit note heading' => ['headings.credit_note', 'DOBROPIS', 'CREDIT NOTE'],
        'supplier' => ['supplier', 'DODAVATEL', 'SUPPLIER'],
        'customer' => ['customer', 'ODBĚRATEL', 'CUSTOMER'],
        'company id' => ['company_id', 'IČO', 'Company ID'],
        'vat id' => ['vat_id', 'DIČ', 'VAT ID'],
        'vat note' => ['not_vat_payer', 'Dodavatel není plátce DPH.', 'The supplier is not a VAT payer.'],
        'issue date' => ['issue_date', 'Datum vystavení', 'Issue date'],
        'taxable supply date' => ['taxable_supply_date', 'Datum zdanitelného plnění', 'Taxable supply date'],
        'due date' => ['due_date', 'Datum splatnosti', 'Due date'],
        'quantity' => ['quantity', 'Množství', 'Qty'],
        'unit price' => ['unit_price', 'Jednotková cena', 'Unit price'],
        'total' => ['total', 'Celkem', 'Total'],
        'vat rate' => ['vat_rate', 'DPH %', 'VAT %'],
        'amount due bar' => ['amount_due_bar', 'K ÚHRADĚ', 'AMOUNT DUE'],
        'credit total bar' => ['credit_total_bar', 'DOBROPIS CELKEM', 'CREDIT TOTAL'],
        'payment sentence' => ['payment_sentence', 'Prosíme o úhradu částky :amount na účet :account s variabilním symbolem :vs do :due.', 'Please pay :amount to account :account with variable symbol :vs by :due.'],
        'account' => ['account', 'Číslo účtu', 'Account'],
        'iban' => ['iban', 'IBAN', 'IBAN'],
        'bic' => ['bic', 'BIC', 'BIC'],
        'variable symbol' => ['variable_symbol', 'Variabilní symbol', 'Variable symbol'],
        'payment method' => ['payment_method', 'Způsob úhrady', 'Payment method'],
        'bank transfer' => ['bank_transfer', 'Převodem', 'Bank transfer'],
        'amount due' => ['amount_due', 'K úhradě', 'Amount due'],
        'proforma note' => ['proforma_note', 'Nejde o daňový doklad.', 'This is not a tax document.'],
        'corrects' => ['corrects_document', 'Opravuje doklad č. :number', 'Corrects document no. :number'],
        'credit note notice' => ['credit_note_no_payment', 'Dobropis neobsahuje platební údaje. Případné vrácení platby proběhne samostatně.', 'This credit note carries no payment details. Any refund is made separately.'],
        'page' => ['page', 'Strana :n / :total', 'Page :n / :total'],
        'invoice file' => ['file_name.invoice', 'faktura-:number.pdf', 'invoice-:number.pdf'],
        'proforma file' => ['file_name.proforma', 'zalohova-faktura-:number.pdf', 'proforma-:number.pdf'],
        'credit note file' => ['file_name.credit_note', 'dobropis-:number.pdf', 'credit-note-:number.pdf'],
    ]);
});

it('prints a document issued in English with English words, ISO dates and English money', function (): void {
    $client = InvoiceFixtures::client(['invoice_language' => 'en', 'name' => 'Sample Customer Ltd.']);
    $issued = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Consulting', '1', '12000.00')]);

    $html = app(InvoicePdf::class)->html($issued);
    $total = Money::ofMinor(1200000, 'CZK')->format('en');

    expect($html)->toContain('lang="en"')
        ->and($html)->toContain('INVOICE')
        ->and($html)->toContain('SUPPLIER')
        ->and($html)->toContain('CUSTOMER')
        ->and($html)->toContain('Issue date')
        ->and($html)->toContain('Due date')
        ->and($html)->toContain('AMOUNT DUE')
        ->and($html)->toContain('Please pay')
        ->and($html)->toContain('The supplier is not a VAT payer.')
        ->and($html)->toContain('2026-10-14')
        ->and($html)->toContain('2026-10-28')
        ->and($html)->toContain($total)
        ->and($total)->toStartWith('CZK')
        ->and($html)->not->toContain('FAKTURA')
        ->and(app(InvoicePdf::class)->fileName($issued))->toBe('invoice-'.$issued->number.'.pdf');
});

it('prints Czech dates and money in a Czech document', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '12000.00')]);

    $html = app(InvoicePdf::class)->html($issued);

    expect($html)->toContain('14. 10. 2026')
        ->and($html)->toContain('28. 10. 2026')
        ->and($html)->toContain(Money::ofMinor(1200000, 'CZK')->format('cs'))
        ->and($html)->toContain('Prosíme o úhradu částky');
});

describe('the snapshot', function (): void {
    it('prints the same document whatever changes after the issue', function (): void {
        $logo = app(DocumentImage::class)->store(pdfUpload(40), 'logo');
        $signature = app(DocumentImage::class)->store(pdfUpload(80), 'signature');
        pdfSettings(function () use ($logo, $signature): void {
            $supplier = app(SupplierSettings::class);
            $supplier->logo_path = $logo;
            $supplier->signature_path = $signature;
            $supplier->save();

            $invoicing = app(InvoicingSettings::class);
            $invoicing->document_accent_color = '#1d4ed8';
            $invoicing->document_footer = 'Registered in the example register.';
            $invoicing->save();
        });

        $client = InvoiceFixtures::client(['name' => 'Původní odběratel s.r.o.']);
        $issued = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Consulting', '2', '500.00')]);
        $pdf = app(InvoicePdf::class);
        $before = $pdf->html($issued);

        pdfSettings(function () use ($client): void {
            Client::query()->whereKey($client->id)->update(['name' => 'Přejmenovaný odběratel a.s.', 'street' => 'Changed street 9']);

            $supplier = app(SupplierSettings::class);
            $supplier->company_name = 'Renamed Supplier a.s.';
            $supplier->logo_path = app(DocumentImage::class)->store(pdfUpload(150), 'logo');
            $supplier->signature_path = app(DocumentImage::class)->store(pdfUpload(200), 'signature');
            $supplier->save();

            $invoicing = app(InvoicingSettings::class);
            $invoicing->document_accent_color = '#00aa00';
            $invoicing->document_footer = 'A different footer.';
            $invoicing->save();
        });
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');

        $fresh = Invoice::query()->whereKey($issued->id)->firstOrFail();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $after = $pdf->html($fresh);

        expect($after)->toBe($before)
            ->and($after)->toContain('Původní odběratel s.r.o.')
            ->and($after)->toContain('Example Supplier s.r.o.')
            ->and($after)->toContain('#1d4ed8')
            ->and($after)->toContain('Registered in the example register.')
            ->and($after)->not->toContain('Renamed Supplier')
            ->and($after)->not->toContain('#00aa00')
            ->and(pdfImages($after))->toHaveCount(3)
            ->and($after)->toContain(base64_encode((string) Storage::disk('local')->get($logo)))
            ->and($after)->toContain(base64_encode((string) Storage::disk('local')->get($signature)));

        // It read the document and its lines only: no client, settings, exchange-rate or Admin-only query. The one
        // exception is the card switch and the frozen pay token of the card button (Phase 11 D-01, Phase 14 D-03), a
        // two-column read of the Admin row.
        $payTokenReads = array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select "card_payment_enabled", "pay_token" from "invoice_admin"'));

        expect($payTokenReads)->toHaveCount(1)
            ->and(implode("\n", array_diff($queries, $payTokenReads)))->not->toMatch('/"(clients|settings|exchange_rates|invoice_admin)"/');
    });

    it('fails instead of printing another image when the stored logo object changed or is gone', function (): void {
        $logo = app(DocumentImage::class)->store(pdfUpload(40), 'logo');
        pdfSettings(function () use ($logo): void {
            $supplier = app(SupplierSettings::class);
            $supplier->logo_path = $logo;
            $supplier->save();
        });
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        $pdf = app(InvoicePdf::class);

        expect($pdf->html($issued))->toContain('data:image/png;base64,');

        Storage::disk('local')->put($logo, 'other bytes');

        expect(fn () => $pdf->html($issued))->toThrow(UnexpectedValueException::class, 'document image mismatch')
            ->and(fn () => $pdf->render($issued))->toThrow(UnexpectedValueException::class, 'document image mismatch');

        Storage::disk('local')->delete($logo);

        expect(fn () => $pdf->html($issued))->toThrow(UnexpectedValueException::class, 'document image mismatch')
            ->and(fn () => $pdf->render($issued))->toThrow(UnexpectedValueException::class, 'document image mismatch');
    });
});

describe('the page furniture', function (): void {
    /**
     * Runs the decoration of one page against a recording canvas of A4 size.
     *
     * @return array{rectangles: list<array{x: float, y: float, width: float, height: float, color: array<int, float>}>, lines: list<array{x1: float, y1: float, x2: float, width: float}>, texts: list<array{x: float, y: float, text: string, size: float}>}
     */
    function pdfDecorate(int $page, int $pages, string $accent = '#ff6f00'): array
    {
        $drawn = ['rectangles' => [], 'lines' => [], 'texts' => []];
        $canvas = Mockery::mock(Canvas::class);
        $canvas->shouldReceive('get_width')->andReturn(595.28);
        $canvas->shouldReceive('get_height')->andReturn(841.89);
        $canvas->shouldReceive('filled_rectangle')->andReturnUsing(function ($x, $y, $width, $height, $color) use (&$drawn): void {
            $drawn['rectangles'][] = ['x' => (float) $x, 'y' => (float) $y, 'width' => (float) $width, 'height' => (float) $height, 'color' => $color];
        });
        $canvas->shouldReceive('line')->andReturnUsing(function ($x1, $y1, $x2, $y2, $color, $width) use (&$drawn): void {
            $drawn['lines'][] = ['x1' => (float) $x1, 'y1' => (float) $y1, 'x2' => (float) $x2, 'width' => (float) $width];
        });
        $canvas->shouldReceive('text')->andReturnUsing(function ($x, $y, $text, $font, $size) use (&$drawn): void {
            $drawn['texts'][] = ['x' => (float) $x, 'y' => (float) $y, 'text' => (string) $text, 'size' => (float) $size];
        });

        (new InvoicePageDecoration(DocumentPalette::fromAccent($accent), 'Strana {n} / {total}'))($page, $pages, $canvas, (new Dompdf)->getFontMetrics());

        return $drawn;
    }

    it('draws a 7 pt accent stripe at the top and at the bottom edge of every page', function (int $page): void {
        $drawn = pdfDecorate($page, 3);

        expect($drawn['rectangles'])->toHaveCount(2)
            ->and($drawn['rectangles'][0])->toMatchArray(['x' => 0.0, 'y' => 0.0, 'width' => 595.28, 'height' => 7.0])
            ->and($drawn['rectangles'][1])->toMatchArray(['x' => 0.0, 'width' => 595.28, 'height' => 7.0])
            ->and($drawn['rectangles'][1]['y'] + 7.0)->toEqualWithDelta(841.89, 0.001)
            ->and($drawn['rectangles'][0]['color'])->toEqualWithDelta([1.0, 111 / 255, 0.0], 0.001)
            ->and($drawn['rectangles'][1]['color'])->toEqualWithDelta([1.0, 111 / 255, 0.0], 0.001);
    })->with([1, 2, 3]);

    it('draws the hairline 44 pt above the bottom edge and the right-aligned page label', function (): void {
        $drawn = pdfDecorate(2, 3);
        $label = $drawn['texts'][0];
        $metrics = (new Dompdf)->getFontMetrics();
        $width = $metrics->getTextWidth($label['text'], $metrics->getFont('Roboto') ?? $metrics->getFont('DejaVu Sans'), 8);

        expect($drawn['lines'])->toHaveCount(1)
            ->and($drawn['lines'][0]['y1'])->toEqualWithDelta(841.89 - 44.0, 0.001)
            ->and($drawn['lines'][0]['width'])->toBe(0.75)
            ->and($drawn['texts'])->toHaveCount(1)
            ->and($label['text'])->toBe('Strana 2 / 3')
            ->and($label['size'])->toBe(8.0)
            ->and($label['x'] + $width)->toEqualWithDelta(595.28 - 36.0, 0.01)
            // The bottom of the 8 pt text sits 24 pt above the bottom edge, below the hairline.
            ->and($label['y'] + 8.0)->toEqualWithDelta(841.89 - 24.0, 0.001);
    });

    it('spans several pages for a 60-line invoice and numbers every page', function (): void {
        $items = [];

        for ($line = 1; $line <= 60; $line++) {
            $items[] = InvoiceFixtures::item('Line '.$line, '1', '10.00');
        }

        $pdf = app(InvoicePdf::class)->render(InvoiceFixtures::issued(InvoiceFixtures::client(), $items));
        $pages = PdfText::pageCount($pdf);

        expect($pages)->toBeGreaterThan(1);

        $text = PdfText::flat($pdf);

        if ($text === null) {
            $this->markTestSkipped('Ghostscript (gs) is not installed, so the page labels are not read back from the PDF.');
        }

        foreach (range(1, $pages) as $page) {
            expect($text)->toContain('Strana '.$page.' / '.$pages);
        }
    });
});

describe('the text of the PDF', function (): void {
    it('keeps every Czech diacritic intact in the extracted text', function (): void {
        $client = InvoiceFixtures::client(['name' => 'Zálohová Částečně s.r.o.']);
        $issued = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Příliš žluťoučký kůň úpěl ďábelské ódy', '1', '100.00')]);

        $text = PdfText::flat(app(InvoicePdf::class)->render($issued));

        if ($text === null) {
            $this->markTestSkipped('Ghostscript (gs) is not installed, so the PDF text cannot be read back.');
        }

        expect($text)->toContain('Příliš žluťoučký kůň úpěl ďábelské ódy')
            ->and($text)->toContain('Zálohová Částečně s.r.o.')
            ->and($text)->toContain('FAKTURA')
            ->and($text)->toContain('ODBĚRATEL')
            ->and($text)->toContain('Dodavatel není plátce DPH.');
    });

    it('counts one page for a one-line invoice and several for sixty lines', function (): void {
        $client = InvoiceFixtures::client();
        $single = InvoiceFixtures::issued($client, [InvoiceFixtures::item('One line', '1', '10.00')]);
        $items = [];

        for ($line = 1; $line <= 60; $line++) {
            $items[] = InvoiceFixtures::item('Line '.$line, '1', '10.00');
        }

        $many = InvoiceFixtures::issued($client, $items);

        expect(PdfText::pageCount(app(InvoicePdf::class)->render($single)))->toBe(1)
            ->and(PdfText::pageCount(app(InvoicePdf::class)->render($many)))->toBeGreaterThanOrEqual(2);
    });

    it('prints the payment box without a QR for a foreign-currency document and none for a credit note', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $client = InvoiceFixtures::client(['currency' => 'EUR']);
        $foreign = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Consulting', '1', '100.00')]);
        $credit = InvoiceFixtures::stornoed(InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '100.00')]));
        $pdf = app(InvoicePdf::class);

        $foreignHtml = $pdf->html($foreign);
        $creditHtml = $pdf->html($credit);

        expect(pdfImages($foreignHtml))->toBe([])
            ->and($foreignHtml)->toContain('Prosíme o úhradu částky')
            ->and($foreignHtml)->toContain('IBAN')
            ->and($creditHtml)->toContain('DOBROPIS')
            ->and($creditHtml)->toContain('DOBROPIS CELKEM')
            ->and($creditHtml)->toContain('Dobropis neobsahuje platební údaje.')
            ->and($creditHtml)->toContain('Opravuje doklad č.')
            ->and($creditHtml)->not->toContain('Prosíme o úhradu částky')
            ->and($creditHtml)->not->toContain('Datum splatnosti')
            ->and(pdfImages($creditHtml))->toBe([]);
    });
});

describe('the fonts', function (): void {
    /**
     * Runs the callback with the storage path pointing at an empty temporary directory, so the font index of the
     * application is neither read nor changed, and gives the directory back afterwards.
     *
     * @param  Closure(string): void  $callback
     */
    function pdfWithFreshFonts(Closure $callback): void
    {
        $original = app()->storagePath();
        $directory = sys_get_temp_dir().'/kokpit-fonts-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory.'/framework');

        try {
            app()->useStoragePath($directory);
            $callback($directory.'/framework/dompdf');
        } finally {
            app()->useStoragePath($original);
            File::deleteDirectory($directory);
        }
    }

    it('installs Roboto on the first render and keeps one consistent index when it is lost in between', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

        pdfWithFreshFonts(function (string $fontDir) use ($issued): void {
            $pdf = app(InvoicePdf::class);
            $index = $fontDir.'/installed-fonts.json';

            expect($pdf->render($issued))->toStartWith('%PDF')
                ->and($index)->toBeFile();

            // A second request that lost the index (the race of two first renders) installs again.
            unlink($index);
            $files = glob($fontDir.'/roboto_*.ttf') ?: [];

            expect($pdf->render($issued))->toStartWith('%PDF')
                ->and(glob($fontDir.'/roboto_*.ttf'))->toBe($files)
                ->and($files)->toHaveCount(2);

            $families = json_decode((string) file_get_contents($index), true, flags: JSON_THROW_ON_ERROR);

            expect($families)->toBeArray()->toHaveKey('roboto')
                ->and(array_keys($families['roboto']))->toEqualCanonicalizing(['normal', 'bold']);
        });
    });

    it('releases the font lock after a render', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

        pdfWithFreshFonts(function () use ($issued): void {
            $pdf = app(InvoicePdf::class);
            $pdf->render($issued);
            $lock = Cache::lock(InvoicePdf::FONT_LOCK, 5);

            expect($lock->get())->toBeTrue();
            $lock->release();
        });
    });
});
