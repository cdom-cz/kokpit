<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * The payment confirmation e-mail (Phase 13, D-05, D-06; P13-04, P13-05): every payment credited through
 * RecordPayment::recordLocked() queues one e-mail to the client, written in the client's language, with the document
 * number, the amount, the date and, for a partial payment, the remaining amount. Every name, address and amount is
 * fictional.
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
 * An issued invoice of 1 000,00 CZK for a Czech client with the given invoice e-mail (null for none).
 */
function payConfirmInvoice(?string $email, string $language = 'cs'): Invoice
{
    return InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => $email, 'invoice_language' => $language]));
}

/**
 * The confirmation row of a payment, read as the system (the model is Admin-only).
 */
function payConfirmRow(InvoicePayment $payment): ?PaymentConfirmation
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ?PaymentConfirmation => PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->first());
}

/**
 * White space collapsed, a no-break space (the grouping of a formatted amount) counted as a space.
 */
function payConfirmFlat(string $text): string
{
    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
}

/**
 * A formatted amount as it reads after payConfirmFlat().
 */
function payConfirmAmount(int $minor, string $currency = 'CZK', string $language = 'cs'): string
{
    return payConfirmFlat(Money::ofMinor($minor, $currency)->format($language));
}

/**
 * The text a reader sees in the HTML part, without style elements.
 */
function payConfirmVisible(string $html): string
{
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    foreach ($dom->querySelectorAll('style, script') as $element) {
        $element->remove();
    }

    return payConfirmFlat((string) $dom->body?->textContent);
}

describe('the tracer (Phase 13)', function (): void {
    it('tells the client once about a partial payment, with the number, the amount, the date and what remains, and leaves the invoice open', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = payConfirmInvoice($address);
        Mail::getSymfonyTransport()->flush();

        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);

        $row = payConfirmRow($payment);

        expect($row)->not->toBeNull()
            ->and($row?->state)->toBe(PaymentConfirmationState::Sent)
            ->and($row?->attempts)->toBe(1)
            ->and($row?->recipient)->toBe($address)
            ->and($row?->language)->toBe('cs')
            ->and($row?->remaining_minor)->toBe($invoice->total_minor - 40000);

        $messages = MailFixtures::to($address);

        expect($messages)->toHaveCount(1);

        $email = $messages[0];
        $html = (string) $email->getHtmlBody();
        $visible = payConfirmVisible($html);

        expect($email->getSubject())->toContain((string) $invoice->number)
            ->and($email->getSubject())->toContain('částečné')
            ->and($visible)->toContain(payConfirmAmount(40000))
            ->and($visible)->toContain(payConfirmAmount($invoice->total_minor - 40000))
            ->and($visible)->toContain('14. 10. 2026')
            ->and($visible)->toContain('Example Supplier s.r.o.')
            ->and($email->getAttachments())->toBe([])
            ->and(Invoice::query()->whereKey($invoice->id)->firstOrFail()->isLocked())->toBeFalse();
    });

    it('records a client without an invoice e-mail as skipped, mails nobody and still takes the payment', function (): void {
        $invoice = payConfirmInvoice(null);
        Mail::getSymfonyTransport()->flush();

        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);

        $row = payConfirmRow($payment);

        expect($payment->exists)->toBeTrue()
            ->and($row?->state)->toBe(PaymentConfirmationState::Skipped)
            ->and($row?->recipient)->toBeNull()
            ->and(MailFixtures::sent())->toBe([]);
    });
});
