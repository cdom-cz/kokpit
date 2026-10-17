<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;

/*
 * Sending a document by e-mail (IN-04; D-22; ruling R-24-1): validated queuing with defaults in the language of the
 * document, an idempotent job that attaches the stored PDF and, when wanted, the work report of the linked time, and
 * a failure that is visible and clean. Every address, name and amount is fictional.
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
 * The messages the array transport holds, as Symfony e-mails.
 *
 * @return list<Email>
 */
function invMailSent(): array
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
 * The file names attached to a message.
 *
 * @return list<string>
 */
function invMailAttachmentNames(Email $email): array
{
    return array_map(static fn ($part): string => (string) $part->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename'), $email->getAttachments());
}

/**
 * An invoice of September time at 1 200 an hour, issued, with its entries linked and billed.
 *
 * @return array{invoice: Invoice, client: Client}
 */
function invMailInvoiceWithTime(string $language = 'cs'): array
{
    $client = InvoiceFixtures::client(['invoice_language' => $language]);
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Mail project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1200',
    ]);

    foreach ([['2026-09-10 08:00:00', '2026-09-10 09:00:00'], ['2026-09-11 08:00:00', '2026-09-11 09:30:00']] as [$start, $end]) {
        $factory = TimeEntry::factory()->forProject($project);
        app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => $factory->create([
            'user_id' => test()->admin->id,
            'started_at' => CarbonImmutable::parse($start, 'UTC'),
            'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ]));
    }

    $draft = app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
    ]);

    return ['invoice' => app(IssueDocument::class)->handle(test()->admin, $draft), 'client' => $client];
}

/**
 * The data of the dialog as an Admin submits it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{recipient: string, subject: string, body: string, attach_work_report: bool}
 */
function invMailData(Invoice $invoice, array $overrides = []): array
{
    $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);

    return [
        'recipient' => (string) $defaults['recipient'],
        'subject' => $defaults['subject'],
        'body' => $defaults['body'],
        'attach_work_report' => $defaults['attach_work_report'],
        ...$overrides,
    ];
}

/**
 * Runs the job the way a worker does (the sync connection, with its middleware).
 */
function invMailRun(string $emailId): void
{
    SendInvoiceEmail::dispatchSync($emailId);
}

function invMailRow(string $emailId): InvoiceEmail
{
    return app(PartnerContext::class)->runAsSystem(static fn (): InvoiceEmail => InvoiceEmail::query()->findOrFail($emailId));
}

describe('the tracer: queue the e-mail of an issued invoice and send it', function (): void {
    it('pre-fills the dialog from the client and the language of the document', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'invoice@example.com']));

        $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);

        expect($defaults['recipient'])->toBe('invoice@example.com')
            ->and($defaults['subject'])->toBe('Faktura '.$invoice->number)
            ->and($defaults['body'])->toStartWith("Dobrý den,\n\nv příloze posíláme fakturu č. ".$invoice->number)
            ->and($defaults['body'])->toContain(Money::ofMinor($invoice->total_minor, $invoice->currency)->format('cs'))
            ->and($defaults['body'])->toContain($invoice->due_date->format('j. n. Y'))
            ->and($defaults['body'])->toEndWith("S pozdravem\nExample Supplier s.r.o.")
            ->and($defaults['attach_work_report'])->toBeFalse()
            ->and($defaults['has_work_report'])->toBeFalse();
    });

    it('pre-fills an English proforma in English', function (): void {
        $proforma = InvoiceFixtures::issued(
            InvoiceFixtures::client(['invoice_language' => 'en']),
            [InvoiceFixtures::item('Advance', '1', '5000.00', 'pcs')],
            InvoiceKind::Proforma,
        );

        $defaults = app(QueueInvoiceEmail::class)->defaults($proforma);

        expect($defaults['subject'])->toBe('Proforma invoice '.$proforma->number)
            ->and($defaults['body'])->toStartWith("Hello,\n\nplease find attached proforma invoice no. ".$proforma->number)
            ->and($defaults['body'])->toContain($proforma->due_date->format('Y-m-d'))
            ->and($defaults['body'])->toContain(Money::ofMinor($proforma->total_minor, $proforma->currency)->format('en'));
    });

    it('leaves the recipient empty when the client has no invoice e-mail', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => null]));

        expect(app(QueueInvoiceEmail::class)->defaults($invoice)['recipient'])->toBeNull();
    });

    it('stores a queued row and dispatches the job after the commit', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $stored = invMailRow($row->id);

        expect($stored->state)->toBe(InvoiceEmailState::Queued)
            ->and($stored->invoice_id)->toBe($invoice->id)
            ->and($stored->recipient)->toBe($invoice->client->invoice_email)
            ->and($stored->subject)->toBe('Faktura '.$invoice->number)
            ->and($stored->attach_work_report)->toBeFalse()
            ->and($stored->attempts)->toBe(0)
            ->and($stored->created_by)->toBe($this->admin->id);

        Queue::assertPushed(SendInvoiceEmail::class, 1);
        Queue::assertPushed(SendInvoiceEmail::class, static fn (SendInvoiceEmail $job): bool => $job->emailId === $row->id);
    });

    it('sends one plain-text mail with the stored PDF and marks the row sent', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'invoice@example.com']));

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $messages = invMailSent();

        expect($messages)->toHaveCount(1);

        $email = $messages[0];

        expect(array_map(static fn ($address): string => $address->getAddress(), $email->getTo()))->toBe(['invoice@example.com'])
            ->and($email->getSubject())->toBe('Faktura '.$invoice->number)
            ->and($email->getHtmlBody())->toBeNull()
            ->and($email->getTextBody())->toStartWith("Dobrý den,\n\nv příloze posíláme fakturu č. ".$invoice->number)
            ->and(invMailAttachmentNames($email))->toBe(['faktura-'.$invoice->number.'.pdf'])
            ->and($email->getAttachments()[0]->getBody())->toStartWith('%PDF-');

        $stored = invMailRow($row->id);

        expect($stored->state)->toBe(InvoiceEmailState::Sent)
            ->and($stored->sent_at)->not->toBeNull()
            ->and($stored->attempts)->toBe(1)
            ->and($stored->failure_code)->toBeNull();
    });

    it('sends nothing when the row was already sent', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        expect(invMailSent())->toHaveCount(1);

        invMailRun($row->id);
        invMailRun($row->id);

        expect(invMailSent())->toHaveCount(1)
            ->and(invMailRow($row->id)->attempts)->toBe(1);
    });

    it('attaches the work report of the linked time, without any price', function (): void {
        ['invoice' => $invoice] = invMailInvoiceWithTime();

        $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);

        expect($defaults['has_work_report'])->toBeTrue()
            ->and($defaults['attach_work_report'])->toBeTrue();

        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $messages = invMailSent();

        expect($messages)->toHaveCount(1)
            ->and(invMailAttachmentNames($messages[0]))->toBe(['faktura-'.$invoice->number.'.pdf', 'pracovni-vykaz-'.$invoice->number.'.pdf']);

        $report = $messages[0]->getAttachments()[1]->getBody();

        expect($report)->toStartWith('%PDF-');

        $text = PdfText::extract($report);

        if ($text !== null) {
            expect($text)->toContain('Pracovní výkaz')
                ->and($text)->not->toContain('Kč')
                ->and($text)->not->toContain('1 200')
                ->and($text)->not->toContain('1200');
        }
    });

    it('attaches only the invoice when the work report is not ticked', function (): void {
        ['invoice' => $invoice] = invMailInvoiceWithTime();

        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, ['attach_work_report' => false]));

        expect(invMailAttachmentNames(invMailSent()[0]))->toBe(['faktura-'.$invoice->number.'.pdf']);
    });

    it('names the work report in English for an English document', function (): void {
        ['invoice' => $invoice] = invMailInvoiceWithTime('en');

        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        expect(invMailAttachmentNames(invMailSent()[0]))->toBe(['invoice-'.$invoice->number.'.pdf', 'work-report-'.$invoice->number.'.pdf']);
    });
});

describe('the copy', function (): void {
    it('has the same keys in Czech and English', function (): void {
        $keys = static function (string $locale): array {
            $flat = Arr::dot(require lang_path($locale.'/invoice_mail.php'));

            return array_keys($flat);
        };

        expect($keys('en'))->toBe($keys('cs'));
    });
});
