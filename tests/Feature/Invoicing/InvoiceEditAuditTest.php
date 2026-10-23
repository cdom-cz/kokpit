<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\InvoiceAudit;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The post-send audit of an issued document (IN-15; D-13, assumption A12): after the first successful send every edit
 * leaves one Admin-only row with the actor and the values before and after, a never-sent document is corrected like a
 * draft. Every name, number and amount is fictional.
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
 * An issued invoice of two lines that was sent once (so it is locked) and unlocked again with a reason.
 */
function editAuditSentAndUnlocked(): Invoice
{
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [
        InvoiceFixtures::item('Design', '2', '500.00'),
        InvoiceFixtures::item('Review', '1', '250.00'),
    ]);

    $row = app(QueueInvoiceEmail::class)->handle(test()->admin, $invoice, [
        'recipient' => 'jane@example.com',
        'subject' => 'Example invoice',
        'body' => 'Example body',
        'attach_work_report' => false,
    ]);

    // The sync queue of the tests runs the job after the commit; a second run of a sent row does nothing.
    SendInvoiceEmail::dispatchSync($row->id);

    expect(editAuditRow($invoice)->lock_state)->toBe(InvoiceLockState::Locked);

    return app(UnlockInvoice::class)->handle(test()->admin, $invoice, 'Wrong example quantity on line one');
}

/**
 * The document row, read fresh.
 */
function editAuditRow(Invoice $invoice): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

/**
 * The lines of the document in order, read fresh.
 *
 * @return list<InvoiceItem>
 */
function editAuditLines(Invoice $invoice): array
{
    return array_values(app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get()->all()));
}

/**
 * The editor payload of a stored line with the given overrides.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function editAuditPayload(InvoiceItem $line, array $overrides = []): array
{
    return [
        'id' => $line->id,
        'description' => $line->description,
        'quantity' => $line->quantity,
        'unit' => $line->unit,
        'unit_price' => $line->unitPrice()->toMajor(),
        ...$overrides,
    ];
}

/**
 * The activity rows of a document, oldest first, optionally of one event.
 *
 * @return list<Activity>
 */
function editAuditRows(Invoice $invoice, ?string $event = null): array
{
    return Activity::query()
        ->where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->when($event !== null, static fn ($query) => $query->where('event', $event))
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all();
}

describe('the audit of an edit after the first send', function (): void {
    it('writes one invoice_edited row with the actor and only the changed values', function (): void {
        $invoice = editAuditSentAndUnlocked();
        [$first, $second] = editAuditLines($invoice);
        $rowsBefore = count(editAuditRows($invoice));
        $updatedBefore = count(editAuditRows($invoice, 'updated'));
        Queue::fake();

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'items' => [editAuditPayload($first, ['quantity' => '3']), editAuditPayload($second)],
            'note' => 'Corrected quantity of the design work.',
        ]);

        $edited = editAuditRows($invoice, 'invoice_edited');

        expect($edited)->toHaveCount(1)
            ->and($edited[0]->causer_id)->toBe($this->admin->id)
            ->and($edited[0]->attribute_changes?->all())->toBe([
                'attributes' => [
                    'note' => 'Corrected quantity of the design work.',
                    'total_minor' => 175000,
                    'items.1.quantity' => '3.0000',
                    'items.1.line_total_minor' => 150000,
                ],
                'old' => [
                    'note' => null,
                    'total_minor' => 125000,
                    'items.1.quantity' => '2.0000',
                    'items.1.line_total_minor' => 100000,
                ],
            ])
            // The edit itself is the only new row: the save of the model is silenced, no `updated` row on top.
            ->and(count(editAuditRows($invoice)))->toBe($rowsBefore + 1)
            ->and(count(editAuditRows($invoice, 'updated')))->toBe($updatedBefore);
    });

    it('keeps the ordinary model history of a document that was never sent, like a draft', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        [$line] = editAuditLines($invoice);
        $updatedBefore = count(editAuditRows($invoice, 'updated'));

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'items' => [editAuditPayload($line, ['quantity' => '3'])],
        ]);

        $updated = editAuditRows($invoice, 'updated');

        expect(editAuditRows($invoice, 'invoice_edited'))->toBe([])
            ->and($updated)->toHaveCount($updatedBefore + 1)
            ->and($updated[$updatedBefore]->attribute_changes?->all())->toBe([
                'attributes' => ['subtotal_minor' => 150000, 'total_minor' => 150000],
                'old' => ['subtotal_minor' => 100000, 'total_minor' => 100000],
            ]);
    });

    it('knows a sent document from one that was only queued', function (): void {
        $sent = editAuditSentAndUnlocked();

        Queue::fake();
        $queued = InvoiceFixtures::issued(InvoiceFixtures::client());
        app(QueueInvoiceEmail::class)->handle($this->admin, $queued, [
            'recipient' => 'jane@example.com',
            'subject' => 'Example invoice',
            'body' => 'Example body',
            'attach_work_report' => false,
        ]);

        expect(InvoiceAudit::everSent($queued))->toBeFalse()
            ->and(InvoiceAudit::everSent($sent))->toBeTrue();
    });
});
