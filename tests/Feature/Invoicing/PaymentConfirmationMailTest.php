<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Mail\PaymentConfirmationMail;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * The wording and the content of the payment confirmation (Phase 13, D-05, D-06; P13-04, P13-10): partial and final
 * payment, an overpayment, both languages, a foreign currency and a document number that holds Markdown characters.
 * Every name, address and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    Mail::getSymfonyTransport()->flush();
});

/**
 * Sends the confirmation of a payment through the real Mailable and returns the one message that left.
 */
function payMailSend(string $number, Money $amount, int $remainingMinor, string $language): Email
{
    $address = exampleEmail('confirm-recipient');

    Mail::getSymfonyTransport()->flush();
    Mail::to($address)->send(PaymentConfirmationMail::forPayment($number, $amount, CarbonImmutable::parse('2026-10-14', 'Europe/Prague'), $remainingMinor, $language));

    $messages = MailFixtures::to($address);

    expect($messages)->toHaveCount(1);

    return $messages[0];
}

/**
 * White space collapsed, a no-break space (the grouping of a formatted amount) counted as a space.
 */
function payMailFlat(string $text): string
{
    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
}

function payMailAmount(int $minor, string $currency, string $language): string
{
    return payMailFlat(Money::ofMinor($minor, $currency)->format($language));
}

function payMailDom(Email $email): HTMLDocument
{
    return HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);
}

/**
 * The text a reader sees in the HTML part, without style elements.
 */
function payMailVisible(Email $email): string
{
    $dom = payMailDom($email);

    foreach ($dom->querySelectorAll('style, script') as $element) {
        $element->remove();
    }

    return payMailFlat((string) $dom->body?->textContent);
}

describe('the Czech wording', function (): void {
    it('reads as a partial payment with the amount, the date, the number and what is still owed', function (): void {
        $email = payMailSend('INV-0042', Money::ofMinor(40000, 'CZK'), 60000, 'cs');
        $visible = payMailVisible($email);

        expect($email->getSubject())->toBe('Potvrzení částečné platby dokladu INV-0042')
            ->and($visible)->toContain('Dobrý den,')
            ->and($visible)->toContain('děkujeme, přijali jsme vaši platbu '.payMailAmount(40000, 'CZK', 'cs').' ze dne 14. 10. 2026 k dokladu č. INV-0042.')
            ->and($visible)->toContain('Zbývá doplatit '.payMailAmount(60000, 'CZK', 'cs').'.')
            ->and($visible)->not->toContain('zcela uhrazen')
            ->and($visible)->toContain('S pozdravem Example Supplier s.r.o.')
            ->and($email->getAttachments())->toBe([]);
    });

    it('reads as paid in full when nothing remains, and has no remaining sentence', function (): void {
        $email = payMailSend('INV-0042', Money::ofMinor(100000, 'CZK'), 0, 'cs');
        $visible = payMailVisible($email);

        expect($email->getSubject())->toBe('Potvrzení platby dokladu INV-0042')
            ->and($visible)->toContain('Doklad je tímto zcela uhrazen.')
            ->and($visible)->not->toContain('Zbývá doplatit')
            ->and($email->getAttachments())->toBe([]);
    });

    it('reads as paid in full for an overpayment, which leaves nothing owed', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => $address]));
        Mail::getSymfonyTransport()->flush();

        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '1500.00', 'paid_on' => '2026-10-14']);

        $messages = MailFixtures::to($address);

        expect($messages)->toHaveCount(1)
            ->and($messages[0]->getSubject())->not->toContain('částečné')
            ->and(payMailVisible($messages[0]))->toContain('Doklad je tímto zcela uhrazen.')
            ->and(payMailVisible($messages[0]))->not->toContain('Zbývá doplatit');
    });
});

describe('the English wording', function (): void {
    it('reads as a partial payment with the date as Y-m-d, the English layout labels and lang en', function (): void {
        $email = payMailSend('INV-0042', Money::ofMinor(40000, 'CZK'), 60000, 'en');
        $visible = payMailVisible($email);

        expect($email->getSubject())->toBe('Partial payment received for document INV-0042')
            ->and(payMailDom($email)->documentElement?->getAttribute('lang'))->toBe('en')
            ->and($visible)->toContain('Hello,')
            ->and($visible)->toContain('thank you, we have received your payment of '.payMailAmount(40000, 'CZK', 'en').' dated 2026-10-14 for document no. INV-0042.')
            ->and($visible)->toContain('The remaining amount is '.payMailAmount(60000, 'CZK', 'en').'.')
            ->and($visible)->toContain('Kind regards Example Supplier s.r.o.')
            ->and($visible)->toContain('Company ID')
            ->and($visible)->not->toContain('IČO');
    });

    it('reads as paid in full when nothing remains', function (): void {
        $email = payMailSend('INV-0042', Money::ofMinor(100000, 'CZK'), 0, 'en');

        expect($email->getSubject())->toBe('Payment received for document INV-0042')
            ->and(payMailVisible($email))->toContain('The document is now paid in full.')
            ->and(payMailVisible($email))->not->toContain('remaining amount');
    });

    it('writes to an English client in English even when the document itself is Czech', function (): void {
        $address = exampleEmail('client-invoice');
        $client = InvoiceFixtures::client(['invoice_email' => $address, 'invoice_language' => 'cs']);
        $invoice = InvoiceFixtures::issued($client);

        expect($invoice->language->value)->toBe('cs');

        app(PartnerContext::class)->runAsSystem(static fn (): bool => Client::query()->whereKey($client->id)->firstOrFail()->forceFill(['invoice_language' => 'en'])->save());
        Mail::getSymfonyTransport()->flush();

        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);

        $messages = MailFixtures::to($address);

        expect($messages)->toHaveCount(1)
            ->and($messages[0]->getSubject())->toBe('Partial payment received for document '.$invoice->number)
            ->and(payMailVisible($messages[0]))->toContain('dated 2026-10-14');
    });
});

describe('the content', function (): void {
    it('prints both amounts in the currency of the document', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.00');
        $address = exampleEmail('client-invoice');
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => $address, 'currency' => 'EUR']));
        Mail::getSymfonyTransport()->flush();

        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '250.00', 'paid_on' => '2026-10-14']);

        $messages = MailFixtures::to($address);

        expect($messages)->toHaveCount(1)
            ->and(payMailVisible($messages[0]))->toContain(payMailAmount(25000, 'EUR', 'cs'))
            ->and(payMailVisible($messages[0]))->toContain(payMailAmount($invoice->total_minor - 25000, 'EUR', 'cs'))
            ->and(payMailVisible($messages[0]))->not->toContain('Kč');
    });

    it('shows a document number with an asterisk and an underscore literally in both parts', function (): void {
        $email = payMailSend('AB*12_34', Money::ofMinor(40000, 'CZK'), 60000, 'cs');
        $dom = payMailDom($email);

        foreach (['em', 'strong', 'code', 'a'] as $tag) {
            expect($dom->querySelectorAll('table.inner-body '.$tag))->toHaveCount(0, 'unexpected '.$tag.' element');
        }

        expect(payMailVisible($email))->toContain('dokladu č. AB*12_34.')
            ->and((string) $email->getTextBody())->toContain('dokladu č. AB*12_34.')
            ->and((string) $email->getTextBody())->not->toContain('\\');
    });

    it('carries the same facts and the supplier name in the text part, and no attachment', function (): void {
        $email = payMailSend('INV-0042', Money::ofMinor(40000, 'CZK'), 60000, 'cs');
        $text = payMailFlat((string) $email->getTextBody());

        expect($text)->toContain('Dobrý den,')
            ->and($text)->toContain(payMailAmount(40000, 'CZK', 'cs'))
            ->and($text)->toContain('14. 10. 2026')
            ->and($text)->toContain('INV-0042')
            ->and($text)->toContain('Zbývá doplatit '.payMailAmount(60000, 'CZK', 'cs'))
            ->and($text)->toContain('Example Supplier s.r.o.')
            ->and($text)->not->toContain('<')
            ->and($email->getAttachments())->toBe([]);
    });

    it('offers no pay button even for a document that could be paid by card', function (): void {
        $email = payMailSend('INV-0042', Money::ofMinor(40000, 'CZK'), 60000, 'cs');

        expect(payMailDom($email)->querySelectorAll('a.button'))->toHaveCount(0)
            ->and((string) $email->getHtmlBody())->not->toContain('/pay/');
    });
});

describe('the copy', function (): void {
    it('has the same keys in Czech and English (lang/cs/payment_mail.php, lang/en/payment_mail.php)', function (): void {
        $keys = static fn (string $locale): array => array_keys(Arr::dot(require lang_path($locale.'/payment_mail.php')));

        expect($keys('en'))->toBe($keys('cs'));
    });
});
