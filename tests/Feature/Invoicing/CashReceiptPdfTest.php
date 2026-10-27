<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\StoreCashReceiptPdf;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Pdf\CashReceiptPdf;
use App\Domain\Invoicing\Pdf\CashReceiptPdfData;
use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;

/*
 * The PDF of a cash receipt (Phase 15; D-11, D-08, AS-5, AS-6; P15-08, P15-12): made from the receipt row alone, stored as
 * a document of the receipt in the transaction of the payment, in the client's language, with a cancelled band when
 * cancelled. Every name, number and amount is fictional.
 */

beforeEach(function (): void {
    Queue::fake();
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * A small invented PNG upload standing for the supplier signature.
 */
function cashReceiptPdfSignature(): UploadedFile
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 90, 160));

    ob_start();
    imagepng($image);

    return DocumentFixtures::upload('signature-example.png', (string) ob_get_clean());
}

/**
 * Stores a signature image in the settings, so the next issued document snapshots it; returns its object path.
 */
function cashReceiptPdfConfigureSignature(): string
{
    $path = app(DocumentImage::class)->store(cashReceiptPdfSignature(), 'signature');

    app(PartnerContext::class)->runAsSystem(static function () use ($path): void {
        $supplier = app(SupplierSettings::class);
        $supplier->signature_path = $path;
        $supplier->save();
    });
    app()->forgetScopedInstances();

    return $path;
}

/**
 * Pays 1 500 CZK in cash on a new issued invoice of a client with the given language, as the Admin through the write
 * core; the payment issues the receipt and its PDF.
 *
 * @return array{0: User, 1: Invoice, 2: CashReceipt}
 */
function cashReceiptPdfPay(string $language = 'cs', string $clientName = 'Example Client s.r.o.'): array
{
    $admin = Canary::admin();
    $client = InvoiceFixtures::client(['invoice_language' => $language, 'name' => $clientName]);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    test()->actingAs($admin);

    $payment = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '1500', 'paid_on' => '2026-10-14', 'method' => 'cash']);
    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();

    return [$admin, $invoice->refresh(), $receipt];
}

/**
 * Runs a write on the receipt as the system, the way the Actions do (the guard allows the listed columns only).
 *
 * @param  array<string, mixed>  $attributes
 */
function cashReceiptPdfWrite(CashReceipt $receipt, array $attributes): CashReceipt
{
    app(PartnerContext::class)->runAsSystem(static function () use ($receipt, $attributes): void {
        CashReceipt::query()->findOrFail($receipt->id)->forceFill($attributes)->save();
    });

    return CashReceipt::query()->findOrFail($receipt->id);
}

it('stores the PDF of a recorded cash payment as a document of the receipt', function (): void {
    [, , $receipt] = cashReceiptPdfPay();

    expect($receipt->pdf_document_id)->not->toBeNull();

    $document = Document::query()->findOrFail($receipt->pdf_document_id);

    expect($document->owner_type)->toBe('cash_receipt')
        ->and($document->owner_id)->toBe($receipt->id)
        ->and($document->client_id)->toBe($receipt->client_id)
        ->and($document->project_id)->toBeNull()
        ->and($document->mime_type)->toBe('application/pdf')
        ->and($document->name)->toBe('prijmovy-doklad-PPD'.'2026'.'0001'.'.pdf')
        ->and($document->visible_to_client)->toBeFalse();
});

it('names the file after the number only, in both languages', function (): void {
    [, , $czech] = cashReceiptPdfPay();
    [, , $english] = cashReceiptPdfPay('en');
    $pdf = app(CashReceiptPdf::class);

    expect($pdf->fileName($czech))->toBe('prijmovy-doklad-'.$czech->number.'.pdf')
        ->and($pdf->fileName($english))->toBe('cash-receipt-'.$english->number.'.pdf')
        ->and($pdf->render($czech))->toStartWith('%PDF-');
});

it('prints every item of an accounting document in the HTML, the paid document number from the receipt row', function (): void {
    cashReceiptPdfConfigureSignature();
    [, $invoice, $receipt] = cashReceiptPdfPay();

    $html = app(CashReceiptPdf::class)->html($receipt);

    expect($html)->toContain('lang="cs"')
        ->and($html)->toContain('Příjmový pokladní doklad')
        ->and($html)->toContain($receipt->number)
        ->and($html)->toContain('14. 10. 2026')
        ->and($html)->toContain('Datum vystavení')
        ->and($html)->toContain('Datum přijetí hotovosti')
        ->and($html)->toContain('Přijato od')
        ->and($html)->toContain('Example Client s.r.o.')
        ->and($html)->toContain('Příjemce')
        ->and($html)->toContain('Example Supplier s.r.o.')
        ->and($html)->toContain('Dodavatel není plátce DPH.')
        ->and($html)->toContain(Money::ofMinor(150000, 'CZK')->format('cs'))
        ->and($html)->toContain('Účel platby')
        ->and($html)->toContain('Úhrada faktury č. '.$invoice->number)
        ->and($html)->toContain('Hrazený doklad')
        ->and($html)->toContain($receipt->document_number)
        ->and($html)->toContain('Doklad vystaven elektronicky: Example Supplier s.r.o.')
        ->and($html)->not->toContain('STORNO');
});

it('prints the supplier signature as the signature record and nothing of a payment instruction', function (): void {
    cashReceiptPdfConfigureSignature();
    [, , $receipt] = cashReceiptPdfPay();

    $html = app(CashReceiptPdf::class)->html($receipt);
    preg_match_all('#<img src="data:image/png;base64,#', $html, $images);

    // Only the signature image: no QR code, no pay button, no payer signature box (AS-5).
    expect($images[0])->toHaveCount(1)
        ->and($html)->not->toContain('Variabilní symbol')
        ->and($html)->not->toContain('IBAN')
        ->and($html)->not->toContain('Číslo účtu')
        ->and($html)->not->toContain('kartou')
        ->and($html)->not->toContain('<a ')
        ->and($html)->not->toContain('DPH %')
        ->and($html)->not->toContain('slovy');
});

it('reads back the same facts from the extracted text of the stored PDF', function (): void {
    [, $invoice, $receipt] = cashReceiptPdfPay();
    $text = PdfText::flat(app(CashReceiptPdf::class)->render($receipt));

    if ($text === null) {
        $this->markTestSkipped('Ghostscript (gs) is not installed, so the PDF text cannot be read back.');
    }

    expect($text)->toContain('Příjmový pokladní doklad')
        ->and($text)->toContain($receipt->number)
        ->and($text)->toContain('Přijato od')
        ->and($text)->toContain('Example Client s.r.o.')
        ->and($text)->toContain('Příjemce')
        ->and($text)->toContain('Example Supplier s.r.o.')
        ->and($text)->toContain(preg_replace('/[\s\x{00A0}]+/u', ' ', Money::ofMinor(150000, 'CZK')->format('cs')))
        ->and($text)->toContain('Hrazený doklad')
        ->and($text)->toContain($invoice->number)
        ->and($text)->not->toContain('Variabilní symbol');
});

it('prints an English receipt for an English client', function (): void {
    [, $invoice, $receipt] = cashReceiptPdfPay('en', 'Sample Customer Ltd.');

    $html = app(CashReceiptPdf::class)->html($receipt);

    expect($html)->toContain('lang="en"')
        ->and($html)->toContain('Cash receipt')
        ->and($html)->toContain('Date of issue')
        ->and($html)->toContain('Date cash received')
        ->and($html)->toContain('Received from')
        ->and($html)->toContain('Received by')
        ->and($html)->toContain('Document paid')
        ->and($html)->toContain('Payment of invoice no. '.$invoice->number)
        ->and($html)->toContain('Issued electronically by Example Supplier s.r.o.')
        ->and($html)->toContain('2026-10-14')
        ->and($html)->toContain(Money::ofMinor(150000, 'CZK')->format('en'))
        ->and($html)->not->toContain('Příjmový');
});

it('renders a cancelled receipt with the cancelled band and the same number', function (): void {
    [, , $receipt] = cashReceiptPdfPay();
    $before = app(CashReceiptPdf::class)->html($receipt);

    $cancelled = cashReceiptPdfWrite($receipt, ['cancelled_at' => CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Prague')]);
    $html = app(CashReceiptPdf::class)->html($cancelled);

    expect($before)->not->toContain('STORNO')
        ->and($html)->toContain('STORNO - doklad zrušen 20. 10. 2026')
        ->and($html)->toContain($receipt->number)
        ->and($html)->toContain('Příjmový pokladní doklad')
        ->and($html)->toContain(Money::ofMinor(150000, 'CZK')->format('cs'));
});

it('prints Admin-typed purpose and party text as text, never as markup', function (): void {
    [, , $receipt] = cashReceiptPdfPay();
    $edited = cashReceiptPdfWrite($receipt, ['purpose' => 'Advance <b>x</b> <img src="file:///etc/example">']);

    $html = app(CashReceiptPdf::class)->html($edited);

    expect($html)->toContain('Advance &lt;b&gt;x&lt;/b&gt; &lt;img src=&quot;file:///etc/example&quot;&gt;')
        ->and($html)->not->toContain('<b>x</b>')
        ->and($html)->not->toContain('<img src="file://')
        ->and(app(CashReceiptPdf::class)->render($edited))->toStartWith('%PDF-');
});

it('reads no invoices row to build its data', function (): void {
    [, , $receipt] = cashReceiptPdfPay();
    $fresh = CashReceipt::query()->findOrFail($receipt->id);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $data = CashReceiptPdfData::fromReceipt($fresh);

    expect($data->documentNumber)->toBe($receipt->document_number)
        ->and(implode("\n", $queries))->not->toMatch('/"(invoices|invoice_admin|clients|settings)"/');
});

it('fails the cash payment as a whole when the receipt PDF cannot be rendered', function (): void {
    $signature = cashReceiptPdfConfigureSignature();
    $admin = Canary::admin();
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $this->actingAs($admin);

    Storage::disk('local')->delete($signature);

    expect(fn () => app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '1500', 'paid_on' => '2026-10-14', 'method' => 'cash']))
        ->toThrow(RuntimeException::class, 'PDF se nepodařilo vytvořit');

    expect(DB::table('invoice_payments')->count())->toBe(0)
        ->and(CashReceipt::query()->count())->toBe(0)
        ->and(Document::query()->where('owner_type', 'cash_receipt')->count())->toBe(0);
});

it('replaces the stored PDF: the old document is gone and the new one is linked', function (): void {
    [$admin, , $receipt] = cashReceiptPdfPay();
    $old = (string) $receipt->pdf_document_id;

    $new = DB::transaction(static fn (): Document => app(StoreCashReceiptPdf::class)->handle($admin, CashReceipt::query()->findOrFail($receipt->id)));

    expect(Document::query()->whereKey($old)->exists())->toBeFalse()
        ->and($new->getKey())->not->toBe($old)
        ->and(CashReceipt::query()->findOrFail($receipt->id)->pdf_document_id)->toBe($new->getKey())
        ->and(Document::query()->where('owner_type', 'cash_receipt')->where('owner_id', $receipt->id)->count())->toBe(1);
});

it('keeps the old document and object when the replacing transaction rolls back', function (): void {
    [$admin, , $receipt] = cashReceiptPdfPay();
    $old = Document::query()->findOrFail($receipt->pdf_document_id);
    $path = $old->getFirstMedia(Document::MEDIA_COLLECTION)?->getPathRelativeToRoot();

    try {
        DB::transaction(static function () use ($admin, $receipt): void {
            app(StoreCashReceiptPdf::class)->handle($admin, CashReceipt::query()->findOrFail($receipt->id));

            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($path)->not->toBeNull()
        ->and(Document::query()->whereKey($old->getKey())->exists())->toBeTrue()
        ->and(CashReceipt::query()->findOrFail($receipt->id)->pdf_document_id)->toBe($old->getKey())
        ->and(Storage::disk('local')->exists((string) $path))->toBeTrue()
        ->and(Document::query()->where('owner_type', 'cash_receipt')->where('owner_id', $receipt->id)->count())->toBe(1);
});

it('has the same keys in Czech and English', function (): void {
    $czech = require lang_path('cs/cash_receipt_pdf.php');
    $english = require lang_path('en/cash_receipt_pdf.php');

    expect(array_keys(Arr::dot($czech)))->toBe(array_keys(Arr::dot($english)))
        ->and(array_keys(Arr::dot($czech)))->toContain('file_name', 'cancelled', 'title', 'document', 'issuer', 'purpose.invoice');
});
