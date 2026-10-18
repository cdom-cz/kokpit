<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
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

    $messages = payOutMails();

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
