<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
