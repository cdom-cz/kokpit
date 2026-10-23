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
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Support\ActivityPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FictionalIban;
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
function editAuditSentAndUnlocked(?Invoice $issued = null): Invoice
{
    $invoice = $issued ?? InvoiceFixtures::issued(InvoiceFixtures::client(), [
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

/**
 * Replaces the accounts of the settings by one CZK account with the fragment, as system.
 */
function editAuditCzkAccount(string $fragment): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($fragment): void {
        $banks = app(BankAccountSettings::class);
        $banks->accounts = [new BankAccount(BankAccountFormat::Europe2, 'Replacement CZK account', 'CZK', iban: FictionalIban::czech($fragment))];
        $banks->save();
    });
    app()->forgetScopedInstances();
}

describe('the audit of an edit after the first send', function (): void {
    it('audits a bank-only refill after the first send with the bank fingerprint and no bank data', function (): void {
        $invoice = editAuditSentAndUnlocked();
        $rowsBefore = count(editAuditRows($invoice));
        $updatedBefore = count(editAuditRows($invoice, 'updated'));
        $fingerprintBefore = InvoiceAudit::snapshot(editAuditRow($invoice))['bank_fingerprint'] ?? null;

        editAuditCzkAccount('56');
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['reload_bank' => true]);

        $fingerprintAfter = InvoiceAudit::snapshot(editAuditRow($invoice))['bank_fingerprint'] ?? null;
        $edited = editAuditRows($invoice, 'invoice_edited');

        expect($edited)->toHaveCount(1);

        $changes = $edited[0]->attribute_changes?->all() ?? [];
        $payload = (string) json_encode($changes);

        expect($edited[0]->causer_id)->toBe($this->admin->id)
            ->and($edited[0]->created_at?->equalTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague')))->toBeTrue()
            ->and(array_keys($changes['attributes'] ?? []))->toBe(['bank_fingerprint'])
            ->and(array_keys($changes['old'] ?? []))->toBe(['bank_fingerprint'])
            ->and($changes['old']['bank_fingerprint'] ?? null)->toBe($fingerprintBefore)
            ->and($changes['attributes']['bank_fingerprint'] ?? null)->toBe($fingerprintAfter)
            ->and($fingerprintBefore)->toMatch('/^[0-9a-f]{12}$/')
            ->and($fingerprintAfter)->toMatch('/^[0-9a-f]{12}$/')
            ->and($fingerprintAfter)->not->toBe($fingerprintBefore)
            ->and($payload)->not->toContain(FictionalIban::czech('12'))
            ->and($payload)->not->toContain(FictionalIban::czech('56'))
            ->and($payload)->not->toContain('Example CZK account')
            ->and($payload)->not->toContain('Replacement CZK account')
            ->and(ActivityPresenter::changes($edited[0]))->toContain('Bankovní účet (otisk): ')
            ->and(ActivityPresenter::changes($edited[0]))->not->toContain('bank_fingerprint')
            ->and(count(editAuditRows($invoice, 'updated')))->toBe($updatedBefore)
            ->and(count(editAuditRows($invoice)))->toBe($rowsBefore + 1);
    });

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

describe('what the audit row holds', function (): void {
    it('writes no row for an edit that changes nothing', function (): void {
        $invoice = editAuditSentAndUnlocked();
        [$first, $second] = editAuditLines($invoice);
        $rowsBefore = count(editAuditRows($invoice));

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'items' => [editAuditPayload($first), editAuditPayload($second)],
        ]);

        expect(editAuditRows($invoice, 'invoice_edited'))->toBe([])
            ->and(count(editAuditRows($invoice)))->toBe($rowsBefore);
    });

    it('carries no bank key while the account stays, and never the pay token or the internal note', function (): void {
        CardPaymentSetup::enable();
        $invoice = editAuditSentAndUnlocked(InvoiceFixtures::issued(CardPaymentSetup::client(), [InvoiceFixtures::item('Design', '2', '500.00')]));
        [$first] = editAuditLines($invoice);
        $token = (string) DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pay_token');
        $internal = 'Internal '.'example remark';

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'items' => [editAuditPayload($first, ['quantity' => '5'])],
            'internal_note' => $internal,
        ]);

        $edited = editAuditRows($invoice, 'invoice_edited');
        $payload = (string) json_encode($edited[0]->attribute_changes?->all());

        expect($edited)->toHaveCount(1)
            ->and($token)->toMatch('/^[A-Za-z0-9]{32}$/')
            ->and($payload)->not->toContain($token)
            ->and($payload)->not->toContain($internal)
            ->and($payload)->not->toContain('bank')
            ->and($payload)->not->toContain('internal_note')
            ->and($payload)->toContain('items.1.quantity');
    });

    it('stores a long party value whole and shows it cut to 80 characters', function (): void {
        $invoice = editAuditSentAndUnlocked();
        $long = str_repeat('x', 120);
        $before = InvoiceAudit::snapshot($invoice);
        $before['customer.name'] = $long;

        InvoiceAudit::edited($invoice, $this->admin, $before);

        $edited = editAuditRows($invoice, 'invoice_edited');
        $stored = $edited[0]->attribute_changes?->all();
        $shown = ActivityPresenter::changes($edited[0]);

        expect($edited)->toHaveCount(1)
            ->and($stored['old']['customer.name'] ?? null)->toBe($long)
            ->and($stored['attributes']['customer.name'] ?? null)->toBe($invoice->customer_snapshot['name'] ?? null)
            ->and($shown)->toContain(str_repeat('x', 80).'…')
            ->and($shown)->not->toContain(str_repeat('x', 81));
    });
});

describe('how the history tab reads an edit row', function (): void {
    it('names edited lines, party fields and the note in Czech', function (): void {
        $invoice = editAuditSentAndUnlocked();
        $before = InvoiceAudit::snapshot($invoice);
        $before['items.2.quantity'] = '9.0000';
        $before['customer.name'] = 'Former Example Client';
        $before['note'] = 'Former example note.';

        InvoiceAudit::edited($invoice, $this->admin, $before);

        $edited = editAuditRows($invoice, 'invoice_edited');
        $shown = ActivityPresenter::changes($edited[0]);

        expect($edited)->toHaveCount(1)
            ->and($shown)->toContain('Položka 2: Množství: 9.0000 -> 1.0000')
            ->and($shown)->toContain('Odběratel: Název: Former Example Client -> '.($invoice->customer_snapshot['name'] ?? ''))
            ->and($shown)->toContain('Poznámka na dokladu: Former example note. -> '.__('kokpit.activity.empty_value'))
            ->and($shown)->not->toContain('items.2')
            ->and($shown)->not->toContain('customer.name');
    });

    it('names a supplier field and a line text', function (): void {
        $invoice = editAuditSentAndUnlocked();
        $before = InvoiceAudit::snapshot($invoice);
        $before['supplier.vat_id'] = 'CZ00000000';
        $before['items.1.description'] = 'Former text';

        InvoiceAudit::edited($invoice, $this->admin, $before);

        $shown = ActivityPresenter::changes(editAuditRows($invoice, 'invoice_edited')[0]);

        expect($shown)->toContain('Dodavatel: DIČ: CZ00000000 -> ')
            ->and($shown)->toContain('Položka 1: Text: Former text -> Design');
    });

    it('keeps the raw name for a dotted key of another subject', function (): void {
        $activity = new Activity;
        $activity->setAttribute('subject_type', 'client');
        $activity->setAttribute('attribute_changes', ['attributes' => ['items.2.quantity' => '3'], 'old' => ['items.2.quantity' => '1']]);

        expect(ActivityPresenter::changes($activity))->toBe('items.2.quantity: 1 -> 3');
    });

    it('keeps the plain label of an ordinary invoice key', function (): void {
        $activity = new Activity;
        $activity->setAttribute('subject_type', 'invoice');
        $activity->setAttribute('attribute_changes', ['attributes' => ['total_minor' => 3], 'old' => ['total_minor' => 1]]);

        expect(ActivityPresenter::changes($activity))->toBe('Celkem (v nejmenších jednotkách měny): 1 -> 3');
    });

    it('has a Czech label for the three events of the lock', function (): void {
        expect(__('kokpit.activity.events.invoice_edited'))->toBe('Doklad upraven po odeslání')
            ->and(__('kokpit.activity.events.invoice_locked'))->toBe('Doklad uzamčen odesláním')
            ->and(__('kokpit.activity.events.invoice_unlocked'))->toBe('Doklad odemčen');
    });
});
