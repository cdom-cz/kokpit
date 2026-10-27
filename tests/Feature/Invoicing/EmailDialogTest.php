<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEmailsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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
                'Přílohy: '.$invoice->number.'.pdf',
                'Odeslat',
                'Neodesílat teď',
            ])
            ->assertMountedActionModalDontSee('Klient nemá vyplněný fakturační e-mail.');

        expect($page->get('mountedActions.0.data.recipient'))->toBe($defaults['recipient'])
            ->and($page->get('mountedActions.0.data.subject'))->toBe($defaults['subject'])
            ->and($page->get('mountedActions.0.data.body'))->toBe($defaults['body'])
            ->and($page->get('mountedActions.0.data.attach_work_report'))->toBeFalse();
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
            ->and($row->attach_work_report)->toBeFalse()
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

/**
 * An issued, still open document switched to cash payment the way the database allows it (Phase 15, plan 15-01): the
 * method is cash, the bank snapshot is gone and the card switch stays off with no pay token.
 */
function emailCashInvoice(Invoice $issued): Invoice
{
    app(PartnerContext::class)->runAsSystem(static function () use ($issued): void {
        DB::update("update invoices set payment_method = 'cash', bank_snapshot = null where id = ?", [$issued->id]);
        DB::update('update invoice_admin set card_payment_enabled = false, pay_token = null where invoice_id = ?', [$issued->id]);
    });

    return Invoice::query()->whereKey($issued->id)->firstOrFail();
}

describe('the default text of a cash document (Phase 15, D-02)', function (): void {
    it('asks for payment in cash in Czech and points at no payment details', function (): void {
        $cash = emailCashInvoice(InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '1000.00')]));

        $body = app(QueueInvoiceEmail::class)->defaults($cash)['body'];

        expect($body)->toContain('Doklad je určen k úhradě v hotovosti.')
            ->and($body)->toContain((string) $cash->number)
            ->and($body)->toContain(Money::ofMinor(100000, 'CZK')->format('cs'))
            ->and($body)->toContain($cash->due_date?->format('j. n. Y') ?? 'missing')
            ->and($body)->not->toContain('Platební údaje')
            ->and($body)->not->toContain('kartou');
    });

    it('asks for payment in cash in English for an English document', function (): void {
        $cash = emailCashInvoice(InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_language' => 'en']), [InvoiceFixtures::item('Consulting', '1', '1000.00')]));

        $body = app(QueueInvoiceEmail::class)->defaults($cash)['body'];

        expect($body)->toContain('This document is to be paid in cash.')
            ->and($body)->toContain((string) $cash->number)
            ->and($body)->toContain($cash->due_date?->format('Y-m-d') ?? 'missing')
            ->and($body)->not->toContain('Payment details')
            ->and($body)->not->toContain('by card');
    });

    it('keeps the transfer text for a transfer document', function (): void {
        $transfer = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '1000.00')]);

        $body = app(QueueInvoiceEmail::class)->defaults($transfer)['body'];

        expect($body)->toContain('Platební údaje najdete v přiloženém dokumentu.')
            ->and($body)->not->toContain('v hotovosti');
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

/**
 * A row of the send log of the document, written the way the job leaves it.
 *
 * @param  array<string, mixed>  $overrides
 */
function emailLogRow(Invoice $invoice, InvoiceEmailState $state, string $created, array $overrides = []): InvoiceEmail
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($invoice, $state, $created, $overrides): InvoiceEmail {
        $row = (new InvoiceEmail)->forceFill([
            'invoice_id' => $invoice->id,
            'recipient' => 'jane@'.implode('.', ['example', 'com']),
            'subject' => 'Logged subject '.$state->value,
            'body' => 'Logged body '.$state->value,
            'attach_work_report' => false,
            'state' => $state,
            'sent_at' => $state === InvoiceEmailState::Sent ? CarbonImmutable::parse($created, 'UTC') : null,
            'failure_code' => $state === InvoiceEmailState::Failed ? 'transport' : null,
            'created_by' => Canary::admin()->getKey(),
            'created_at' => $created,
            'updated_at' => $created,
            ...$overrides,
        ]);
        $row->save();

        return $row;
    });
}

function emailTab(Invoice $invoice): Testable
{
    return Livewire::test(InvoiceEmailsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class]);
}

describe('the Odeslání tab', function (): void {
    it('lists a sent and a failed attempt with their states, attachments and the fixed failure note', function (): void {
        $invoice = emailInvoiceWithTime();
        $failed = emailLogRow($invoice, InvoiceEmailState::Failed, '2026-10-12 08:00:00+00', ['attach_work_report' => true]);
        $sent = emailLogRow($invoice, InvoiceEmailState::Sent, '2026-10-13 08:00:00+00');

        emailTab($invoice)
            ->assertCanSeeTableRecords([$sent, $failed], inOrder: true)
            ->assertTableColumnStateSet('attachments', 'PDF', $sent)
            ->assertTableColumnStateSet('attachments', 'PDF a výkaz', $failed)
            ->assertTableColumnStateSet('note', 'Odeslání se nepodařilo. Zkuste ho zopakovat.', $failed)
            ->assertTableColumnStateSet('note', '—', $sent)
            ->assertSee('Vytvořeno')
            ->assertSee('Příjemce')
            ->assertSee('Odesláno')
            ->assertSee('Odeslání selhalo')
            ->assertSee('Odeslání se nepodařilo. Zkuste ho zopakovat.')
            ->assertDontSee('transport')
            ->assertDontSee('Logged body');
    });

    it('reads a queued and a sending row past the stale threshold as failed and offers the resend on both', function (): void {
        $invoice = emailInvoiceWithTime();
        $queued = emailLogRow($invoice, InvoiceEmailState::Queued, '2026-10-14 09:45:00+00');
        $staleQueued = emailLogRow($invoice, InvoiceEmailState::Queued, '2026-10-14 07:00:00+00', ['updated_at' => '2026-10-14 07:00:00+00']);
        $fresh = emailLogRow($invoice, InvoiceEmailState::Sending, '2026-10-14 09:30:00+00', ['updated_at' => '2026-10-14 09:59:00+00']);
        $stale = emailLogRow($invoice, InvoiceEmailState::Sending, '2026-10-14 07:00:00+00', ['updated_at' => '2026-10-14 07:00:00+00']);

        emailTab($invoice)
            ->assertTableColumnStateSet('state', InvoiceEmailState::Queued, $queued)
            ->assertTableColumnStateSet('state', InvoiceEmailState::Failed, $staleQueued)
            ->assertTableColumnStateSet('state', InvoiceEmailState::Sending, $fresh)
            ->assertTableColumnStateSet('state', InvoiceEmailState::Failed, $stale)
            ->assertTableActionHidden('resendEmail', $queued)
            ->assertTableActionVisible('resendEmail', $staleQueued)
            ->assertTableActionHidden('resendEmail', $fresh)
            ->assertTableActionVisible('resendEmail', $stale);
    });

    it('offers Odeslat znovu on a failed row only and opens the dialog with the values of that row', function (): void {
        Queue::fake();
        $invoice = emailInvoiceWithTime();
        $failed = emailLogRow($invoice, InvoiceEmailState::Failed, '2026-10-12 08:00:00+00', ['attach_work_report' => false]);
        $sent = emailLogRow($invoice, InvoiceEmailState::Sent, '2026-10-13 08:00:00+00');

        $tab = emailTab($invoice)
            ->assertTableActionVisible('resendEmail', $failed)
            ->assertTableActionHidden('resendEmail', $sent)
            ->mountTableAction('resendEmail', $failed)
            ->assertMountedActionModalSee(['Odeslat fakturu e-mailem', 'Přílohy: '.$invoice->number.'.pdf']);

        expect($tab->get('mountedActions.0.data.subject'))->toBe('Logged subject failed')
            ->and($tab->get('mountedActions.0.data.body'))->toBe('Logged body failed')
            ->and($tab->get('mountedActions.0.data.recipient'))->toBe($failed->recipient)
            ->and($tab->get('mountedActions.0.data.attach_work_report'))->toBeFalse();

        $tab->callMountedTableAction()
            ->assertNotified('E-mail je ve frontě');

        $rows = InvoiceEmail::query()->where('invoice_id', $invoice->id)->get();
        $new = $rows->whereNotIn('id', [$failed->id, $sent->id])->sole();

        expect($rows)->toHaveCount(3)
            ->and($new->state)->toBe(InvoiceEmailState::Queued)
            ->and($new->subject)->toBe('Logged subject failed')
            ->and($new->attach_work_report)->toBeFalse()
            ->and($failed->fresh()?->state)->toBe(InvoiceEmailState::Failed);

        Queue::assertPushed(SendInvoiceEmail::class, static fn (SendInvoiceEmail $job): bool => $job->emailId === $new->id);
    });

    it('does not borrow the values of a row of another document', function (): void {
        Queue::fake();
        $invoice = emailInvoiceWithTime();
        $other = InvoiceFixtures::issued(InvoiceFixtures::client());
        $foreign = emailLogRow($other, InvoiceEmailState::Failed, '2026-10-12 08:00:00+00', ['subject' => 'Foreign subject']);
        emailLogRow($invoice, InvoiceEmailState::Failed, '2026-10-12 08:00:00+00');

        // The tab resolves its rows through the document: a forged record id of another document's row mounts nothing.
        $tab = emailTab($invoice)->mountAction(TestAction::make('resendEmail')->table($foreign));

        expect($tab->get('mountedActions'))->toBe([]);

        Queue::assertNothingPushed();
    });

    it('is not offered on a draft and answers a Partner with 403', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

        expect(InvoiceEmailsRelationManager::canViewForRecord($invoice, ViewInvoice::class))->toBeTrue()
            ->and(InvoiceEmailsRelationManager::canViewForRecord($draft, ViewInvoice::class))->toBeFalse();

        $this->actingAs(Canary::partnerFor($client->id));

        expect(InvoiceEmailsRelationManager::canViewForRecord($invoice, ViewInvoice::class))->toBeFalse();

        emailTab($invoice)->assertForbidden();
    });
});

describe('the E-mail column and Odeslat znovu in the list', function (): void {
    it('shows the sent date, the wait, the failure and nothing for a draft, and the filter Selhalo finds the failed ones', function (): void {
        $sent = InvoiceFixtures::issued(InvoiceFixtures::client());
        $queued = InvoiceFixtures::issued(InvoiceFixtures::client());
        $sending = InvoiceFixtures::issued(InvoiceFixtures::client());
        $failed = InvoiceFixtures::issued(InvoiceFixtures::client());
        $never = InvoiceFixtures::issued(InvoiceFixtures::client());
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

        emailLogRow($sent, InvoiceEmailState::Sent, '2026-10-02 08:00:00+00');
        emailLogRow($queued, InvoiceEmailState::Queued, '2026-10-02 08:00:00+00');
        emailLogRow($sending, InvoiceEmailState::Sending, '2026-10-02 08:00:00+00');
        emailLogRow($failed, InvoiceEmailState::Failed, '2026-10-02 08:00:00+00');

        Livewire::test(ListInvoices::class)
            ->assertTableColumnStateSet('email', 'sent', $sent)
            ->assertTableColumnStateSet('email', 'queued', $queued)
            ->assertTableColumnStateSet('email', 'queued', $sending)
            ->assertTableColumnStateSet('email', 'failed', $failed)
            ->assertTableColumnStateSet('email', 'none', $never)
            ->assertTableColumnStateSet('email', null, $draft)
            ->assertSee(['Odesláno 2. 10. 2026', 'Ve frontě', 'Odeslání selhalo'])
            ->filterTable('email', 'failed')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$sent, $queued, $sending, $never]);
    });

    it('offers Odeslat znovu for failed and unsent issued documents only', function (): void {
        $sent = InvoiceFixtures::issued(InvoiceFixtures::client());
        $queued = InvoiceFixtures::issued(InvoiceFixtures::client());
        $failed = InvoiceFixtures::issued(InvoiceFixtures::client());
        $never = InvoiceFixtures::issued(InvoiceFixtures::client());
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

        emailLogRow($sent, InvoiceEmailState::Sent, '2026-10-02 08:00:00+00');
        emailLogRow($queued, InvoiceEmailState::Queued, '2026-10-02 08:00:00+00');
        emailLogRow($failed, InvoiceEmailState::Sent, '2026-10-01 08:00:00+00');
        emailLogRow($failed, InvoiceEmailState::Failed, '2026-10-02 08:00:00+00');

        Livewire::test(ListInvoices::class)
            ->assertTableActionVisible('resendEmail', $failed)
            ->assertTableActionVisible('resendEmail', $never)
            ->assertTableActionHidden('resendEmail', $sent)
            ->assertTableActionHidden('resendEmail', $queued)
            ->assertTableActionHidden('resendEmail', $draft);
    });

    it('queues a new e-mail from the list row after the confirmation', function (): void {
        Queue::fake();
        $never = emailInvoiceWithTime();

        Livewire::test(ListInvoices::class)
            ->mountTableAction('resendEmail', $never)
            ->assertMountedActionModalSee('Odeslat fakturu e-mailem')
            ->callMountedTableAction()
            ->assertNotified('E-mail je ve frontě');

        expect(InvoiceEmail::query()->where('invoice_id', $never->id)->sole()->state)->toBe(InvoiceEmailState::Queued);

        Queue::assertPushed(SendInvoiceEmail::class, 1);
    });
});
