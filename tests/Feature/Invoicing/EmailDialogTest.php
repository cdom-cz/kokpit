<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The e-mail dialog, the Odeslání tab, the E-mail column of the list and the resend (IN-04; D-22, ruling R-24-1;
 * UI-SPEC Surface G, N9). Every address, name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * The detail of a document, opened the way the issue opens it (`send=1`) or plainly.
 */
function emailPage(Invoice $invoice, bool $send = false): Testable
{
    return ($send ? Livewire::withQueryParams(['send' => 1]) : Livewire::withQueryParams([]))
        ->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The detail as the browser gets it after the issue: the page asks for the dialog as soon as it is loaded.
 */
function emailPageOpened(Invoice $invoice): Testable
{
    $page = emailPage($invoice, send: true)->assertSeeHtml('wire:init="openSendDialog"');

    return $page->call('openSendDialog');
}

/**
 * An issued invoice of two entries of September time (3,5 h at 1 200).
 */
function emailInvoiceWithTime(array $clientOverrides = []): Invoice
{
    $client = InvoiceFixtures::client($clientOverrides);
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Dialog project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1200',
    ]);

    foreach ([['2026-09-10 08:00:00', '2026-09-10 09:00:00'], ['2026-09-11 08:00:00', '2026-09-11 10:30:00']] as [$start, $end]) {
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
        'currency' => 'CZK',
    ]);

    return app(IssueDocument::class)->handle(test()->admin, $draft);
}

describe('the dialog after the issue', function (): void {
    it('opens by itself with send=1, pre-filled in the language of the document, and names the attachments', function (): void {
        $invoice = emailInvoiceWithTime();
        $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);

        $page = emailPageOpened($invoice)
            ->assertActionMounted('sendEmail')
            ->assertMountedActionModalSee([
                'Odeslat fakturu e-mailem',
                'Příjemce',
                'Předmět',
                'Text',
                'Přiložit pracovní výkaz',
                'Výkaz obsahuje odpracované hodiny bez cen.',
                'Přílohy: '.$invoice->number.'.pdf, pracovní výkaz.pdf',
                'Odeslat',
                'Neodesílat teď',
            ])
            ->assertMountedActionModalDontSee('Klient nemá vyplněný fakturační e-mail.');

        expect($page->get('mountedActions.0.data.recipient'))->toBe($defaults['recipient'])
            ->and($page->get('mountedActions.0.data.subject'))->toBe($defaults['subject'])
            ->and($page->get('mountedActions.0.data.body'))->toBe($defaults['body'])
            ->and($page->get('mountedActions.0.data.attach_work_report'))->toBeTrue();
    });

    it('stays closed without the flag and opens on demand from the header', function (): void {
        $invoice = emailInvoiceWithTime();

        emailPage($invoice)
            ->assertDontSeeHtml('wire:init')
            ->assertActionNotMounted('sendEmail')
            ->assertActionVisible('sendEmail')
            ->assertActionHasLabel('sendEmail', 'Odeslat e-mailem')
            ->assertActionHasColor('sendEmail', 'gray')
            ->mountAction('sendEmail')
            ->assertMountedActionModalSee('Odeslat fakturu e-mailem');
    });

    it('queues the e-mail after the confirmation with the info toast and leaves nothing sent', function (): void {
        Queue::fake();
        $invoice = emailInvoiceWithTime();

        emailPageOpened($invoice)
            ->fillForm(['subject' => 'Faktura k úhradě', 'body' => 'Dobrý den, posíláme fakturu.'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertNotified('E-mail je ve frontě')
            ->assertDispatched('invoice-email-state-changed')
            ->assertActionNotMounted('sendEmail');

        $row = InvoiceEmail::query()->where('invoice_id', $invoice->id)->sole();

        expect($row->state)->toBe(InvoiceEmailState::Queued)
            ->and($row->recipient)->toBe($invoice->client->invoice_email)
            ->and($row->subject)->toBe('Faktura k úhradě')
            ->and($row->body)->toBe('Dobrý den, posíláme fakturu.')
            ->and($row->attach_work_report)->toBeTrue()
            ->and($row->created_by)->toBe($this->admin->id);

        Queue::assertPushed(SendInvoiceEmail::class, static fn (SendInvoiceEmail $job): bool => $job->emailId === $row->id);
    });

    it('drops the work report from the attachments line and from the e-mail when it is unticked', function (): void {
        Queue::fake();
        $invoice = emailInvoiceWithTime();

        emailPageOpened($invoice)
            ->fillForm(['attach_work_report' => false], 'mountedActionSchema0')
            ->assertMountedActionModalSee('Přílohy: '.$invoice->number.'.pdf')
            ->assertMountedActionModalDontSee('pracovní výkaz.pdf')
            ->callMountedAction();

        expect(InvoiceEmail::query()->where('invoice_id', $invoice->id)->sole()->attach_work_report)->toBeFalse();
    });

    it('leaves the document unsent when the dialog is cancelled and keeps Odeslat e-mailem available', function (): void {
        Queue::fake();
        $invoice = emailInvoiceWithTime();

        emailPageOpened($invoice)
            ->unmountAction()
            ->assertActionNotMounted('sendEmail')
            ->assertActionVisible('sendEmail');

        expect(InvoiceEmail::query()->count())->toBe(0);

        Queue::assertNothingPushed();
    });
});

describe('what the dialog asks for', function (): void {
    it('shows an empty recipient with the helper for a client without an invoice e-mail and refuses to send', function (): void {
        Queue::fake();
        $invoice = emailInvoiceWithTime(['invoice_email' => null]);

        $page = emailPageOpened($invoice)
            ->assertMountedActionModalSee('Klient nemá vyplněný fakturační e-mail.');

        expect($page->get('mountedActions.0.data.recipient'))->toBeNull();

        $page->callMountedAction()
            ->assertHasActionErrors(['recipient'])
            ->assertActionMounted('sendEmail');

        expect(InvoiceEmail::query()->count())->toBe(0);

        Queue::assertNothingPushed();
    });

    it('shows the field error of a recipient that is no address', function (): void {
        Queue::fake();
        $invoice = emailInvoiceWithTime();

        emailPageOpened($invoice)
            ->fillForm(['recipient' => 'not an address'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasActionErrors(['recipient']);

        expect(InvoiceEmail::query()->count())->toBe(0);
    });

    it('offers no work report for a document without linked time', function (): void {
        Queue::fake();
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        $page = emailPageOpened($invoice)
            ->assertMountedActionModalDontSee('Přiložit pracovní výkaz')
            ->assertMountedActionModalSee('Přílohy: '.$invoice->number.'.pdf')
            ->assertMountedActionModalDontSee('pracovní výkaz.pdf');

        $page->callMountedAction();

        expect(InvoiceEmail::query()->where('invoice_id', $invoice->id)->sole()->attach_work_report)->toBeFalse();
    });

    it('names the kind in the accusative in the heading', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $proforma = InvoiceFixtures::issued($client, [], InvoiceKind::Proforma);
        $credit = app(IssueCreditNote::class)->storno($this->admin, $invoice);

        emailPageOpened($credit)->assertMountedActionModalSee('Odeslat dobropis e-mailem');
        emailPageOpened($proforma)->assertMountedActionModalSee('Odeslat zálohovou fakturu e-mailem');
    });

    it('keeps a long edited text submittable and refuses one above the limit under the text', function (): void {
        Queue::fake();
        $invoice = emailInvoiceWithTime();

        emailPageOpened($invoice)
            ->fillForm(['body' => str_repeat('Lorem ipsum. ', 380)], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $second = emailPageOpened($invoice)
            ->fillForm(['body' => str_repeat('x', 5001)], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasActionErrors(['body']);

        expect(InvoiceEmail::query()->where('invoice_id', $invoice->id)->count())->toBe(1);

        $second->assertActionMounted('sendEmail');
    });
});

describe('who may open it', function (): void {
    it('is offered to the Admin on an issued document only and a Partner gets 403', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);

        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);
        emailPage($draft, send: true)->assertRedirect();

        $this->actingAs(Canary::partnerFor($client->id));

        emailPage($invoice, send: true)->assertForbidden();
    });
});
