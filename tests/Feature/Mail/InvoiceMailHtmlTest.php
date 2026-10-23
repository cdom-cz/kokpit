<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * The HTML part of the document e-mail (Phase 13, D-01, T-13-12 to T-13-14): the Admin's text is escaped and never parsed,
 * the card button follows the one predicate of PayLinkBlock, and the layout speaks the language of the document.
 * Every name, address and amount is fictional.
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
 * Queues and sends the e-mail of a document to a fixed address and returns the one message that left.
 */
function invHtmlSend(Invoice $invoice, ?string $body = null): Email
{
    $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);

    Mail::getSymfonyTransport()->flush();

    app(QueueInvoiceEmail::class)->handle(test()->admin, $invoice, [
        'recipient' => 'invoice@example.com',
        'subject' => $defaults['subject'],
        'body' => $body ?? $defaults['body'],
        'attach_work_report' => false,
    ]);

    $messages = MailFixtures::to('invoice@example.com');

    expect($messages)->toHaveCount(1);

    return $messages[0];
}

function invHtmlDom(Email $email): HTMLDocument
{
    return HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);
}

/**
 * The text a reader sees: the document without style elements, white space collapsed.
 */
function invHtmlVisible(HTMLDocument $dom): string
{
    foreach ($dom->querySelectorAll('style, script') as $element) {
        $element->remove();
    }

    return trim((string) preg_replace('/\s+/u', ' ', (string) $dom->body?->textContent));
}

function invHtmlCard(string $language = 'cs'): Invoice
{
    return CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com', 'invoice_language' => $language]));
}

describe('the body the Admin typed', function (): void {
    it('shows list, heading, emphasis, link, code, tag and indentation syntax literally and keeps the line breaks', function (): void {
        $host = implode('.', ['linked', 'example', 'com']);
        $body = implode("\n", [
            '- first item',
            '- second item',
            '# Heading line',
            '1. numbered line',
            'some *star* and _under_ words',
            '[a link](https://'.$host.'/path) and `code`',
            'a \\ backslash, a <b>tag</b> and R&D',
            '    indented by four spaces',
            'single break below',
            'inside one paragraph',
            '',
            'Second paragraph',
        ]);

        $email = invHtmlSend(InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'invoice@example.com'])), $body);
        $dom = invHtmlDom($email);
        $visible = invHtmlVisible($dom);

        foreach (['ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'em', 'strong', 'code', 'pre', 'b', 'i'] as $tag) {
            expect($dom->querySelectorAll($tag))->toHaveCount(0, 'unexpected '.$tag.' element');
        }

        foreach ($dom->querySelectorAll('a') as $anchor) {
            expect($anchor->getAttribute('href'))->not->toContain($host);
        }

        foreach (['- first item', '# Heading line', '1. numbered line', '*star*', '_under_', '[a link](https://'.$host.'/path)', '`code`', '\\ backslash', '<b>tag</b>', 'R&D', 'indented by four spaces'] as $literal) {
            expect($visible)->toContain($literal);
        }

        $paragraphs = $dom->querySelectorAll('table.inner-body td.content-cell > p');

        expect($paragraphs)->toHaveCount(2)
            ->and($paragraphs[0]?->querySelectorAll('br')->length)->toBe(9)
            ->and(trim((string) $paragraphs[1]?->textContent))->toBe('Second paragraph')
            ->and(rtrim((string) $email->getTextBody()))->toBe($body);
    });

    it('drops no text and adds no markup for a body of one line', function (): void {
        $email = invHtmlSend(InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'invoice@example.com'])), 'Hi');

        expect(invHtmlVisible(invHtmlDom($email)))->toContain('Hi')
            ->and(rtrim((string) $email->getTextBody()))->toBe('Hi');
    });
});

describe('the pay button follows the one predicate of PayLinkBlock', function (): void {
    it('shows the button, the lead and the fallback URL for a card document that owes money', function (): void {
        $invoice = invHtmlCard();
        $url = PayUrl::forInvoice($invoice);
        $dom = invHtmlDom(invHtmlSend($invoice));

        expect($dom->querySelectorAll('a.button'))->toHaveCount(1)
            ->and($dom->querySelector('a.button')?->getAttribute('href'))->toBe($url)
            ->and(invHtmlVisible($dom))->toContain('Doklad můžete zaplatit online kartou:')
            ->and(invHtmlVisible($dom))->toContain('Pokud tlačítko nefunguje, zkopírujte tento odkaz do prohlížeče: '.$url);
    });

    it('shows the button for a proforma and a partly paid document', function (): void {
        $proforma = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']), InvoiceKind::Proforma);

        expect(invHtmlDom(invHtmlSend($proforma))->querySelectorAll('a.button'))->toHaveCount(1);

        $invoice = invHtmlCard();
        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '2000.00', 'paid_on' => '2026-10-14']);

        expect(invHtmlDom(invHtmlSend(Invoice::query()->whereKey($invoice->id)->firstOrFail()))->querySelectorAll('a.button'))->toHaveCount(1);
    });

    it('shows no button and no URL, and the text part no block, for a transfer document', function (): void {
        $email = invHtmlSend(InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'invoice@example.com'])));
        $dom = invHtmlDom($email);

        expect($dom->querySelectorAll('a.button'))->toHaveCount(0)
            ->and((string) $email->getHtmlBody())->not->toContain('/pay/')
            ->and(invHtmlVisible($dom))->not->toContain('Pokud tlačítko nefunguje')
            ->and((string) $email->getTextBody())->not->toContain('Doklad můžete zaplatit');
    });

    it('shows no button once the card switch is off and the same button when it is back on', function (): void {
        $setSwitch = static fn (Invoice $invoice, bool $on): mixed => app(PartnerContext::class)->runAsSystem(static function () use ($invoice, $on): void {
            DB::update('update invoice_admin set card_payment_enabled = ? where invoice_id = ?', [$on ? 'true' : 'false', $invoice->id]);
        });

        $off = invHtmlCard();
        $setSwitch($off, false);
        $offMail = invHtmlSend(Invoice::query()->whereKey($off->id)->firstOrFail());

        expect(invHtmlDom($offMail)->querySelectorAll('a.button'))->toHaveCount(0)
            ->and((string) $offMail->getHtmlBody())->not->toContain('/pay/')
            ->and((string) $offMail->getTextBody())->not->toContain('/pay/');

        // A document that was switched off and on again, with the token it kept, carries the button of that token.
        $back = invHtmlCard();
        $url = PayUrl::forInvoice($back);
        $setSwitch($back, false);
        $setSwitch($back, true);

        expect(invHtmlDom(invHtmlSend(Invoice::query()->whereKey($back->id)->firstOrFail()))->querySelector('a.button')?->getAttribute('href'))->toBe($url);
    });

    it('shows no button for a document paid in full, a cancelled invoice or a credit note', function (): void {
        $paid = invHtmlCard();
        app(RecordPayment::class)->handle($this->admin, $paid, ['amount' => '12500.00', 'paid_on' => '2026-10-14']);

        expect(invHtmlDom(invHtmlSend(Invoice::query()->whereKey($paid->id)->firstOrFail()))->querySelectorAll('a.button'))->toHaveCount(0);

        $cancelled = invHtmlCard();
        $credit = app(IssueCreditNote::class)->storno($this->admin, $cancelled);

        expect(invHtmlDom(invHtmlSend(Invoice::query()->whereKey($cancelled->id)->firstOrFail()))->querySelectorAll('a.button'))->toHaveCount(0)
            ->and(invHtmlDom(invHtmlSend($credit))->querySelectorAll('a.button'))->toHaveCount(0);
    });
});

describe('the language of the document', function (): void {
    it('gives an English document an English layout, lead and button', function (): void {
        $invoice = invHtmlCard('en');
        $dom = invHtmlDom(invHtmlSend($invoice));
        $visible = invHtmlVisible($dom);

        expect($dom->documentElement?->getAttribute('lang'))->toBe('en')
            ->and(trim((string) $dom->querySelector('a.button')?->textContent))->toBe('Pay by card')
            ->and($visible)->toContain('You can pay this document online by card:')
            ->and($visible)->toContain('If the button does not work')
            ->and($visible)->not->toContain('Zaplatit kartou')
            ->and($visible)->not->toContain('Pokud tlačítko');
    });

    it('gives a Czech document the Czech layout and button', function (): void {
        $dom = invHtmlDom(invHtmlSend(invHtmlCard('cs')));

        expect($dom->documentElement?->getAttribute('lang'))->toBe('cs')
            ->and(trim((string) $dom->querySelector('a.button')?->textContent))->toBe('Zaplatit kartou');
    });
});

describe('the attachments', function (): void {
    it('are the PDF of the document under its generated name', function (): void {
        $invoice = invHtmlCard();
        $email = invHtmlSend($invoice);

        $names = array_map(static fn ($part): string => (string) $part->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename'), $email->getAttachments());

        expect($names)->toBe(['faktura-'.$invoice->number.'.pdf'])
            ->and($email->getAttachments()[0]->getBody())->toStartWith('%PDF-');
    });
});
