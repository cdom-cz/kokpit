<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The document of a client with the document e-mails switched off (Phase 16; D-02, D-03, D-04, D-06): the detail offers
 * no e-mail, says why, and lets the Admin lock the open document by hand. Every name is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

const HAND_SERVED_NOTE = 'Klient má odesílání dokladů e-mailem vypnuté. Doklad mu předejte jinak a uzamkněte ho ručně.';

/**
 * A client whose document e-mails are switched off.
 */
function handServedDocClient(): Client
{
    return InvoiceFixtures::client(['document_emails_enabled' => false]);
}

/**
 * The detail of a document, opened plainly or the way the issue opens it (`send=1`).
 */
function handServedDocPage(Invoice $invoice, bool $send = false): Testable
{
    return ($send ? Livewire::withQueryParams(['send' => 1]) : Livewire::withQueryParams([]))
        ->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The callout action `lock` of the open banner.
 */
function handServedDocLock(): TestAction
{
    return TestAction::make('lock')->schemaComponent('open_for_edits', schema: 'infolist');
}

/**
 * The callout action `unlock` of the locked banner.
 */
function handServedDocUnlock(): TestAction
{
    return TestAction::make('unlock')->schemaComponent('locked', schema: 'infolist');
}

describe('the detail of a client with the document e-mails off', function (): void {
    it('hides Odeslat e-mailem on an issued invoice and says why', function (): void {
        $invoice = InvoiceFixtures::issued(handServedDocClient());

        handServedDocPage($invoice)
            ->assertActionHidden('sendEmail')
            ->assertSee(HAND_SERVED_NOTE);
    });

    it('does the same on an issued proforma', function (): void {
        $proforma = InvoiceFixtures::issued(handServedDocClient(), [], InvoiceKind::Proforma);

        handServedDocPage($proforma)
            ->assertActionHidden('sendEmail')
            ->assertSee(HAND_SERVED_NOTE);
    });

    it('keeps the action and shows no note for a client with the e-mails on', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        handServedDocPage($invoice)
            ->assertActionVisible('sendEmail')
            ->assertDontSee(HAND_SERVED_NOTE);
    });
});

describe('the lock by hand from the open callout', function (): void {
    it('offers the lock for a client with the e-mails off and for one with them on', function (bool $enabled): void {
        $client = InvoiceFixtures::client(['document_emails_enabled' => $enabled]);
        $invoice = InvoiceFixtures::issued($client);

        handServedDocPage($invoice)
            ->assertActionExists(handServedDocLock())
            ->assertActionVisible(handServedDocLock())
            ->assertActionHasLabel(handServedDocLock(), 'Uzamknout');
    })->with([
        'switch off' => [false],
        'switch on' => [true],
    ]);

    it('locks the invoice, shows the locked callout with the unlock and writes one row naming the Admin', function (): void {
        $invoice = InvoiceFixtures::issued(handServedDocClient());
        $this->actingAs($this->admin);
        $before = (array) DB::table('invoices')->where('id', $invoice->id)->first();

        handServedDocPage($invoice)
            ->callAction(handServedDocLock())
            ->assertActionVisible(handServedDocUnlock())
            ->assertSee('Uzamčeno')
            ->assertDontSee('Uzamknout');

        $after = (array) DB::table('invoices')->where('id', $invoice->id)->first();

        expect($invoice->refresh()->isLocked())->toBeTrue()
            ->and($after['lock_state'])->toBe('locked')
            ->and(InvoiceEmail::query()->where('invoice_id', $invoice->id)->count())->toBe(0);

        foreach (['number', 'kind', 'supplier_snapshot', 'customer_snapshot', 'bank_snapshot', 'total_minor', 'issue_date'] as $column) {
            expect($after[$column])->toBe($before[$column]);
        }

        $rows = Activity::query()
            ->where('subject_type', 'invoice')
            ->where('subject_id', $invoice->id)
            ->where('event', 'invoice_locked_manually')
            ->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->causer_id)->toBe($this->admin->id)
            ->and($rows[0]->attribute_changes?->all())->toBe([
                'attributes' => ['lock_state' => 'locked'],
                'old' => ['lock_state' => 'open'],
            ]);
    });

    it('locks an issued proforma the same way', function (): void {
        $proforma = InvoiceFixtures::issued(handServedDocClient(), [], InvoiceKind::Proforma);

        handServedDocPage($proforma)->callAction(handServedDocLock());

        expect($proforma->refresh()->isLocked())->toBeTrue()
            ->and(InvoiceEmail::query()->where('invoice_id', $proforma->id)->count())->toBe(0);
    });

    it('shows no lock action on a locked invoice', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(handServedDocClient());

        handServedDocPage($invoice)
            ->assertActionVisible(handServedDocUnlock())
            ->assertDontSee('Uzamknout');
    });
});

/**
 * The values of the dialog as an Admin submits them.
 *
 * @return array{recipient: string, subject: string, body: string, attach_work_report: bool}
 */
function handServedDocData(): array
{
    return [
        'recipient' => exampleEmail('invoice'),
        'subject' => 'Example document',
        'body' => 'Example body',
        'attach_work_report' => false,
    ];
}

/**
 * An issued document of the given kind for the client; the credit note is the storno of an issued invoice.
 */
function handServedDocOf(string $kind, Client $client): Invoice
{
    return match ($kind) {
        'proforma' => InvoiceFixtures::issued($client, [], InvoiceKind::Proforma),
        'credit note' => app(IssueCreditNote::class)->storno(test()->admin, InvoiceFixtures::issued($client)),
        default => InvoiceFixtures::issued($client),
    };
}

describe('the queueing of a document e-mail for a client with the e-mails off', function (): void {
    it('refuses every kind under invoice, writes no row and dispatches no job', function (string $kind): void {
        Queue::fake();
        $document = handServedDocOf($kind, handServedDocClient());

        try {
            app(QueueInvoiceEmail::class)->handle($this->admin, $document, handServedDocData());
            test()->fail('The queueing had to be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toBe(['invoice' => [__('invoices.errors.email_client_disabled')]])
                ->and($exception->errors()['invoice'][0])->toBe('Klient má odesílání dokladů e-mailem vypnuté.');
        }

        expect(InvoiceEmail::query()->where('invoice_id', $document->id)->count())->toBe(0)
            ->and(DB::table('invoice_emails')->count())->toBe(0);

        Queue::assertNothingPushed();
    })->with(['invoice', 'proforma', 'credit note']);

    it('queues as before for a client with the e-mails on', function (): void {
        Queue::fake();
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, handServedDocData());

        expect(InvoiceEmail::query()->where('invoice_id', $invoice->id)->count())->toBe(1);

        Queue::assertPushed(SendInvoiceEmail::class, static fn (SendInvoiceEmail $job): bool => $job->emailId === $row->id);
    });

    it('judges the switch as it is now, not as the passed model carries it', function (): void {
        Queue::fake();
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);

        DB::table('clients')->where('id', $client->id)->update(['document_emails_enabled' => false]);

        expect(fn () => app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, handServedDocData()))->toThrow(ValidationException::class);

        Queue::assertNothingPushed();
    });
});

describe('the dialog after the issue', function (): void {
    it('stays closed for a client with the e-mails off', function (): void {
        $invoice = InvoiceFixtures::issued(handServedDocClient());

        handServedDocPage($invoice, send: true)
            ->assertDontSeeHtml('wire:init="openSendDialog"')
            ->call('openSendDialog')
            ->assertActionNotMounted('sendEmail');
    });

    it('still opens for a client with the e-mails on', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        handServedDocPage($invoice, send: true)
            ->assertSeeHtml('wire:init="openSendDialog"')
            ->call('openSendDialog')
            ->assertActionMounted('sendEmail');
    });
});
