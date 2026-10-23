<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\RegeneratePdf;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Payments\Queries\PartnerPayButton;
use App\Domain\Payments\Support\PayLinkBlock;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FictionalIban;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;

/*
 * The "Pay by card" button of the PDF and the pay-link block of the e-mail (PA-02; D-01, D-03, D-05, D-06). Both
 * point at the stable Kokpit URL and never at Stripe. Every name, address and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    config(['app.url' => 'https://kokpit.example.com']);
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    Mail::getSymfonyTransport()->flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    CardPaymentSetup::enable();
});

/**
 * The bytes of the PDF stored at issue.
 */
function payOutStoredPdf(Invoice $invoice): string
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($invoice): string {
        $documentId = DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id');
        $media = Document::query()->whereKey($documentId)->firstOrFail()->getFirstMedia(Document::MEDIA_COLLECTION);

        return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    });
}

/**
 * The messages the array transport holds, as Symfony e-mails.
 *
 * @return list<Email>
 */
function payOutMails(): array
{
    $messages = [];

    foreach (Mail::getSymfonyTransport()->messages() as $message) {
        assert($message instanceof SentMessage);
        $original = $message->getOriginalMessage();
        assert($original instanceof Email);
        $messages[] = $original;
    }

    return $messages;
}

/**
 * Queues and sends the e-mail of a document with the dialog defaults, or with the given body, and returns the
 * text body of the one message that left.
 */
function payOutSend(Invoice $invoice, ?string $body = null): string
{
    $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);

    app(QueueInvoiceEmail::class)->handle(test()->admin, $invoice, [
        'recipient' => 'invoice@example.com',
        'subject' => $defaults['subject'],
        'body' => $body ?? $defaults['body'],
        'attach_work_report' => false,
    ]);

    // Only the document e-mail this helper just queued: by recipient and subject, so a further e-mail does not count.
    $messages = array_values(array_filter(
        payOutMails(),
        static fn (Email $mail): bool => $mail->getTo()[0]->getAddress() === 'invoice@example.com'
            && $mail->getSubject() === $defaults['subject'],
    ));

    expect($messages)->toHaveCount(1);

    return (string) $messages[0]->getTextBody();
}

describe('the tracer: a card-on invoice carries its stable URL in the PDF and in the e-mail', function (): void {
    it('prints the lead line, the button as a link annotation and the stable URL in the PDF', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $url = PayUrl::forInvoice($invoice);
        $pdf = payOutStoredPdf($invoice);
        $text = PdfText::flat($pdf);

        if ($text === null) {
            $this->markTestSkipped('Ghostscript is not installed.');
        }

        expect($url)->toStartWith('https://kokpit.example.com/pay/')
            ->and($pdf)->toContain('/URI')
            ->and($pdf)->toContain($url)
            ->and($text)->toContain('Nebo zaplaťte online kartou:')
            ->and($text)->toContain('Zaplatit kartou')
            ->and($text)->toContain($url)
            ->and($pdf)->not->toContain('stripe.com')
            ->and($text)->not->toContain('stripe.com');
    });

    it('appends the pay block after the confirmed text and leaves the log row as confirmed', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));
        $url = PayUrl::forInvoice($invoice);
        $confirmed = app(QueueInvoiceEmail::class)->defaults($invoice)['body'];

        $body = payOutSend($invoice);

        expect($body)->toStartWith($confirmed)
            ->and(rtrim($body))->toEndWith("\n\nDoklad můžete zaplatit online kartou:\n".$url)
            ->and($body)->not->toContain('stripe.com')
            ->and(DB::table('invoice_emails')->where('invoice_id', $invoice->id)->value('body'))->toBe($confirmed);
    });
});

/**
 * The text of the PDF stored at issue, or a skip when Ghostscript is not installed.
 */
function payOutStoredText(Invoice $invoice): string
{
    $text = PdfText::flat(payOutStoredPdf($invoice));

    if ($text === null) {
        test()->markTestSkipped('Ghostscript is not installed.');
    }

    return $text;
}

/**
 * A EUR account with a BIC next to the CZK one, so a EUR document has a payment box (no QR) to print the button in.
 */
function payOutEuroAccount(): void
{
    app(PartnerContext::class)->runAsSystem(static function (): void {
        $banks = app(BankAccountSettings::class);
        $banks->accounts = [
            new BankAccount(BankAccountFormat::Europe2, 'Example CZK account', 'CZK', iban: FictionalIban::czech('12')),
            new BankAccount(BankAccountFormat::Europe2, 'Example EUR account', 'EUR', bic: implode('', ['EXAM', 'CZPP']), iban: FictionalIban::czech('34')),
        ];
        $banks->save();
    });
    app()->forgetScopedInstances();
}

/**
 * A full payment of the document as the Admin records it.
 */
function payOutPay(Invoice $invoice, string $amount): void
{
    app(RecordPayment::class)->handle(test()->admin, $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14']);
}

describe('every document variant prints the right thing', function (): void {
    it('prints the same button and URL on a proforma', function (): void {
        $proforma = CardPaymentSetup::issued(CardPaymentSetup::client(), InvoiceKind::Proforma);
        $text = payOutStoredText($proforma);

        expect(PayUrl::forInvoice($proforma))->not->toBeNull()
            ->and($text)->toContain('ZÁLOHOVÁ FAKTURA')
            ->and($text)->toContain('Nebo zaplaťte online kartou:')
            ->and($text)->toContain('Zaplatit kartou')
            ->and($text)->toContain(PayUrl::forInvoice($proforma))
            ->and(payOutStoredPdf($proforma))->toContain('/URI');
    });

    it('prints English words on an English invoice and mails the English lead', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_language' => 'en', 'invoice_email' => 'invoice@example.com']));
        $text = payOutStoredText($invoice);

        expect($text)->toContain('Or pay online by card:')
            ->and($text)->toContain('Pay by card')
            ->and($text)->not->toContain('Zaplatit kartou')
            ->and($text)->toContain(PayUrl::forInvoice($invoice));

        expect(rtrim(payOutSend($invoice)))->toEndWith("\n\nYou can pay this document online by card:\n".PayUrl::forInvoice($invoice));
    });

    it('prints the button in the payment box of a EUR invoice that has no QR', function (): void {
        payOutEuroAccount();
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $invoice = InvoiceFixtures::issued(
            CardPaymentSetup::client(['currency' => 'EUR', 'hourly_rate' => Money::ofMinor(0, 'EUR')]),
            [InvoiceFixtures::item('Consulting', '2', '100.00')],
        );
        $text = payOutStoredText($invoice);

        expect($invoice->currency)->toBe('EUR')
            ->and(app(InvoicePdf::class)->html($invoice))->not->toContain('data:image/png;base64,iVBOR')
            ->and($text)->toContain('Nebo zaplaťte online kartou:')
            ->and($text)->toContain('Zaplatit kartou')
            ->and($text)->toContain(PayUrl::forInvoice($invoice));
    });

    it('prints no button on a document issued with the global switch off', function (): void {
        CardPaymentSetup::onlinePayments(false);
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $text = payOutStoredText($invoice);

        expect(PayUrl::forInvoice($invoice))->toBeNull()
            ->and($text)->not->toContain('Nebo zaplaťte online kartou')
            ->and($text)->not->toContain('Zaplatit kartou')
            ->and($text)->not->toContain('/pay/')
            ->and(payOutStoredPdf($invoice))->not->toContain('/URI')
            ->and(payOutSend($invoice))->not->toContain('kartou');
    });

    it('prints no button on a credit note of a card-on invoice', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $credit = app(IssueCreditNote::class)->storno($this->admin, $invoice);
        $pdf = app(InvoicePdf::class)->render($credit);
        $text = PdfText::flat($pdf);

        if ($text === null) {
            $this->markTestSkipped('Ghostscript is not installed.');
        }

        expect($credit->kind)->toBe(InvoiceKind::CreditNote)
            ->and($text)->toContain('DOBROPIS')
            ->and($text)->not->toContain('Zaplatit kartou')
            ->and($text)->not->toContain('/pay/')
            ->and($pdf)->not->toContain('/URI')
            ->and(app(InvoicePdf::class)->html($credit))->not->toContain('/pay/');
    });

    it('keeps the button and the URL on a PDF regenerated after the document is paid', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $url = PayUrl::forInvoice($invoice);
        $issuedText = payOutStoredText($invoice);

        payOutPay($invoice, '12500.00');
        $document = Document::query()->whereKey(DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id'))->firstOrFail();
        app(DeleteDocument::class)->handle($this->admin, $document, true);
        app(RegeneratePdf::class)->handle($this->admin, Invoice::query()->whereKey($invoice->id)->firstOrFail());

        $regeneratedText = payOutStoredText($invoice);

        expect($regeneratedText)->toBe($issuedText)
            ->and($regeneratedText)->toContain($url)
            ->and($regeneratedText)->toContain('Zaplatit kartou')
            ->and(payOutStoredPdf($invoice))->toContain('/URI');
    });
});

describe('the pay block of a resend', function (): void {
    it('is added to a partly paid document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));
        payOutPay($invoice, '2000.00');
        $invoice = Invoice::query()->whereKey($invoice->id)->firstOrFail();

        expect(PayLinkBlock::applies($invoice))->toBeTrue()
            ->and(rtrim(payOutSend($invoice)))->toEndWith("\n\nDoklad můžete zaplatit online kartou:\n".PayUrl::forInvoice($invoice));
    });

    it('is not added to a paid document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));
        payOutPay($invoice, '12500.00');
        $invoice = Invoice::query()->whereKey($invoice->id)->firstOrFail();

        expect(PayLinkBlock::applies($invoice))->toBeFalse()
            ->and(PayLinkBlock::forMail($invoice))->toBeNull()
            ->and(payOutSend($invoice))->not->toContain('/pay/')->not->toContain('Doklad můžete zaplatit');
    });

    it('is not added to a cancelled document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));
        app(IssueCreditNote::class)->storno($this->admin, $invoice);
        $invoice = Invoice::query()->whereKey($invoice->id)->firstOrFail();

        expect(PayLinkBlock::applies($invoice))->toBeFalse()
            ->and(payOutSend($invoice))->not->toContain('/pay/');
    });

    it('is added even when the Admin cleared the text down to a minimum', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));

        $body = payOutSend($invoice, 'Hi');

        expect($body)->toStartWith('Hi')
            ->and(rtrim($body))->toEndWith("\n\nDoklad můžete zaplatit online kartou:\n".PayUrl::forInvoice($invoice))
            ->and(DB::table('invoice_emails')->where('invoice_id', $invoice->id)->value('body'))->toBe('Hi');
    });

    it('is never added to the mail of a credit note', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));
        $credit = app(IssueCreditNote::class)->storno($this->admin, $invoice);

        expect(PayLinkBlock::applies($credit))->toBeFalse()
            ->and(payOutSend($credit))->not->toContain('/pay/');
    });
});

/**
 * The detail of a document with the e-mail dialog open.
 */
function payOutDialog(Invoice $invoice): Testable
{
    return Livewire::withQueryParams([])
        ->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->mountAction('sendEmail');
}

describe('the e-mail dialog announces the pay link', function (): void {
    it('shows the gray line on an unpaid card-on invoice and keeps the link out of the text', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        $page = payOutDialog($invoice)->assertMountedActionModalSee('Do e-mailu se automaticky přidá odkaz Zaplatit kartou.');

        expect((string) $page->get('mountedActions.0.data.body'))->not->toContain('/pay/')
            ->and((string) $page->get('mountedActions.0.data.body'))->not->toContain(PayUrl::forInvoice($invoice));
    });

    it('shows no line on a paid document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        payOutPay($invoice, '12500.00');

        payOutDialog($invoice)->assertMountedActionModalDontSee('Do e-mailu se automaticky přidá odkaz');
    });

    it('shows no line on a cancelled document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        payOutDialog($invoice)->assertMountedActionModalDontSee('Do e-mailu se automaticky přidá odkaz');
    });

    it('shows no line on a document issued with card payment off', function (): void {
        CardPaymentSetup::onlinePayments(false);
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        payOutDialog($invoice)->assertMountedActionModalDontSee('Do e-mailu se automaticky přidá odkaz');
    });
});

/**
 * The card switch of an open issued document moved by raw SQL as system (Phase 14, plan 14-02: the switch is writable
 * while the document is open and the token it keeps never changes).
 */
function payOutSwitch(Invoice $invoice, bool $on): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($invoice, $on): void {
        DB::update('update invoice_admin set card_payment_enabled = ? where invoice_id = ?', [$on ? 'true' : 'false', $invoice->id]);
    });
}

describe('the button follows the switch (Phase 14)', function (): void {
    it('offers no URL, no PDF button, no pay block and no Partner or Admin button once the switch is off, and the same ones when it is on again', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));
        $url = PayUrl::forInvoice($invoice);
        $token = (string) DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pay_token');

        expect($url)->toBe(PayUrl::for($token))
            ->and(PartnerPayButton::urlFor($invoice))->toBe($url)
            ->and(PayLinkBlock::applies($invoice))->toBeTrue();

        payOutSwitch($invoice, false);
        $off = Invoice::query()->whereKey($invoice->id)->firstOrFail();
        $html = app(InvoicePdf::class)->html($off);

        expect(DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pay_token'))->toBe($token)
            ->and(PayUrl::forInvoice($off))->toBeNull()
            ->and($html)->not->toContain('Zaplatit kartou')
            ->and($html)->not->toContain('/pay/')
            ->and($html)->not->toContain('class="card-lead"')
            ->and(PayLinkBlock::applies($off))->toBeFalse()
            ->and(PayLinkBlock::forMail($off))->toBeNull()
            ->and(PartnerPayButton::urlFor($off))->toBeNull();

        Livewire::withQueryParams([])
            ->test(ViewInvoice::class, ['record' => $off->getRouteKey()])
            ->assertActionHidden('payByCard');

        payOutDialog($off)->assertMountedActionModalDontSee('Do e-mailu se automaticky přidá odkaz');

        payOutSwitch($invoice, true);
        $on = Invoice::query()->whereKey($invoice->id)->firstOrFail();

        expect(PayUrl::forInvoice($on))->toBe($url)
            ->and(app(InvoicePdf::class)->html($on))->toContain('Zaplatit kartou')->toContain($url)
            ->and(PayLinkBlock::applies($on))->toBeTrue()
            ->and(PartnerPayButton::urlFor($on))->toBe($url);

        Livewire::withQueryParams([])
            ->test(ViewInvoice::class, ['record' => $on->getRouteKey()])
            ->assertActionVisible('payByCard')
            ->assertActionHasUrl('payByCard', $url);
    });

    it('sends the e-mail of a document whose switch is off without a pay block', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));
        payOutSwitch($invoice, false);
        $off = Invoice::query()->whereKey($invoice->id)->firstOrFail();

        expect(payOutSend($off))->not->toContain('/pay/')->not->toContain('Doklad můžete zaplatit');
    });
});
