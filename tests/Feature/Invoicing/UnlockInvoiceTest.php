<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateInternalNote;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The sanctioned unlock of a sent invoice or proforma (IN-12, IN-15; D-03, D-13; assumptions A1, A3, A12): one Action,
 * one transaction-local setting, one audit row with the reason. Every name and reason is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * The activity rows of one event on a document, oldest first.
 *
 * @return list<Activity>
 */
function unlockRows(Invoice $invoice, string $event): array
{
    return Activity::query()
        ->where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->get()
        ->all();
}

describe('the unlock of a sent document', function (): void {
    it('opens a locked invoice, clears the setting and writes one audit row with the reason', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());

        $opened = app(UnlockInvoice::class)->handle($this->admin, $invoice, '  Wrong example quantity on line one  ');

        expect($opened->lock_state)->toBe(InvoiceLockState::Open)
            ->and($opened->isLocked())->toBeFalse()
            ->and(DB::table('invoices')->where('id', $invoice->id)->value('lock_state'))->toBe('open')
            ->and(DB::scalar("select current_setting('kokpit.invoice_unlock', true)"))->toBeIn([null, '']);

        $rows = unlockRows($invoice, 'invoice_unlocked');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->causer_id)->toBe($this->admin->id)
            ->and($rows[0]->attribute_changes?->all())->toBe([
                'attributes' => ['lock_state' => 'open', 'unlock_reason' => 'Wrong example quantity on line one'],
                'old' => ['lock_state' => 'locked'],
            ]);
    });

    it('opens a locked proforma the same way', function (): void {
        $proforma = InvoiceFixtures::lockedIssued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);

        $opened = app(UnlockInvoice::class)->handle($this->admin, $proforma, 'Advance amount was mistyped');

        expect($opened->lock_state)->toBe(InvoiceLockState::Open)
            ->and(unlockRows($proforma, 'invoice_unlocked'))->toHaveCount(1);
    });
});

/**
 * Asserts the unlock is refused with the given message under the given key, and that neither the row nor the history
 * of the document changed.
 */
function unlockExpectRefused(Invoice $invoice, string $key, string $message, string $reason = 'Valid example reason'): void
{
    $before = (array) DB::table('invoices')->where('id', $invoice->id)->first();
    $rowsBefore = Activity::query()->where('subject_type', 'invoice')->where('subject_id', $invoice->id)->count();

    try {
        app(UnlockInvoice::class)->handle(test()->admin, $invoice, $reason);
        test()->fail('The unlock should have been refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key)
            ->and($e->errors()[$key][0])->toBe($message);
    }

    expect((array) DB::table('invoices')->where('id', $invoice->id)->first())->toBe($before)
        ->and(Activity::query()->where('subject_type', 'invoice')->where('subject_id', $invoice->id)->count())->toBe($rowsBefore)
        ->and(unlockRows($invoice, 'invoice_unlocked'))->toBe([]);
}

describe('what the unlock refuses', function (): void {
    it('refuses a reason outside 10 to 500 characters, blank included', function (string $reason): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());

        unlockExpectRefused($invoice, 'reason', 'Důvod musí mít 10 až 500 znaků.', $reason);
        expect($invoice->refresh()->isLocked())->toBeTrue();
    })->with([
        'empty' => [''],
        'blank' => ['            '],
        'nine characters' => ['123456789'],
        'nine after trimming' => ['  123456789  '],
        'over 500' => [str_repeat('a', 501)],
    ]);

    it('accepts the limits of 10 and 500 characters', function (int $length): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());

        expect(app(UnlockInvoice::class)->handle($this->admin, $invoice, str_repeat('a', $length))->isLocked())->toBeFalse();
    })->with([10, 500]);

    it('refuses an invoice that is open', function (): void {
        unlockExpectRefused(InvoiceFixtures::issued(InvoiceFixtures::client()), 'unlock', 'Doklad není uzamčen.');
    });

    it('refuses a draft', function (): void {
        unlockExpectRefused(InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]), 'unlock', 'Doklad není uzamčen.');
    });

    it('refuses a credit note, which is locked at issue and never opened', function (): void {
        $credit = InvoiceFixtures::stornoed(InvoiceFixtures::lockedIssued(InvoiceFixtures::client()));

        expect($credit->isLocked())->toBeTrue();

        unlockExpectRefused($credit, 'unlock', 'Dobropis po vystavení upravit ani odemknout nelze.');
    });

    it('refuses a cancelled invoice', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        unlockExpectRefused($invoice, 'unlock', 'Stornovaný doklad upravit nelze.');
    });

    it('refuses a proforma that already has its invoice', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);
        app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '1000.00', 'paid_on' => '2026-10-14']);
        app(ConvertProforma::class)->handle($this->admin, $proforma);
        InvoiceFixtures::lock($proforma);

        unlockExpectRefused($proforma, 'unlock', 'Zálohovou fakturu, ze které už vznikla faktura, upravit nelze.');
    });

    it('refuses a Partner with an AuthorizationException and leaves the document locked', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::lockedIssued($client);
        $partner = Canary::partnerFor($client->id);

        expect(fn () => app(UnlockInvoice::class)->handle($partner, $invoice, 'Valid example reason'))->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);

        expect($invoice->refresh()->isLocked())->toBeTrue()
            ->and(unlockRows($invoice, 'invoice_unlocked'))->toBe([]);
    });
});

describe('an unlocked document', function (): void {
    it('stays open through reads, a payment and an internal note until the next send', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        app(UnlockInvoice::class)->handle($this->admin, $invoice, 'Wrong example quantity on line one');

        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '100.00', 'paid_on' => '2026-10-14']);
        app(UpdateInternalNote::class)->handle($this->admin, $invoice, 'Example follow-up');
        $invoice->refresh();
        Invoice::query()->whereKey($invoice->id)->get();

        expect($invoice->refresh()->isLocked())->toBeFalse()
            ->and(unlockRows($invoice, 'invoice_unlocked'))->toHaveCount(1);
    });
});
