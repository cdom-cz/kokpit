<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\QueuePaymentConfirmation;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Payments\Actions\AssignCardPayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\Canary;
use Tests\Support\CardPaymentFixtures;
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

/**
 * One try of the sending job through its own middleware and without the failure handling of the queue, like one try of
 * a worker.
 */
function payConfirmTry(string $confirmationId): void
{
    $job = new SendPaymentConfirmation($confirmationId);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (SendPaymentConfirmation $job): mixed => app()->call($job->handle(...)));
}

/**
 * The confirmation row as the database holds it now.
 */
function payConfirmStored(string $confirmationId): PaymentConfirmation
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmation => PaymentConfirmation::query()->findOrFail($confirmationId));
}

/**
 * The number of confirmation rows of the whole database.
 */
function payConfirmCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => PaymentConfirmation::query()->count());
}

/**
 * Makes the mail transport refuse every message, with a text that names the recipient the way a provider answer would.
 */
function payConfirmBreakTransport(string $recipient): void
{
    Event::listen(MessageSending::class, static function () use ($recipient): void {
        throw new TransportException('550 mailbox <'.$recipient.'> rejected by relay');
    });
}

/**
 * A payment recorded with the queue held back, so its confirmation row waits in `queued` and the test decides when the
 * job runs.
 */
function payConfirmHeld(Invoice $invoice, string $amount = '400.00'): PaymentConfirmation
{
    Queue::fake();
    $payment = app(RecordPayment::class)->handle(test()->admin, $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14']);

    return payConfirmRow($payment) ?? throw new LogicException('No confirmation row.');
}

/**
 * Switches the document e-mails of a client on or off as the system, the way a stored change reads for the job.
 */
function payConfirmSwitch(string $clientId, bool $enabled): void
{
    app(PartnerContext::class)->runAsSystem(static fn (): int => Client::query()->whereKey($clientId)->update(['document_emails_enabled' => $enabled]));
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

describe('the paths (Phase 13)', function (): void {
    it('confirms the two payments of an invoice one after the other, each with its own remaining amount', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = payConfirmInvoice($address);
        Mail::getSymfonyTransport()->flush();

        $first = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);
        $second = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '600.00', 'paid_on' => '2026-10-14']);

        $messages = MailFixtures::to($address);

        expect(payConfirmRow($first)?->remaining_minor)->toBe(60000)
            ->and(payConfirmRow($second)?->remaining_minor)->toBe(0)
            ->and(payConfirmRow($first)?->state)->toBe(PaymentConfirmationState::Sent)
            ->and(payConfirmRow($second)?->state)->toBe(PaymentConfirmationState::Sent)
            ->and($messages)->toHaveCount(2)
            ->and($messages[0]->getSubject())->toContain('částečné')
            ->and($messages[1]->getSubject())->not->toContain('částečné')
            ->and(payConfirmVisible((string) $messages[1]->getHtmlBody()))->toContain('Doklad je tímto zcela uhrazen.');
    });

    it('queues the job for the row of the payment with the grace delay and sends nothing yet', function (): void {
        $invoice = payConfirmInvoice(exampleEmail('client-invoice'));
        Queue::fake();
        Mail::getSymfonyTransport()->flush();

        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);
        $row = payConfirmRow($payment);

        Queue::assertPushed(SendPaymentConfirmation::class, static fn (SendPaymentConfirmation $job): bool => $job->confirmationId === $row?->id
            && $job->delay instanceof DateTimeInterface
            && CarbonImmutable::instance($job->delay)->equalTo(now()->addSeconds(QueuePaymentConfirmation::GRACE_SECONDS)));
        Queue::assertPushed(SendPaymentConfirmation::class, 1);

        expect($row?->state)->toBe(PaymentConfirmationState::Queued)
            ->and(MailFixtures::sent())->toBe([]);
    });

    it('sends nothing for a payment deleted inside the grace window, and the job that wakes up later does not fail', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = payConfirmInvoice($address);
        $row = payConfirmHeld($invoice);
        Mail::getSymfonyTransport()->flush();

        $payment = app(PartnerContext::class)->runAsSystem(static fn (): InvoicePayment => InvoicePayment::query()->findOrFail($row->invoice_payment_id));
        app(DeletePayment::class)->handle($this->admin, $payment);

        expect(payConfirmCount())->toBe(0);

        payConfirmTry($row->id);

        expect(MailFixtures::sent())->toBe([]);
    });

    it('mails only the corrected payment when a mistaken one is deleted and the right one recorded', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = payConfirmInvoice($address);
        $wrong = payConfirmHeld($invoice, '300.00');

        $payment = app(PartnerContext::class)->runAsSystem(static fn (): InvoicePayment => InvoicePayment::query()->findOrFail($wrong->invoice_payment_id));
        app(DeletePayment::class)->handle($this->admin, $payment);

        $right = payConfirmHeld($invoice, '400.00');

        payConfirmTry($wrong->id);
        payConfirmTry($right->id);

        $messages = MailFixtures::to($address);

        expect($messages)->toHaveCount(1)
            ->and(payConfirmVisible((string) $messages[0]->getHtmlBody()))->toContain(payConfirmAmount(40000));
    });

    it('leaves no row and no e-mail when the payment is refused for a missing CNB list', function (): void {
        $address = exampleEmail('client-invoice');
        InvoiceFixtures::cnbRate('EUR', '2026-10-09', '25.125');
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['currency' => 'EUR', 'invoice_email' => $address]));
        Mail::getSymfonyTransport()->flush();

        $record = static fn () => app(RecordPayment::class)->handle(test()->admin, $invoice, ['amount' => '100', 'paid_on' => '2026-10-05']);

        expect($record)->toThrow(ValidationException::class)
            ->and(payConfirmCount())->toBe(0)
            ->and(MailFixtures::sent())->toBe([]);
    });

    it('confirms a payment of a proforma and not its carried copy on the converted invoice', function (): void {
        $address = exampleEmail('client-invoice');
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => $address]), [], InvoiceKind::Proforma);
        Mail::getSymfonyTransport()->flush();

        $payment = app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '1000.00', 'paid_on' => '2026-10-14']);

        expect(payConfirmRow($payment)?->state)->toBe(PaymentConfirmationState::Sent)
            ->and(MailFixtures::to($address))->toHaveCount(1);

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);
        $copy = $invoice->payments()->sole();

        expect($copy->carried_from_payment_id)->toBe($payment->id)
            ->and(payConfirmRow($copy))->toBeNull()
            ->and(payConfirmCount())->toBe(1)
            ->and(MailFixtures::to($address))->toHaveCount(1);
    });

    it('confirms the Admin\'s assignment of an unmatched card payment once', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = payConfirmInvoice($address);
        $record = CardPaymentFixtures::unmatched($invoice, ['amount_minor' => $invoice->total_minor]);
        Mail::getSymfonyTransport()->flush();

        app(AssignCardPayment::class)->handle($this->admin, $record, ['invoice_id' => $invoice->id]);

        $payment = $invoice->payments()->sole();
        $messages = MailFixtures::to($address);

        expect(payConfirmRow($payment)?->state)->toBe(PaymentConfirmationState::Sent)
            ->and($messages)->toHaveCount(1)
            ->and(payConfirmVisible((string) $messages[0]->getHtmlBody()))->toContain('Doklad je tímto zcela uhrazen.')
            ->and(payConfirmCount())->toBe(1);
    });

    it('keeps the invoice open and unaudited by a sent confirmation', function (): void {
        $invoice = payConfirmInvoice(exampleEmail('client-invoice'));

        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '1000.00', 'paid_on' => '2026-10-14']);

        $locks = app(PartnerContext::class)->runAsSystem(static fn (): int => Activity::query()
            ->where('subject_type', 'invoice')
            ->where('subject_id', $invoice->id)
            ->where('event', 'invoice_locked')
            ->count());

        expect(payConfirmRow($payment)?->state)->toBe(PaymentConfirmationState::Sent)
            ->and(Invoice::query()->whereKey($invoice->id)->firstOrFail()->isLocked())->toBeFalse()
            ->and($locks)->toBe(0);
    });

    it('keeps the job away from the lock state and the audit of the invoice, and locks only the receipt (Phase 15)', function (): void {
        $source = (string) file_get_contents((string) (new ReflectionClass(SendPaymentConfirmation::class))->getFileName());

        expect($source)->not->toContain('InvoiceLockState')
            ->and($source)->not->toContain('InvoiceAudit')
            ->and(substr_count($source, 'lockForUpdate()'))->toBe(1)
            ->and($source)->toContain('CashReceipt::query()->whereKey($receiptId)->lockForUpdate()');
    });
});

describe('the job (Phase 13)', function (): void {
    it('puts the row back to queued after a refused try, then fails it with a fixed class once the queue gives up', function (): void {
        $address = exampleEmail('client-invoice');
        $row = payConfirmHeld(payConfirmInvoice($address));

        payConfirmBreakTransport($address);

        $thrown = null;

        try {
            payConfirmTry($row->id);
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $stored = payConfirmStored($row->id);

        expect($thrown)->not->toBeNull()
            ->and($thrown?->getMessage())->toBe('The mail transport refused the message.')
            ->and($thrown?->getMessage())->not->toContain($address)
            ->and($thrown?->getMessage())->not->toContain('rejected by relay')
            ->and($stored->state)->toBe(PaymentConfirmationState::Queued)
            ->and($stored->attempts)->toBe(1)
            ->and(MailFixtures::sent())->toBe([]);

        (new SendPaymentConfirmation($row->id))->failed(new TransportException('550 mailbox <'.$address.'> rejected by relay'));

        $failed = payConfirmStored($row->id);
        $encoded = (string) json_encode($failed->getAttributes());

        expect($failed->state)->toBe(PaymentConfirmationState::Failed)
            ->and($failed->failure_code)->toBe(SendPaymentConfirmation::FAILURE_TRANSPORT)
            ->and($failed->sent_at)->toBeNull()
            ->and($encoded)->not->toContain('rejected by relay')
            ->and($encoded)->not->toContain('mailbox');

        // A second report changes nothing.
        (new SendPaymentConfirmation($row->id))->failed(new LogicException('something else'));

        $again = payConfirmStored($row->id);

        expect($again->state)->toBe(PaymentConfirmationState::Failed)
            ->and($again->failure_code)->toBe(SendPaymentConfirmation::FAILURE_TRANSPORT)
            ->and($again->updated_at?->equalTo($failed->updated_at))->toBeTrue();
    });

    it('classes any other failure as unexpected', function (): void {
        $row = payConfirmHeld(payConfirmInvoice(exampleEmail('client-invoice')));

        (new SendPaymentConfirmation($row->id))->failed(new LogicException('something broke'));

        $stored = payConfirmStored($row->id);

        expect($stored->state)->toBe(PaymentConfirmationState::Failed)
            ->and($stored->failure_code)->toBe(SendPaymentConfirmation::FAILURE_UNEXPECTED);
    });

    it('ends a stored recipient that no longer passes the rule as failed invalid_recipient, without a send', function (): void {
        $row = payConfirmHeld(payConfirmInvoice(exampleEmail('client-invoice')));

        app(PartnerContext::class)->runAsSystem(static fn (): int => PaymentConfirmation::query()->whereKey($row->id)->update(['recipient' => 'not an address']));

        payConfirmTry($row->id);

        $stored = payConfirmStored($row->id);

        expect($stored->state)->toBe(PaymentConfirmationState::Failed)
            ->and($stored->failure_code)->toBe(SendPaymentConfirmation::FAILURE_INVALID_RECIPIENT)
            ->and(MailFixtures::sent())->toBe([]);
    });

    it('never sends a finished row again, and does nothing for an id that is no UUID', function (): void {
        $address = exampleEmail('client-invoice');
        $row = payConfirmHeld(payConfirmInvoice($address));
        Mail::getSymfonyTransport()->flush();

        payConfirmTry($row->id);
        payConfirmTry($row->id);
        SendPaymentConfirmation::dispatchSync($row->id);

        $stored = payConfirmStored($row->id);

        expect($stored->state)->toBe(PaymentConfirmationState::Sent)
            ->and($stored->attempts)->toBe(1)
            ->and(MailFixtures::to($address))->toHaveCount(1);

        payConfirmTry('not-a-uuid');
        (new SendPaymentConfirmation('not-a-uuid'))->failed(new LogicException('x'));

        expect(MailFixtures::to($address))->toHaveCount(1);
    });
});

describe('a client with document e-mails switched off (Phase 16, D-06)', function (): void {
    it('records the payment and its income as always, writes the confirmation as skipped with the address kept and queues no job', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => $address, 'document_emails_enabled' => false]));
        Queue::fake();
        Mail::getSymfonyTransport()->flush();

        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);
        $row = payConfirmRow($payment);

        Queue::assertNotPushed(SendPaymentConfirmation::class);

        expect($payment->exists)->toBeTrue()
            ->and($payment->transaction()->count())->toBe(1)
            ->and($row?->state)->toBe(PaymentConfirmationState::Skipped)
            ->and($row?->recipient)->toBe($address)
            ->and($row?->sent_at)->toBeNull()
            ->and($row?->failure_code)->toBeNull()
            ->and($row?->attempts)->toBe(0)
            ->and($row?->remaining_minor)->toBe($invoice->total_minor - 40000)
            ->and(MailFixtures::sent())->toBe([]);
    });

    it('issues the cash receipt of a cash payment too and leaves it open', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => $address, 'document_emails_enabled' => false]));
        Queue::fake();

        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14', 'method' => 'cash']);
        $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->first();

        Queue::assertNotPushed(SendPaymentConfirmation::class);

        expect($receipt)->not->toBeNull()
            ->and($receipt?->isLocked())->toBeFalse()
            ->and($receipt?->isCancelled())->toBeFalse()
            ->and(payConfirmRow($payment)?->state)->toBe(PaymentConfirmationState::Skipped)
            ->and(payConfirmRow($payment)?->recipient)->toBe($address);
    });

    it('also skips a client that has no usable e-mail and has the switch off, with no recipient', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => null, 'document_emails_enabled' => false]));
        Queue::fake();

        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);

        Queue::assertNotPushed(SendPaymentConfirmation::class);

        expect(payConfirmRow($payment)?->state)->toBe(PaymentConfirmationState::Skipped)
            ->and(payConfirmRow($payment)?->recipient)->toBeNull();
    });

    it('still queues the delayed job for a client with the switch on', function (): void {
        $invoice = payConfirmInvoice(exampleEmail('client-invoice'));
        Queue::fake();

        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-14']);

        Queue::assertPushed(SendPaymentConfirmation::class, 1);

        expect(payConfirmRow($payment)?->state)->toBe(PaymentConfirmationState::Queued);
    });
});

describe('a confirmation still waiting when the switch goes off (Phase 16, D-07, D-08)', function (): void {
    it('is skipped by its job without a send or a failure, and switching on again revives nothing', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = payConfirmInvoice($address);
        $row = payConfirmHeld($invoice);
        Mail::getSymfonyTransport()->flush();

        payConfirmSwitch($invoice->client_id, false);
        payConfirmTry($row->id);

        $stored = payConfirmStored($row->id);

        expect($stored->state)->toBe(PaymentConfirmationState::Skipped)
            ->and($stored->attempts)->toBe(1)
            ->and($stored->sent_at)->toBeNull()
            ->and($stored->failure_code)->toBeNull()
            ->and($stored->recipient)->toBe($address)
            ->and(MailFixtures::sent())->toBe([]);

        payConfirmSwitch($invoice->client_id, true);
        payConfirmTry($row->id);

        $again = payConfirmStored($row->id);

        expect($again->state)->toBe(PaymentConfirmationState::Skipped)
            ->and($again->attempts)->toBe(1)
            ->and(MailFixtures::sent())->toBe([]);
    });

    it('still sends the confirmation of a client whose switch stays on', function (): void {
        $address = exampleEmail('client-invoice');
        $row = payConfirmHeld(payConfirmInvoice($address));
        Mail::getSymfonyTransport()->flush();

        payConfirmTry($row->id);

        expect(payConfirmStored($row->id)->state)->toBe(PaymentConfirmationState::Sent)
            ->and(MailFixtures::to($address))->toHaveCount(1);
    });

    it('re-checks right before the transport call: a switch turned off while the payment is read stops the send', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = payConfirmInvoice($address);
        $row = payConfirmHeld($invoice);
        Mail::getSymfonyTransport()->flush();
        $flipped = 0;

        // The job reads the payment between its two checks: the first sees the switch on, the second must see it off.
        Event::listen('eloquent.retrieved: '.InvoicePayment::class, static function () use ($invoice, &$flipped): void {
            if ($flipped === 0) {
                $flipped++;
                payConfirmSwitch($invoice->client_id, false);
            }
        });

        payConfirmTry($row->id);

        $stored = payConfirmStored($row->id);

        expect($flipped)->toBe(1)
            ->and($stored->state)->toBe(PaymentConfirmationState::Skipped)
            ->and($stored->sent_at)->toBeNull()
            ->and($stored->failure_code)->toBeNull()
            ->and(MailFixtures::sent())->toBe([]);
    });
});
