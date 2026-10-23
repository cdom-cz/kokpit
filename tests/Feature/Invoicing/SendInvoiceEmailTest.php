<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
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

/**
 * Makes the mail transport refuse every message, with a text that names the recipient the way a provider answer would.
 */
function invMailBreakTransport(string $recipient): void
{
    Event::listen(MessageSending::class, static function () use ($recipient): void {
        throw new TransportException('550 mailbox <'.$recipient.'> rejected by relay');
    });
}

/**
 * Runs the job through its own middleware and without the queue's failure handling, like one try of a worker.
 */
function invMailTry(string $emailId): void
{
    $job = new SendInvoiceEmail($emailId);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (SendInvoiceEmail $job): mixed => app()->call($job->handle(...)));
}

/**
 * The failed-send notices of a user, as stored.
 *
 * @return list<array<string, mixed>>
 */
function invMailBell(User $user): array
{
    return $user->notifications()->get()
        ->map(static fn ($notification): array => (array) $notification->data)
        ->filter(static fn (array $data): bool => ($data['title'] ?? null) === __('invoices.email.notice.title'))
        ->values()
        ->all();
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

    it('sends one mail with an HTML part, a plain-text part and the stored PDF and marks the row sent', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'invoice@example.com']));

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $messages = invMailSent();

        expect($messages)->toHaveCount(1);

        $email = $messages[0];

        expect(array_map(static fn ($address): string => $address->getAddress(), $email->getTo()))->toBe(['invoice@example.com'])
            ->and($email->getSubject())->toBe('Faktura '.$invoice->number)
            ->and((string) $email->getHtmlBody())->toContain('Example Supplier s.r.o.')
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
            ->and($defaults['attach_work_report'])->toBeFalse();

        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, ['attach_work_report' => true]));

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

        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, ['attach_work_report' => true]));

        expect(invMailAttachmentNames(invMailSent()[0]))->toBe(['invoice-'.$invoice->number.'.pdf', 'work-report-'.$invoice->number.'.pdf']);
    });
});

describe('a send that keeps failing', function (): void {
    it('marks the row failed with the transport class and sends one clean bell notice to every Admin', function (): void {
        $second = Canary::admin();
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'recipient@example.com']));
        $data = invMailData($invoice);

        invMailBreakTransport('recipient@example.com');

        // No Queue::fake(): the sync connection runs the job like a worker, with its middleware and failed().
        expect(fn () => app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, $data))->toThrow(RuntimeException::class);

        // The generic failed-job alert (Phase 3) is deferred to the end of the request; deliver it now so that it is
        // part of what the Admins can read in the bell.
        app(DeferredCallbackCollection::class)->invoke();

        /** @var InvoiceEmail $stored */
        $stored = app(PartnerContext::class)->runAsSystem(static fn (): ?InvoiceEmail => InvoiceEmail::query()->where('invoice_id', $invoice->id)->first());

        expect($stored->state)->toBe(InvoiceEmailState::Failed)
            ->and($stored->failure_code)->toBe('transport')
            ->and($stored->sent_at)->toBeNull()
            ->and(invMailSent())->toBe([]);

        foreach ([$this->admin, $second] as $admin) {
            $bell = invMailBell($admin);

            expect($bell)->toHaveCount(1)
                ->and($bell[0]['body'])->toBe(__('invoices.email.notice.body', ['number' => $invoice->number]));

            // Everything in the bell: the notice of this plan and the technical alert next to it.
            $encoded = (string) json_encode($admin->notifications()->get()->pluck('data'));

            expect($encoded)->not->toContain('recipient@example.com')
                ->and($encoded)->not->toContain('rejected by relay')
                ->and($encoded)->not->toContain(Money::ofMinor($invoice->total_minor, $invoice->currency)->format('cs'))
                ->and($encoded)->not->toContain('1000');
        }
    });

    it('puts the row back to queued between tries and fails it once the queue gives up', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        invMailBreakTransport('recipient@example.com');

        expect(fn () => invMailTry($row->id))->toThrow(RuntimeException::class);
        expect(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Queued)
            ->and(invMailRow($row->id)->attempts)->toBe(1)
            ->and(invMailBell($this->admin))->toBe([]);

        expect(fn () => invMailTry($row->id))->toThrow(RuntimeException::class);
        expect(invMailRow($row->id)->attempts)->toBe(2);

        // The worker calls failed() once the attempts are used up; a second call changes and tells nothing.
        $failure = new TransportException('relay refused');
        (new SendInvoiceEmail($row->id))->failed($failure);
        (new SendInvoiceEmail($row->id))->failed($failure);

        expect(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Failed)
            ->and(invMailRow($row->id)->failure_code)->toBe('transport')
            ->and(invMailBell($this->admin))->toHaveCount(1);
    });

    it('classes any other failure as unexpected', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        (new SendInvoiceEmail($row->id))->failed(new LogicException('something broke'));

        expect(invMailRow($row->id)->failure_code)->toBe('unexpected');
    });

    it('fails a row at once, with attachment_missing, when the stored PDF object is gone', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        foreach (Storage::disk('local')->allFiles() as $file) {
            Storage::disk('local')->delete($file);
        }

        invMailTry($row->id);

        $stored = invMailRow($row->id);

        expect($stored->state)->toBe(InvoiceEmailState::Failed)
            ->and($stored->failure_code)->toBe('attachment_missing')
            ->and(invMailSent())->toBe([])
            ->and(invMailBell($this->admin))->toHaveCount(1);
    });

    it('fails a row with attachment_missing when the document has no PDF link', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        // The sanctioned way to lose the link: the file of the PDF is deleted (DO-03).
        $document = $invoice->admin?->pdfDocument;
        assert($document instanceof Document);
        app(DeleteDocument::class)->handle($this->admin, $document, true);

        expect(app(PartnerContext::class)->runAsSystem(static fn (): ?string => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->value('pdf_document_id')))->toBeNull();

        invMailTry($row->id);

        expect(invMailRow($row->id)->failure_code)->toBe('attachment_missing')
            ->and(invMailSent())->toBe([]);
    });

    it('gives a partner of the client no notice and no row', function (): void {
        $client = InvoiceFixtures::client();
        $partner = Canary::partnerFor($client->id);
        $invoice = InvoiceFixtures::issued($client);
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        (new SendInvoiceEmail($row->id))->failed(new TransportException('relay refused'));

        $this->actingAs($partner);

        expect($partner->notifications()->count())->toBe(0)
            ->and(InvoiceEmail::query()->count())->toBe(0);
    });
});

describe('a queued e-mail older than the stale threshold', function (): void {
    /**
     * Moves the last move of a row to the given number of seconds ago, the way a lost job leaves it.
     */
    function invMailAge(string $emailId, int $seconds): void
    {
        app(PartnerContext::class)->runAsSystem(static fn (): int => InvoiceEmail::query()->whereKey($emailId)->update(['updated_at' => CarbonImmutable::now()->subSeconds($seconds)]));
    }

    it('gives the row up as failed with the class stale, sends nothing and locks nothing', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));
        invMailAge($row->id, (int) config('kokpit.health.unsent_invoice_email_stale_after') + 60);

        invMailTry($row->id);

        expect(invMailSent())->toBe([])
            ->and(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Failed)
            ->and(invMailRow($row->id)->failure_code)->toBe('stale')
            ->and(invMailRow($row->id)->attempts)->toBe(0)
            ->and($invoice->refresh()->lock_state)->toBe(InvoiceLockState::Open)
            ->and(invMailBell($this->admin))->toHaveCount(1);

        // A second run changes and tells nothing.
        invMailTry($row->id);

        expect(invMailSent())->toBe([])
            ->and(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Failed)
            ->and(invMailBell($this->admin))->toHaveCount(1);
    });

    it('still sends a queued row a minute short of the threshold and locks the document', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));
        invMailAge($row->id, (int) config('kokpit.health.unsent_invoice_email_stale_after') - 60);

        invMailTry($row->id);

        expect(invMailSent())->toHaveCount(1)
            ->and(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Sent)
            ->and($invoice->refresh()->lock_state)->toBe(InvoiceLockState::Locked)
            ->and(invMailBell($this->admin))->toBe([]);
    });

    it('claims the row under the invoice row lock', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        invMailTry($row->id);

        $lock = array_find_key($queries, static fn (string $sql): bool => str_contains($sql, 'from "invoices"') && str_contains($sql, 'for update'));
        $claim = array_find_key($queries, static fn (string $sql): bool => str_starts_with($sql, 'update "invoice_emails"') && str_contains($sql, '"state"'));

        expect($lock)->not->toBeNull()
            ->and($claim)->not->toBeNull()
            ->and($lock)->toBeLessThan($claim);
    });
});

describe('a resend', function (): void {
    it('is a new row; the first stays failed and the latest row decides', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'recipient@example.com']));
        Queue::fake();
        $first = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));
        (new SendInvoiceEmail($first->id))->failed(new TransportException('relay refused'));

        $this->travel(5)->seconds();
        $second = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));
        invMailTry($second->id);

        expect($second->id)->not->toBe($first->id)
            ->and(invMailRow($first->id)->state)->toBe(InvoiceEmailState::Failed)
            ->and(invMailRow($second->id)->state)->toBe(InvoiceEmailState::Sent)
            ->and($invoice->emails()->count())->toBe(2)
            ->and($invoice->emails()->first()?->id)->toBe($second->id)
            ->and(invMailSent())->toHaveCount(1);
    });
});

describe('the lock: only a successfully sent document e-mail locks the invoice', function (): void {
    it('locks the invoice together with the sent mark when the transport accepts the message', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        expect($invoice->lock_state)->toBe(InvoiceLockState::Open);

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        expect(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Sent)
            ->and($invoice->refresh()->lock_state)->toBe(InvoiceLockState::Locked)
            ->and($invoice->isLocked())->toBeTrue();
    });

    it('locks a proforma the same way', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Advance', '1', '5000.00', 'pcs')], InvoiceKind::Proforma);

        app(QueueInvoiceEmail::class)->handle($this->admin, $proforma, invMailData($proforma));

        expect($proforma->refresh()->lock_state)->toBe(InvoiceLockState::Locked);
    });

    it('leaves the invoice open while the e-mail is only queued', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        expect(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Queued)
            ->and($invoice->refresh()->lock_state)->toBe(InvoiceLockState::Open);
    });

    it('leaves the row queued and the invoice open when the transport throws', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'recipient@example.com']));
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        invMailBreakTransport('recipient@example.com');

        expect(fn () => invMailTry($row->id))->toThrow(RuntimeException::class);
        expect(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Queued)
            ->and($invoice->refresh()->lock_state)->toBe(InvoiceLockState::Open);
    });

    it('leaves the invoice open when the queue gives up on the e-mail', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        (new SendInvoiceEmail($row->id))->failed(new TransportException('relay refused'));

        expect(invMailRow($row->id)->state)->toBe(InvoiceEmailState::Failed)
            ->and($invoice->refresh()->lock_state)->toBe(InvoiceLockState::Open);
    });

    it('keeps a locked invoice as it is when another e-mail is sent for it', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $before = $invoice->refresh();
        $snapshot = $before->getAttributes();

        $this->travel(5)->minutes();
        $second = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        expect(invMailRow($second->id)->state)->toBe(InvoiceEmailState::Sent)
            ->and(invMailSent())->toHaveCount(2)
            ->and($invoice->refresh()->getAttributes())->toBe($snapshot);
    });
});

/**
 * The lock rows of the history of a document.
 *
 * @return list<Activity>
 */
function invMailLockRows(Invoice $invoice): array
{
    return Activity::query()
        ->where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', 'invoice_locked')
        ->orderBy('created_at')
        ->get()
        ->all();
}

describe('the lock audit: one history row per lock, written by the send', function (): void {
    it('writes one row with the sender as causer when the send locks the document', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $rows = invMailLockRows($invoice);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->causer_id)->toBe($this->admin->id)
            ->and($rows[0]->attribute_changes?->all())->toBe([
                'attributes' => ['lock_state' => 'locked'],
                'old' => ['lock_state' => 'open'],
            ]);
    });

    it('writes no row for a resend of a locked document', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $this->travel(5)->minutes();
        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        expect(invMailLockRows($invoice))->toHaveCount(1);
    });

    it('writes no row while the e-mail is queued, when the transport throws or when the queue gives up', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'recipient@example.com']));
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        expect(invMailLockRows($invoice))->toBe([]);

        invMailBreakTransport('recipient@example.com');

        expect(fn () => invMailTry($row->id))->toThrow(RuntimeException::class)
            ->and(invMailLockRows($invoice))->toBe([]);

        (new SendInvoiceEmail($row->id))->failed(new TransportException('relay refused'));

        expect(invMailLockRows($invoice))->toBe([]);
    });

    it('locks again with a second row after an unlock and the next successful send, and not before', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $this->travel(5)->minutes();
        $opened = app(UnlockInvoice::class)->handle($this->admin, $invoice, 'Wrong example quantity on line one');

        expect($opened->isLocked())->toBeFalse()
            ->and($invoice->refresh()->isLocked())->toBeFalse()
            ->and(invMailLockRows($invoice))->toHaveCount(1);

        $this->travel(5)->minutes();
        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        expect($invoice->refresh()->isLocked())->toBeTrue()
            ->and(invMailLockRows($invoice))->toHaveCount(2);
    });
});

describe('what the dialog may submit', function (): void {
    it('refuses a recipient that is no address or carries a line break', function (string $recipient): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();

        try {
            app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, ['recipient' => $recipient]));
            $this->fail('The recipient should have been refused.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('recipient')
                ->and($e->errors()['recipient'][0])->toBe(__('invoices.errors.email_recipient'));
        }

        expect(InvoiceEmail::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'not an address' => ['not-an-email'],
        'empty' => [''],
        'blank' => ['   '],
        'line break' => ["invoice@example.com\r\nBcc: other@example.com"],
        'two addresses' => ['one@example.com, two@example.com'],
        'display name' => ['Someone <someone@example.com>'],
    ]);

    it('strips line breaks from the subject before storing it', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, ['subject' => "Invoice\r\nBcc: other@example.com\nnext"]));

        expect(invMailRow($row->id)->subject)->toBe('Invoice Bcc: other@example.com next');
    });

    it('refuses a subject over 200 and a body over 5000 characters, and accepts the limits', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();

        foreach ([['subject', 201], ['body', 5001]] as [$field, $length]) {
            try {
                app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, [$field => str_repeat('a', $length)]));
                $this->fail('The '.$field.' should have been refused.');
            } catch (ValidationException $e) {
                expect($e->errors())->toHaveKey($field);
            }
        }

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, ['subject' => str_repeat('s', 200), 'body' => str_repeat('b', 5000)]));

        expect(mb_strlen(invMailRow($row->id)->subject))->toBe(200)
            ->and(mb_strlen(invMailRow($row->id)->body))->toBe(5000)
            ->and(InvoiceEmail::query()->count())->toBe(1);
    });

    it('refuses an empty subject and an empty body', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        foreach (['subject', 'body'] as $field) {
            expect(fn () => app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, [$field => " \n "])))
                ->toThrow(ValidationException::class);
        }
    });

    it('keeps the typed text as it is in the text part and reads it literally in the HTML part, markup and all', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, ['body' => "Hello <b>there</b> & \"friends\"\0"]));

        $email = invMailSent()[0];

        $html = HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);

        expect(trim((string) $email->getTextBody()))->toBe('Hello <b>there</b> & "friends"')
            ->and($html->querySelectorAll('b'))->toHaveCount(0)
            ->and((string) $html->body?->textContent)->toContain('Hello <b>there</b> & "friends"');
    });

    it('refuses a draft', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '1000.00')]);

        try {
            app(QueueInvoiceEmail::class)->handle($this->admin, $draft, [
                'recipient' => 'invoice@example.com',
                'subject' => 'Subject',
                'body' => 'Body',
                'attach_work_report' => false,
            ]);
            $this->fail('A draft should have been refused.');
        } catch (ValidationException $e) {
            expect($e->errors()['invoice'][0])->toBe(__('invoices.errors.email_not_issued'));
        }

        expect(InvoiceEmail::query()->count())->toBe(0);
    });

    it('refuses a partner', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $data = invMailData($invoice);
        $partner = Canary::partnerFor($client->id);

        expect(fn () => app(QueueInvoiceEmail::class)->handle($partner, $invoice, $data))->toThrow(AuthorizationException::class);
        expect(app(PartnerContext::class)->runAsSystem(static fn (): int => InvoiceEmail::query()->count()))->toBe(0);
    });

    it('ignores the work report flag on a document without linked time', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice, ['attach_work_report' => true]));

        expect(invMailRow($row->id)->attach_work_report)->toBeFalse()
            ->and(invMailAttachmentNames(invMailSent()[0]))->toBe(['faktura-'.$invoice->number.'.pdf']);
    });

    it('sends no work report for a row that asks for one when the entries are gone', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        Queue::fake();
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));
        app(PartnerContext::class)->runAsSystem(static fn (): int => InvoiceEmail::query()->whereKey($row->id)->update(['attach_work_report' => true]));

        invMailTry($row->id);

        expect(invMailAttachmentNames(invMailSent()[0]))->toBe(['faktura-'.$invoice->number.'.pdf']);
    });
});

describe('the HTML layout (Phase 13)', function (): void {
    it('sends a card-enabled document as an HTML e-mail with the supplier header, the typed text and a pay button', function (): void {
        CardPaymentSetup::enable();

        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']));
        $url = PayUrl::forInvoice($invoice);
        $data = invMailData($invoice);

        Mail::getSymfonyTransport()->flush();
        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, $data);

        $messages = invMailSent();

        expect($messages)->toHaveCount(1);

        $email = $messages[0];
        $html = HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);
        $buttons = $html->querySelectorAll('a.button');

        expect($url)->not->toBeNull()
            ->and($buttons)->toHaveCount(1)
            ->and($buttons[0]?->getAttribute('href'))->toBe($url)
            ->and(trim((string) $buttons[0]?->textContent))->toBe('Zaplatit kartou')
            ->and((string) $html->body?->textContent)->toContain('Example Supplier s.r.o.')
            ->and((string) $html->body?->textContent)->toContain('Doklad můžete zaplatit online kartou:')
            ->and((string) $html->body?->textContent)->toContain('Pokud tlačítko nefunguje')
            ->and((string) $html->body?->textContent)->toContain($url)
            ->and(rtrim((string) $email->getTextBody()))->toBe(rtrim($data['body'])."\n\nDoklad můžete zaplatit online kartou:\n".$url)
            ->and($email->getSubject())->toBe('Faktura '.$invoice->number)
            ->and(invMailAttachmentNames($email))->toBe(['faktura-'.$invoice->number.'.pdf']);
    });

    it('sends a document without card payment with no button', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'invoice@example.com']));

        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, invMailData($invoice));

        $html = HTMLDocument::createFromString((string) invMailSent()[0]->getHtmlBody(), LIBXML_NOERROR);

        expect($html->querySelectorAll('a.button'))->toHaveCount(0);
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
