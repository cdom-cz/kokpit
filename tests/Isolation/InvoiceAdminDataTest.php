<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\MorphMap;
use App\Domain\Shared\Models\Activity;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;

/*
 * What a Partner reads of the Admin-only half of a billing document (IN-03, IN-05, IN-09, T-10-21, T-10-22): the
 * internal note, the CNB snapshot with the CZK total, the stored PDF link, the payments, the line sources and the
 * e-mail log are closed to every Partner, even for an issued invoice of the own client that the Partner may read.
 * Rows are written in system runs; every name, number and amount is fictional and assembled at runtime.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function adminDataSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Writes a draft invoice of the client, with its Admin-only row, and issues it. The Admin-only row goes in while
 * the document is a draft: an issued document accepts no new child row.
 *
 * @param  array<string, mixed>  $adminAttributes  forced onto the invoice_admin row
 * @return array{invoice: Invoice, admin: InvoiceAdmin}
 */
function adminDataIssuedInvoice(string $clientId, string $noteCanary, array $adminAttributes = []): array
{
    return adminDataSystem(static function () use ($clientId, $noteCanary, $adminAttributes): array {
        $invoice = new Invoice;
        $invoice->forceFill([
            'client_id' => $clientId,
            'kind' => 'invoice',
            'currency' => 'CZK',
            'language' => 'cs',
            'vat_mode' => 'non_payer',
            'subtotal_minor' => 10000,
            'vat_minor' => 0,
            'total_minor' => 10000,
        ])->save();

        $admin = new InvoiceAdmin(['internal_note' => $noteCanary]);
        $admin->forceFill([
            'invoice_id' => $invoice->id,
            'internal_note_updated_at' => now(),
            'exchange_rate' => '24.5000000000',
            'exchange_unit_amount' => 1,
            'exchange_rate_date' => '2026-10-16',
            'czk_total_minor' => 245000,
            ...$adminAttributes,
        ])->save();

        $invoice->forceFill([
            'state' => 'issued',
            'number' => implode('', ['D', (string) random_int(100000, 999999), (string) random_int(1000, 9999)]),
            'variable_symbol' => (string) random_int(1000000, 9999999),
            'issue_date' => '2026-10-17',
            'taxable_supply_date' => '2026-10-17',
            'due_date' => '2026-10-31',
            'supplier_snapshot' => ['name' => 'Example supplier'],
            'customer_snapshot' => ['name' => 'Example customer'],
            'bank_snapshot' => ['account' => 'Example account'],
            'appearance_snapshot' => ['accent' => '#000000'],
            'issued_at' => now(),
        ])->save();

        return ['invoice' => $invoice->refresh(), 'admin' => $admin->refresh()];
    });
}

/**
 * Stores one file row owned by the invoice by raw SQL (the `invoice` owner kind of DocumentOwnerType arrives in
 * plan 10-20) and links it as the stored PDF of the invoice. Returns the document id.
 */
function adminDataLinkPdf(Invoice $invoice, InvoiceAdmin $admin, string $uploaderId): string
{
    return adminDataSystem(static function () use ($invoice, $admin, $uploaderId): string {
        /** @var object{id: string} $inserted */
        $inserted = DB::selectOne(
            "insert into documents (name, mime_type, size_bytes, uploaded_by_id, owner_type, owner_id, created_at, updated_at) values ('Fictional.pdf', 'application/pdf', 1024, ?, 'invoice', ?, now(), now()) returning id",
            [$uploaderId, $invoice->id],
        );

        $admin->forceFill(['pdf_document_id' => $inserted->id])->save();

        return $inserted->id;
    });
}

/**
 * Records one payment of the issued invoice, the way the payment Action will: forceFill in a system run.
 */
function adminDataPayment(Invoice $invoice, string $userId, string $noteCanary, int $amountMinor = 4000, string $paidOn = '2026-10-20'): InvoicePayment
{
    return adminDataSystem(static function () use ($invoice, $userId, $noteCanary, $amountMinor, $paidOn): InvoicePayment {
        $payment = new InvoicePayment(['note' => $noteCanary]);
        $payment->forceFill([
            'invoice_id' => $invoice->id,
            'currency' => $invoice->currency,
            'amount_minor' => $amountMinor,
            'paid_on' => $paidOn,
            'method' => PaymentMethod::Transfer,
            'created_by' => $userId,
        ])->save();

        return $payment->refresh();
    });
}

/**
 * Writes one issued invoice of the client that carries a billed line: the line, its source row (project, task,
 * source rate, exact seconds, conversion snapshot), two e-mails of the log and one time entry reserved for the
 * document. The source and the e-mails go in while the document is a draft: an issued document accepts no new
 * child row.
 *
 * @return array{invoice: Invoice, item: InvoiceItem, source: InvoiceItemSource, firstEmail: InvoiceEmail, secondEmail: InvoiceEmail, entry: TimeEntry, project: Project, task: Task}
 */
function adminDataSourcedInvoice(string $clientId, string $userId, string $canary): array
{
    return adminDataSystem(static function () use ($clientId, $userId, $canary): array {
        $project = Project::factory()->create(['client_id' => $clientId]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $invoice = new Invoice;
        $invoice->forceFill([
            'client_id' => $clientId,
            'kind' => 'invoice',
            'currency' => 'CZK',
            'language' => 'cs',
            'vat_mode' => 'non_payer',
            'subtotal_minor' => 10000,
            'vat_minor' => 0,
            'total_minor' => 10000,
        ])->save();

        $item = (new InvoiceItem(['description' => 'Example consulting', 'unit' => 'h']))->forceFill([
            'invoice_id' => $invoice->id,
            'position' => 1,
            'quantity' => '2.0000',
            'unit_price_minor' => 5000,
            'line_total_minor' => 10000,
        ]);
        $item->save();

        $source = new InvoiceItemSource;
        $source->forceFill([
            'invoice_item_id' => $item->id,
            'invoice_id' => $invoice->id,
            'origin' => InvoiceItemOrigin::Time,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'source_rate_minor' => 150000,
            'source_rate_currency' => 'CZK',
            'source_seconds' => 7200,
            'price_manual' => false,
            'conversion_snapshot' => ['rate_note' => $canary],
        ])->save();

        $firstEmail = (new InvoiceEmail)->forceFill([
            'invoice_id' => $invoice->id,
            'recipient' => 'jane@'.implode('.', ['example', 'com']),
            'subject' => $canary,
            'body' => 'Fictional body',
            'state' => InvoiceEmailState::Queued,
            'created_by' => $userId,
            'created_at' => '2026-10-17 10:00:00+00',
            'updated_at' => '2026-10-17 10:00:00+00',
        ]);
        $firstEmail->save();

        $secondEmail = (new InvoiceEmail)->forceFill([
            'invoice_id' => $invoice->id,
            'recipient' => 'jane@'.implode('.', ['example', 'com']),
            'subject' => $canary,
            'body' => 'Fictional body',
            'state' => InvoiceEmailState::Queued,
            'created_by' => $userId,
            'created_at' => '2026-10-18 10:00:00+00',
            'updated_at' => '2026-10-18 10:00:00+00',
        ]);
        $secondEmail->save();

        $entry = TimeEntry::factory()->forProject($project)->create();
        $entry->forceFill(['invoice_id' => $invoice->id])->save();

        $invoice->forceFill([
            'state' => 'issued',
            'number' => implode('', ['S', (string) random_int(100000, 999999), (string) random_int(1000, 9999)]),
            'variable_symbol' => (string) random_int(1000000, 9999999),
            'issue_date' => '2026-10-17',
            'taxable_supply_date' => '2026-10-17',
            'due_date' => '2026-10-31',
            'supplier_snapshot' => ['name' => 'Example supplier'],
            'customer_snapshot' => ['name' => 'Example customer'],
            'bank_snapshot' => ['account' => 'Example account'],
            'appearance_snapshot' => ['accent' => '#000000'],
            'issued_at' => now(),
        ])->save();

        return [
            'invoice' => $invoice->refresh(),
            'item' => $item->refresh(),
            'source' => $source->refresh(),
            'firstEmail' => $firstEmail->refresh(),
            'secondEmail' => $secondEmail->refresh(),
            'entry' => $entry->refresh(),
            'project' => $project,
            'task' => $task,
        ];
    });
}

beforeEach(function (): void {
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->noteCanary = Canary::canary('internal_note');
    $this->paymentCanary = Canary::canary('payment_note');

    $this->adminUser = Canary::admin();
    ['invoice' => $this->invoice, 'admin' => $this->adminRow] = adminDataIssuedInvoice($this->clientA, $this->noteCanary);
    $this->documentId = adminDataLinkPdf($this->invoice, $this->adminRow, $this->adminUser->id);
    $this->payment = adminDataPayment($this->invoice, $this->adminUser->id, $this->paymentCanary);
});

describe('tracer: note, CNB snapshot, PDF link and payments of an issued invoice', function (): void {
    it('lets the Admin read the internal note, the CNB snapshot, the CZK total and the stored PDF through the invoice', function (): void {
        $this->actingAs($this->adminUser);

        $invoice = Invoice::query()->findOrFail($this->invoice->id);
        $admin = $invoice->admin;

        expect($admin)->toBeInstanceOf(InvoiceAdmin::class)
            ->and($admin->internal_note)->toBe($this->noteCanary)
            ->and($admin->exchange_rate)->toBe('24.5000000000')
            ->and($admin->exchange_unit_amount)->toBe(1)
            ->and($admin->czk_total_minor)->toBe(245000)
            ->and($admin->czkTotal()?->minor)->toBe(245000)
            ->and($admin->czkTotal()?->currency)->toBe('CZK')
            ->and($admin->invoice->is($invoice))->toBeTrue()
            ->and($admin->pdfDocument)->toBeInstanceOf(Document::class)
            ->and($admin->pdfDocument->id)->toBe($this->documentId);
    });

    it('lets the Admin read the payments through the invoice, oldest payment date first', function (): void {
        $later = adminDataPayment($this->invoice, $this->adminUser->id, $this->paymentCanary, 1000, '2026-10-25');
        $earlier = adminDataPayment($this->invoice, $this->adminUser->id, $this->paymentCanary, 2000, '2026-10-18');
        $this->actingAs($this->adminUser);

        $payments = Invoice::query()->findOrFail($this->invoice->id)->payments;

        expect($payments->pluck('id')->all())->toBe([$earlier->id, $this->payment->id, $later->id])
            ->and($payments->first()?->amount()->minor)->toBe(2000)
            ->and($payments->first()?->method)->toBe(PaymentMethod::Transfer)
            ->and($payments->first()?->note)->toBe($this->paymentCanary)
            ->and($payments->first()?->invoice->is($this->invoice))->toBeTrue();
    });

    it('reads a system run the stored PDF of the document, and the Admin-only row, without a user', function (): void {
        $pdf = adminDataSystem(fn (): ?Document => InvoiceAdmin::query()->where('invoice_id', $this->invoice->id)->firstOrFail()->pdfDocument);

        expect($pdf?->id)->toBe($this->documentId);
    });

    it('gives a Partner of the same client no Admin-only row, no payment and no PDF link', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $invoice = Invoice::query()->find($this->invoice->id);

        expect($invoice)->not->toBeNull()
            ->and($invoice?->admin)->toBeNull()
            ->and($invoice?->payments)->toHaveCount(0)
            ->and(InvoiceAdmin::query()->count())->toBe(0)
            ->and(InvoicePayment::query()->count())->toBe(0)
            ->and(InvoiceAdmin::query()->find($this->adminRow->id))->toBeNull()
            ->and(InvoicePayment::query()->find($this->payment->id))->toBeNull()
            ->and(InvoiceAdmin::query()->where('internal_note', $this->noteCanary)->exists())->toBeFalse();
    });

    it('lets a Partner of the same client read the printed invoice without any canary of the Admin-only half', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $json = (string) json_encode(Invoice::query()->with(['admin', 'payments'])->get()->toArray());

        expect($json)->not->toContain($this->noteCanary)
            ->and($json)->not->toContain($this->paymentCanary)
            ->and($json)->not->toContain('24.5')
            ->and($json)->not->toContain((string) $this->documentId);
    });

    it('denies a Partner every ability on an Admin-only invoicing row and admits the Admin', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        foreach ([$this->adminRow, $this->payment] as $row) {
            expect(Gate::forUser($partner)->denies('view', $row))->toBeTrue()
                ->and(Gate::forUser($partner)->denies('viewAny', $row::class))->toBeTrue()
                ->and(Gate::forUser($partner)->denies('create', $row::class))->toBeTrue()
                ->and(Gate::forUser($partner)->denies('update', $row))->toBeTrue()
                ->and(Gate::forUser($partner)->denies('delete', $row))->toBeTrue()
                ->and(Gate::forUser($this->adminUser)->allows('view', $row))->toBeTrue();
        }
    });
});

describe('activity history', function (): void {
    it('logs the payment under the invoice_payment alias with the allowlisted columns and never the note', function (): void {
        $this->actingAs($this->adminUser);

        $rows = Activity::query()->where('subject_type', 'invoice_payment')->where('subject_id', $this->payment->id)->get();
        $json = (string) json_encode($rows->map(static fn (Activity $row): mixed => $row->attribute_changes)->all());

        expect($rows)->toHaveCount(1)
            ->and($json)->toContain('amount_minor')
            ->and($json)->toContain('paid_on')
            ->and($json)->toContain('method')
            ->and($json)->not->toContain($this->paymentCanary)
            ->and($json)->not->toContain('"note"');
    });

    it('logs the Admin-only row under the invoice_admin alias with the marker, the CNB columns and the PDF link, never the note text', function (): void {
        $this->actingAs($this->adminUser);

        $rows = Activity::query()->where('subject_type', 'invoice_admin')->where('subject_id', $this->adminRow->id)->get();
        $json = (string) json_encode($rows->map(static fn (Activity $row): mixed => $row->attribute_changes)->all());

        expect($rows->count())->toBeGreaterThanOrEqual(1)
            ->and($json)->toContain('internal_note_updated_at')
            ->and($json)->toContain('czk_total_minor')
            ->and($json)->toContain('pdf_document_id')
            ->and($json)->not->toContain($this->noteCanary)
            ->and($json)->not->toContain('"internal_note"');
    });
});

describe('no touch of the issued invoice', function (): void {
    it('leaves the issued invoice row unchanged when a payment is recorded as system', function (): void {
        $before = (array) DB::table('invoices')->where('id', $this->invoice->id)->first();

        adminDataPayment($this->invoice, $this->adminUser->id, $this->paymentCanary, 500, '2026-10-26');

        $after = (array) DB::table('invoices')->where('id', $this->invoice->id)->first();

        expect($after)->toBe($before);
    });

    it('declares no $touches toward the invoice on any Admin-only invoicing model', function (): void {
        foreach ([InvoiceAdmin::class, InvoicePayment::class] as $class) {
            expect((new ReflectionClass($class))->getDefaultProperties()['touches'] ?? [])->toBe([], $class);
        }
    });
});

describe('line sources, linked time and the e-mail log', function (): void {
    beforeEach(function (): void {
        $this->sourceCanary = Canary::canary('source_note');
        $this->sourced = adminDataSourcedInvoice($this->clientA, $this->adminUser->id, $this->sourceCanary);
    });

    it('lets the Admin read the generation source of a line with its rates, seconds and conversion snapshot', function (): void {
        $this->actingAs($this->adminUser);

        $item = InvoiceItem::query()->findOrFail($this->sourced['item']->id);
        $source = $item->source;

        expect($source)->toBeInstanceOf(InvoiceItemSource::class)
            ->and($source->origin)->toBe(InvoiceItemOrigin::Time)
            ->and($source->sourceRate()?->minor)->toBe(150000)
            ->and($source->sourceRate()?->currency)->toBe('CZK')
            ->and($source->source_seconds)->toBe(7200)
            ->and($source->price_manual)->toBeFalse()
            ->and($source->conversion_snapshot)->toBe(['rate_note' => $this->sourceCanary])
            ->and($source->item->is($item))->toBeTrue()
            ->and($source->invoice->id)->toBe($this->sourced['invoice']->id)
            ->and($source->project?->id)->toBe($this->sourced['project']->id)
            ->and($source->task?->id)->toBe($this->sourced['task']->id);
    });

    it('reads no source rate from a source without one', function (): void {
        $source = new InvoiceItemSource;
        $source->forceFill(['origin' => InvoiceItemOrigin::FixedAmount]);

        expect($source->sourceRate())->toBeNull()
            ->and($source->origin)->toBe(InvoiceItemOrigin::FixedAmount);
    });

    it('lets the Admin read the e-mail log of a document, latest first, and the linked time', function (): void {
        $this->actingAs($this->adminUser);

        $invoice = Invoice::query()->findOrFail($this->sourced['invoice']->id);

        expect($invoice->emails->pluck('id')->all())->toBe([$this->sourced['secondEmail']->id, $this->sourced['firstEmail']->id])
            ->and($invoice->emails->first()?->state)->toBe(InvoiceEmailState::Queued)
            ->and($invoice->emails->first()?->subject)->toBe($this->sourceCanary)
            ->and($invoice->emails->first()?->invoice->is($invoice))->toBeTrue()
            ->and($invoice->entries->pluck('id')->all())->toBe([$this->sourced['entry']->id]);
    });

    it('shows a Partner of the same client no source, no e-mail and no linked time through the visible document', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $invoice = Invoice::query()->findOrFail($this->sourced['invoice']->id);
        $item = $invoice->items->first();

        expect($item)->toBeInstanceOf(InvoiceItem::class)
            ->and($item->source)->toBeNull()
            ->and($invoice->emails)->toHaveCount(0)
            ->and($invoice->entries)->toHaveCount(0)
            ->and(InvoiceItemSource::query()->count())->toBe(0)
            ->and(InvoiceEmail::query()->count())->toBe(0)
            ->and(InvoiceEmail::query()->where('subject', $this->sourceCanary)->exists())->toBeFalse();

        $json = (string) json_encode(Invoice::query()->with(['items.source', 'emails', 'entries'])->get()->toArray());

        expect($json)->not->toContain($this->sourceCanary)->and($json)->not->toContain('150000');
    });

    it('denies a Partner every ability on a source row and an e-mail row and admits the Admin', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        foreach ([$this->sourced['source'], $this->sourced['firstEmail']] as $row) {
            expect(Gate::forUser($partner)->denies('view', $row))->toBeTrue()
                ->and(Gate::forUser($partner)->denies('viewAny', $row::class))->toBeTrue()
                ->and(Gate::forUser($partner)->denies('create', $row::class))->toBeTrue()
                ->and(Gate::forUser($partner)->denies('update', $row))->toBeTrue()
                ->and(Gate::forUser($partner)->denies('delete', $row))->toBeTrue()
                ->and(Gate::forUser($this->adminUser)->allows('view', $row))->toBeTrue();
        }
    });

    it('registers the four morph aliases of the Admin-only invoicing models', function (): void {
        expect(MorphMap::MAP)->toMatchArray([
            'invoice_admin' => InvoiceAdmin::class,
            'invoice_item_source' => InvoiceItemSource::class,
            'invoice_payment' => InvoicePayment::class,
            'invoice_email' => InvoiceEmail::class,
        ]);
    });

    it('declares no $touches toward the invoice on the source and e-mail models', function (): void {
        foreach ([InvoiceItemSource::class, InvoiceEmail::class] as $class) {
            expect((new ReflectionClass($class))->getDefaultProperties()['touches'] ?? [])->toBe([], $class);
        }
    });
});
