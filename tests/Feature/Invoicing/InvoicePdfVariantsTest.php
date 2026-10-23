<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\FictionalIban;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;

/*
 * The variants of the invoice PDF (IN-04, IN-06; D-14, D-15, D-21, D-23, D-24; rulings R-18-1, R-18-2): the
 * proforma, the credit note, a foreign-currency document, the English copy, the optional blocks, and the proof
 * that a regenerated PDF equals the issued one. Equivalence is asserted on the HTML string, the page count and
 * the Ghostscript text, never on the PDF bytes, which embed creation metadata. Every name, number and amount is
 * fictional.
 */

/**
 * The data-URI images of an HTML document, in order.
 *
 * @return list<string>
 */
function variantImages(string $html): array
{
    preg_match_all('#<img src="(data:image/png;base64,[^"]+)"#', $html, $matches);

    return $matches[1];
}

/**
 * A small invented PNG upload; the colour is drawn from the seed so two seeds are two different images.
 */
function variantUpload(int $seed): UploadedFile
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $seed % 256, ($seed * 7) % 256, ($seed * 13) % 256));

    ob_start();
    imagepng($image);

    return DocumentFixtures::upload('variant-'.$seed.'.png', (string) ob_get_clean());
}

/**
 * Writes settings as the system (the settings model is closed to a guest) and drops the scoped instances.
 *
 * @param  Closure(): void  $write
 */
function variantSettings(Closure $write): void
{
    app(PartnerContext::class)->runAsSystem($write);
    app()->forgetScopedInstances();
}

/**
 * The text of a rendered document with white space reduced, for the assertions on words.
 */
function variantFlat(string $html): string
{
    return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
}

/**
 * Money as the page prints it, with the no-break spaces reduced the way {@see variantFlat()} reduces them.
 */
function variantMoney(int $minor, string $currency, string $locale): string
{
    return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', Money::ofMinor($minor, $currency)->format($locale)));
}

/**
 * An issued, still open document switched to online payment the way the database allows it (Phase 14, plan 14-02): the
 * method is online, the bank snapshot is gone, the card switch is on and a first pay token is set. Returns the document
 * read again, and the token through the second parameter.
 */
function variantOnline(Invoice $issued, ?string &$token = null): Invoice
{
    $token = PayToken::generate();

    app(PartnerContext::class)->runAsSystem(static function () use ($issued, $token): void {
        DB::update("update invoices set payment_method = 'online', bank_snapshot = null where id = ?", [$issued->id]);
        DB::update('update invoice_admin set card_payment_enabled = true, pay_token = ? where invoice_id = ?', [$token, $issued->id]);
    });

    return Invoice::query()->whereKey($issued->id)->firstOrFail();
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

describe('the proforma', function (): void {
    it('carries its heading, the line that it is not a tax document and the payment box with the QR when it is in CZK', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Advance', '1', '5000.00', 'ks')], InvoiceKind::Proforma);

        $html = app(InvoicePdf::class)->html($issued);
        $text = variantFlat($html);

        expect($html)->toContain('ZÁLOHOVÁ FAKTURA')
            ->and($text)->toContain('Nejde o daňový doklad.')
            ->and($text)->toContain('Variabilní symbol')
            ->and($text)->toContain('K ÚHRADĚ')
            ->and(variantImages($html))->toHaveCount(1)
            ->and(app(InvoicePdf::class)->fileName($issued))->toBe('zalohova-faktura-'.$issued->number.'.pdf');
    });

    it('prints English copy for an English proforma', function (): void {
        $issued = InvoiceFixtures::issued(
            InvoiceFixtures::client(['invoice_language' => 'en']),
            [InvoiceFixtures::item('Advance', '1', '5000.00', 'pcs')],
            InvoiceKind::Proforma,
        );

        $text = variantFlat(app(InvoicePdf::class)->html($issued));

        expect($text)->toContain('PROFORMA INVOICE')
            ->and($text)->toContain('This is not a tax document.')
            ->and($text)->not->toContain('ZÁLOHOVÁ');
    });
});

describe('the credit note', function (): void {
    it('prints negative quantities and amounts, names the corrected document and carries no payment data', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '2.5', '1000.00')]);
        $credit = InvoiceFixtures::stornoed($invoice);

        $html = app(InvoicePdf::class)->html($credit);
        $text = variantFlat($html);

        expect($html)->toContain('DOBROPIS')
            ->and($text)->toContain('-2,5 h')
            ->and($text)->toContain(variantMoney(-250000, 'CZK', 'cs'))
            ->and($text)->toContain('Opravuje doklad č. '.$invoice->number)
            ->and($text)->toContain('DOBROPIS CELKEM')
            ->and($text)->toContain('Dobropis neobsahuje platební údaje. Případné vrácení platby proběhne samostatně.')
            ->and($text)->not->toContain('Datum splatnosti')
            ->and($text)->not->toContain('Variabilní symbol')
            ->and($text)->not->toContain('Prosíme o úhradu')
            ->and($text)->not->toContain('K ÚHRADĚ')
            ->and(variantImages($html))->toBe([])
            ->and($html)->not->toContain('data:image/png')
            ->and(app(InvoicePdf::class)->fileName($credit))->toBe('dobropis-'.$credit->number.'.pdf');
    });

    it('prints the credit note of an English document in English', function (): void {
        $invoice = InvoiceFixtures::issued(
            InvoiceFixtures::client(['invoice_language' => 'en']),
            [InvoiceFixtures::item('Consulting', '2.5', '1000.00')],
        );
        $credit = InvoiceFixtures::stornoed($invoice);

        $text = variantFlat(app(InvoicePdf::class)->html($credit));

        expect($text)->toContain('CREDIT NOTE')
            ->and($text)->toContain('CREDIT TOTAL')
            ->and($text)->toContain('Corrects document no. '.$invoice->number)
            ->and($text)->toContain('This credit note carries no payment details. Any refund is made separately.')
            ->and($text)->not->toContain('Due date')
            ->and($text)->not->toContain('Variable symbol');
    });

    it('renders the credit note to a PDF', function (): void {
        $credit = InvoiceFixtures::stornoed(InvoiceFixtures::issued(InvoiceFixtures::client()));

        expect(app(InvoicePdf::class)->render($credit))->toStartWith('%PDF');
    });
});

describe('a document in another currency', function (): void {
    /**
     * Replaces the EUR account by one that carries a BIC, so the payment box has something to print for it.
     */
    function variantEuroAccountWithBic(): string
    {
        $bic = implode('', ['EXAM', 'CZPP']);

        variantSettings(function () use ($bic): void {
            $banks = app(BankAccountSettings::class);
            $banks->accounts = [
                new BankAccount(BankAccountFormat::Europe2, 'Example CZK account', 'CZK', iban: FictionalIban::czech('12')),
                new BankAccount(BankAccountFormat::Europe2, 'Example EUR account', 'EUR', bic: $bic, iban: FictionalIban::czech('34')),
            ];
            $banks->save();
        });

        return $bic;
    }

    it('prints the payment box without a QR, with the IBAN and the BIC, and money in EUR', function (): void {
        $bic = variantEuroAccountWithBic();
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $issued = InvoiceFixtures::issued(
            InvoiceFixtures::client(['currency' => 'EUR', 'hourly_rate' => Money::ofMinor(0, 'EUR')]),
            [InvoiceFixtures::item('Consulting', '2', '100.00')],
        );

        $html = app(InvoicePdf::class)->html($issued);
        $text = variantFlat($html);

        expect($issued->currency)->toBe('EUR')
            ->and(variantImages($html))->toBe([])
            ->and($text)->toContain('IBAN')
            ->and($text)->toContain($bic)
            ->and($text)->toContain('BIC')
            ->and($text)->toContain(variantMoney(20000, 'EUR', 'cs'))
            ->and($text)->toContain('Variabilní symbol');
    });

    it('prints an English EUR document with English words and money', function (): void {
        variantEuroAccountWithBic();
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $issued = InvoiceFixtures::issued(
            InvoiceFixtures::client(['currency' => 'EUR', 'hourly_rate' => Money::ofMinor(0, 'EUR'), 'invoice_language' => 'en']),
            [InvoiceFixtures::item('Consulting', '2', '100.00')],
        );

        $text = variantFlat(app(InvoicePdf::class)->html($issued));

        expect($text)->toContain('AMOUNT DUE')
            ->and($text)->toContain(variantMoney(20000, 'EUR', 'en'))
            ->and($text)->toContain('Variable symbol');
    });
});

describe('the payment method (Phase 14)', function (): void {
    it('prints an online CZK document with the amount, the online sentence and the card button, and no bank detail, symbol or QR', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '1000.00')]);
        $iban = FictionalIban::czech('12');
        $online = variantOnline($issued, $token);

        $html = app(InvoicePdf::class)->html($online);
        $text = variantFlat($html);

        expect($text)->toContain(variantMoney(100000, 'CZK', 'cs'))
            ->and($text)->toContain('Prosíme o úhradu částky')
            ->and($text)->toContain('online platební kartou do')
            ->and($text)->toContain('Online platební kartou')
            ->and($text)->toContain('Způsob úhrady')
            ->and($text)->toContain('K úhradě')
            ->and($text)->toContain('Zaplaťte online kartou:')
            ->and($text)->not->toContain('Nebo zaplaťte')
            ->and($text)->toContain('Zaplatit kartou')
            ->and($text)->toContain(PayUrl::for($token))
            ->and($text)->not->toContain('IBAN')
            ->and($text)->not->toContain('Číslo účtu')
            ->and($text)->not->toContain('Variabilní symbol')
            ->and($text)->not->toContain('Převodem')
            ->and($text)->not->toContain('na účet')
            ->and($text)->not->toContain(trim(chunk_split($iban, 4, ' ')))
            ->and($html)->not->toContain($iban)
            ->and(variantImages($html))->toBe([]);
    });

    it('keeps the bank box and the QR of a transfer document', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '1000.00')]);

        $html = app(InvoicePdf::class)->html($issued);
        $text = variantFlat($html);

        expect($issued->payment_method?->value)->toBe('transfer')
            ->and($text)->toContain('IBAN')
            ->and($text)->toContain('na účet')
            ->and($text)->toContain('Variabilní symbol')
            ->and($text)->toContain('Převodem')
            ->and($text)->toContain(variantMoney(100000, 'CZK', 'cs'))
            ->and($text)->not->toContain('Online platební kartou')
            ->and(variantImages($html))->toHaveCount(1);
    });

    it('prints every amount of an online EUR document in EUR, with the button and no bank detail', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $issued = InvoiceFixtures::issued(
            InvoiceFixtures::client(['currency' => 'EUR', 'hourly_rate' => Money::ofMinor(0, 'EUR')]),
            [InvoiceFixtures::item('Consulting', '2', '100.00')],
        );
        $online = variantOnline($issued, $token);

        $html = app(InvoicePdf::class)->html($online);
        $text = variantFlat($html);
        $amount = variantMoney(20000, 'EUR', 'cs');

        expect($online->currency)->toBe('EUR')
            ->and(substr_count($text, $amount))->toBeGreaterThanOrEqual(3)
            ->and($text)->not->toContain(variantMoney(20000, 'CZK', 'cs'))
            ->and($text)->toContain('Zaplatit kartou')
            ->and($text)->toContain(PayUrl::for($token))
            ->and($text)->not->toContain('IBAN')
            ->and($text)->not->toContain('Variabilní symbol')
            ->and(variantImages($html))->toBe([]);
    });

    it('prints the English words of an online English document', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_language' => 'en']), [InvoiceFixtures::item('Consulting', '1', '1000.00')]);
        $online = variantOnline($issued);

        $text = variantFlat(app(InvoicePdf::class)->html($online));

        expect($text)->toContain('Please pay '.variantMoney(100000, 'CZK', 'en').' online by card by')
            ->and($text)->toContain('Online by card')
            ->and($text)->toContain('Pay online by card:')
            ->and($text)->not->toContain('Or pay online by card:')
            ->and($text)->toContain('Pay by card')
            ->and($text)->not->toContain('Variable symbol')
            ->and($text)->not->toContain('Bank transfer');
    });

    it('prints an online proforma the same way', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Advance', '1', '5000.00', 'ks')], InvoiceKind::Proforma);
        $online = variantOnline($issued);

        $text = variantFlat(app(InvoicePdf::class)->html($online));

        expect($text)->toContain('ZÁLOHOVÁ FAKTURA')
            ->and($text)->toContain('Online platební kartou')
            ->and($text)->toContain('Zaplatit kartou')
            ->and($text)->not->toContain('Variabilní symbol');
    });

    it('prints the account rows and the QR of a CZK document paid to a Czech IBAN whatever the currency of the account itself', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '1000.00')]);
        $snapshot = $issued->bank_snapshot;
        $iban = (string) ($snapshot['iban'] ?? '');

        // The snapshotted account is a CZK one; the same account stored as a EUR one prints the same box and QR.
        app(PartnerContext::class)->runAsSystem(static function () use ($issued, $snapshot): void {
            DB::update('update invoices set bank_snapshot = ? where id = ?', [json_encode([...$snapshot, 'currency' => 'EUR'], JSON_THROW_ON_ERROR), $issued->id]);
        });

        $html = app(InvoicePdf::class)->html(Invoice::query()->whereKey($issued->id)->firstOrFail());
        $text = variantFlat($html);

        expect($iban)->not->toBe('')
            ->and($text)->toContain(trim(chunk_split($iban, 4, ' ')))
            ->and($text)->toContain('Variabilní symbol')
            ->and(variantImages($html))->toHaveCount(1);
    });

    it('prints no QR for a EUR document, though its Czech IBAN would be accepted for CZK', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $issued = InvoiceFixtures::issued(
            InvoiceFixtures::client(['currency' => 'EUR', 'hourly_rate' => Money::ofMinor(0, 'EUR')]),
            [InvoiceFixtures::item('Consulting', '2', '100.00')],
        );

        $html = app(InvoicePdf::class)->html($issued);

        expect($issued->currency)->toBe('EUR')
            ->and(variantFlat($html))->toContain('IBAN')
            ->and(variantImages($html))->toBe([]);
    });
});

describe('the optional blocks', function (): void {
    it('prints the logo, the signature, the note and the footer when they are set', function (): void {
        $logo = app(DocumentImage::class)->store(variantUpload(40), 'logo');
        $signature = app(DocumentImage::class)->store(variantUpload(80), 'signature');
        variantSettings(function () use ($logo, $signature): void {
            $supplier = app(SupplierSettings::class);
            $supplier->logo_path = $logo;
            $supplier->signature_path = $signature;
            $supplier->save();

            $invoicing = app(InvoicingSettings::class);
            $invoicing->document_footer = 'Footer of the example supplier.';
            $invoicing->save();
        });

        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Consulting', '1', '100.00')]);
        $draft->forceFill(['note' => 'A printed note for the customer.'])->save();
        $issued = app(IssueDocument::class)->handle(Canary::admin(), $draft);

        $html = app(InvoicePdf::class)->html($issued);
        $images = variantImages($html);
        $text = variantFlat($html);

        // The logo, the signature and the payment QR.
        expect($images)->toHaveCount(3)
            ->and($html)->toContain(base64_encode((string) Storage::disk('local')->get($logo)))
            ->and($html)->toContain(base64_encode((string) Storage::disk('local')->get($signature)))
            ->and($text)->toContain('A printed note for the customer.')
            ->and($text)->toContain('Footer of the example supplier.');
    });

    it('prints none of them when they are not set', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

        $html = app(InvoicePdf::class)->html($issued);

        // Only the payment QR is an image; no note, footer block or signature.
        expect(variantImages($html))->toHaveCount(1)
            ->and($html)->not->toContain('class="printed-note"')
            ->and($html)->not->toContain('class="footer"')
            ->and($html)->not->toContain('class="signature"');
    });

    it('wraps a 300-character footer inside the page', function (): void {
        $footer = trim(str_repeat('Example footer words. ', 15));
        $footer = substr($footer, 0, 300);
        variantSettings(function () use ($footer): void {
            $invoicing = app(InvoicingSettings::class);
            $invoicing->document_footer = $footer;
            $invoicing->save();
        });
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

        $pdf = app(InvoicePdf::class)->render($issued);
        $text = PdfText::flat($pdf);

        expect(mb_strlen($footer))->toBe(300)
            ->and($pdf)->toStartWith('%PDF')
            ->and(PdfText::pageCount($pdf))->toBe(1);

        if ($text === null) {
            $this->markTestSkipped('Ghostscript (gs) is not installed, so the wrapped footer is not read back from the PDF.');
        }

        // The wrapped text keeps every word; nothing is cut at the right edge.
        expect(substr_count($text, 'Example footer words.'))->toBe(substr_count($footer, 'Example footer words.'));
    });
});

describe('regeneration', function (): void {
    it('renders the same issued document twice as the same HTML, page count and text although everything around it changed', function (): void {
        $logo = app(DocumentImage::class)->store(variantUpload(40), 'logo');
        $signature = app(DocumentImage::class)->store(variantUpload(80), 'signature');
        variantSettings(function () use ($logo, $signature): void {
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
        $generator = app(InvoicePdf::class);

        $first = $generator->html($issued);
        $firstPdf = $generator->render($issued);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 11:00:00', 'Europe/Prague'));
        variantSettings(function () use ($client): void {
            Client::query()->whereKey($client->id)->update(['name' => 'Přejmenovaný odběratel a.s.', 'hourly_rate_minor' => 99900]);

            $supplier = app(SupplierSettings::class);
            $supplier->logo_path = app(DocumentImage::class)->store(variantUpload(150), 'logo');
            $supplier->signature_path = app(DocumentImage::class)->store(variantUpload(200), 'signature');
            $supplier->save();

            $invoicing = app(InvoicingSettings::class);
            $invoicing->document_accent_color = '#00aa00';
            $invoicing->document_footer = 'A different footer.';
            $invoicing->save();
        });
        InvoiceFixtures::cnbRate('EUR', '2026-10-15', '26.000');

        $fresh = Invoice::query()->whereKey($issued->id)->firstOrFail();
        $second = $generator->html($fresh);
        $secondPdf = $generator->render($fresh);

        expect($second)->toBe($first)
            ->and(PdfText::pageCount($secondPdf))->toBe(PdfText::pageCount($firstPdf));

        $firstText = PdfText::extract($firstPdf);

        if ($firstText === null) {
            $this->markTestSkipped('Ghostscript (gs) is not installed, so the extracted text is not compared.');
        }

        expect(PdfText::extract($secondPdf))->toBe($firstText);
    });

    it('prints no generation time, so nothing in the HTML depends on the clock', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        $generator = app(InvoicePdf::class);

        $first = $generator->html($issued);
        $this->travelTo(CarbonImmutable::parse('2027-03-01 08:00:00', 'Europe/Prague'));

        expect($generator->html($issued))->toBe($first);
    });

    it('renders a one-line, a 25-line and a 60-line invoice, and numbers every page of the long one', function (int $lines): void {
        $items = [];

        for ($line = 1; $line <= $lines; $line++) {
            $items[] = InvoiceFixtures::item('Line '.$line, '1', '10.00');
        }

        $pdf = app(InvoicePdf::class)->render(InvoiceFixtures::issued(InvoiceFixtures::client(), $items));
        $pages = PdfText::pageCount($pdf);

        expect($pdf)->toStartWith('%PDF')
            ->and($pages)->toBeGreaterThanOrEqual(1);

        if ($lines === 60) {
            expect($pages)->toBeGreaterThan(1);
        }

        $text = PdfText::flat($pdf);

        if ($text === null) {
            $this->markTestSkipped('Ghostscript (gs) is not installed, so the page labels are not read back from the PDF.');
        }

        foreach (range(1, $pages) as $page) {
            expect($text)->toContain('Strana '.$page.' / '.$pages);
        }

        expect($text)->toContain('Line 1')
            ->and($text)->toContain('Line '.$lines);
    })->with([1, 25, 60]);
});
