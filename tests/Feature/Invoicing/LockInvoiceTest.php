<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\LockInvoice;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The lock of an open issued invoice or proforma by hand (Phase 16; D-02, D-03; owner answers A1, A2): one Action, one
 * audit row of its own event, no e-mail row, and the same unlock afterwards. Every name and reason is fictional.
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
 * The activity rows of one event on a document, oldest first.
 *
 * @return list<Activity>
 */
function lockInvoiceRows(Invoice $invoice, string $event): array
{
    return Activity::query()
        ->where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->get()
        ->all();
}

/**
 * Asserts the lock is refused with the given message under `lock`, and that neither the row nor the history of the
 * document changed.
 */
function lockInvoiceExpectRefused(Invoice $invoice, string $message): void
{
    $before = (array) DB::table('invoices')->where('id', $invoice->id)->first();
    $rowsBefore = Activity::query()->where('subject_type', 'invoice')->where('subject_id', $invoice->id)->count();

    try {
        app(LockInvoice::class)->handle(test()->admin, $invoice);
        test()->fail('The lock should have been refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lock')
            ->and($e->errors()['lock'][0])->toBe($message);
    }

    expect((array) DB::table('invoices')->where('id', $invoice->id)->first())->toBe($before)
        ->and(Activity::query()->where('subject_type', 'invoice')->where('subject_id', $invoice->id)->count())->toBe($rowsBefore)
        ->and(lockInvoiceRows($invoice, 'invoice_locked_manually'))->toBe([]);
}

/**
 * The values of the dialog as an Admin submits them.
 *
 * @return array{recipient: string, subject: string, body: string, attach_work_report: bool}
 */
function lockInvoiceMailData(): array
{
    return [
        'recipient' => exampleEmail('invoice'),
        'subject' => 'Example document',
        'body' => 'Example body',
        'attach_work_report' => false,
    ];
}

/**
 * Runs the job the way one try of a worker does, through its own middleware.
 */
function lockInvoiceRunJob(string $emailId): void
{
    $job = new SendInvoiceEmail($emailId);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (SendInvoiceEmail $job): mixed => app()->call($job->handle(...)));
}

describe('the lock by hand', function (): void {
    it('locks an open issued invoice and writes one row of its own event', function (): void {
        $client = InvoiceFixtures::client(['document_emails_enabled' => false]);
        $invoice = InvoiceFixtures::issued($client);

        $locked = app(LockInvoice::class)->handle($this->admin, $invoice);

        expect($locked->lock_state)->toBe(InvoiceLockState::Locked)
            ->and($locked->isLocked())->toBeTrue()
            ->and(DB::table('invoices')->where('id', $invoice->id)->value('lock_state'))->toBe('locked')
            ->and(lockInvoiceRows($invoice, 'invoice_locked'))->toBe([])
            ->and(InvoiceEmail::query()->where('invoice_id', $invoice->id)->count())->toBe(0);

        $rows = lockInvoiceRows($invoice, 'invoice_locked_manually');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->causer_id)->toBe($this->admin->id)
            ->and($rows[0]->attribute_changes?->all())->toBe([
                'attributes' => ['lock_state' => 'locked'],
                'old' => ['lock_state' => 'open'],
            ]);
    });

    it('locks an open issued proforma', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);

        $locked = app(LockInvoice::class)->handle($this->admin, $proforma);

        expect($locked->isLocked())->toBeTrue()
            ->and(lockInvoiceRows($proforma, 'invoice_locked_manually'))->toHaveCount(1);
    });
});

describe('what the lock refuses', function (): void {
    it('refuses a draft', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

        lockInvoiceExpectRefused($draft, 'Koncept se upravuje v editoru konceptu.');
    });

    it('refuses a credit note, which is locked at its issue', function (): void {
        $credit = InvoiceFixtures::stornoed(InvoiceFixtures::issued(InvoiceFixtures::client()));

        lockInvoiceExpectRefused($credit, 'Dobropis po vystavení upravit ani odemknout nelze.');
    });

    it('refuses a locked invoice', function (): void {
        lockInvoiceExpectRefused(InvoiceFixtures::lockedIssued(InvoiceFixtures::client()), 'Doklad je už uzamčen.');
    });

    it('refuses a cancelled invoice', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        lockInvoiceExpectRefused($invoice, 'Stornovaný doklad upravit nelze.');
    });

    it('refuses a proforma that already has its invoice', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);
        app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '1000.00', 'paid_on' => '2026-10-14']);
        app(ConvertProforma::class)->handle($this->admin, $proforma);

        lockInvoiceExpectRefused($proforma, 'Zálohovou fakturu, ze které už vznikla faktura, upravit nelze.');
    });

    it('refuses a Partner with an AuthorizationException and changes nothing', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $partner = Canary::partnerFor($client->id);

        expect(fn () => app(LockInvoice::class)->handle($partner, $invoice))->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);

        expect($invoice->refresh()->isLocked())->toBeFalse()
            ->and(lockInvoiceRows($invoice, 'invoice_locked_manually'))->toBe([]);
    });
});

describe('the lock against the send', function (): void {
    it('locks a document with a queued e-mail, and the send afterwards marks it sent without a second lock row', function (): void {
        Queue::fake();
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $queued = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, lockInvoiceMailData());

        app(LockInvoice::class)->handle($this->admin, $invoice);

        lockInvoiceRunJob($queued->id);

        expect(InvoiceEmail::query()->whereKey($queued->id)->sole()->state)->toBe(InvoiceEmailState::Sent)
            ->and(lockInvoiceRows($invoice, 'invoice_locked'))->toBe([])
            ->and(lockInvoiceRows($invoice, 'invoice_locked_manually'))->toHaveCount(1)
            ->and($invoice->refresh()->isLocked())->toBeTrue();
    });

    it('refuses the lock of a document the send has locked first, with exactly one lock row', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $queued = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, lockInvoiceMailData());

        // The sync queue of the tests runs the job after the commit; a second run of a sent row does nothing.
        lockInvoiceRunJob($queued->id);

        expect($invoice->refresh()->isLocked())->toBeTrue();

        lockInvoiceExpectRefused($invoice, 'Doklad je už uzamčen.');

        expect(lockInvoiceRows($invoice, 'invoice_locked'))->toHaveCount(1)
            ->and(lockInvoiceRows($invoice, 'invoice_locked_manually'))->toBe([]);
    });
});

describe('the document after the lock by hand', function (): void {
    it('refuses a correction until it is unlocked, and unlocks and locks again like a sent one', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        app(LockInvoice::class)->handle($this->admin, $invoice);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['note' => 'Changed.']);
            test()->fail('The edit had to be refused.');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['document' => [__('invoices.errors.document_locked')]]);
        }

        try {
            app(UnlockInvoice::class)->handle($this->admin, $invoice, '123456789');
            test()->fail('The unlock had to be refused.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('reason');
        }

        expect($invoice->refresh()->isLocked())->toBeTrue();

        $opened = app(UnlockInvoice::class)->handle($this->admin, $invoice, 'Wrong example note on the document');

        expect($opened->isLocked())->toBeFalse()
            ->and(lockInvoiceRows($invoice, 'invoice_unlocked'))->toHaveCount(1);

        app(LockInvoice::class)->handle($this->admin, $invoice);

        expect($invoice->refresh()->isLocked())->toBeTrue()
            ->and(lockInvoiceRows($invoice, 'invoice_locked_manually'))->toHaveCount(2);
    });
});
