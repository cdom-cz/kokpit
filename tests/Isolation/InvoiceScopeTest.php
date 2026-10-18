<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;

/*
 * What a Partner sees of billing documents (IN-09, UI-SPEC assumption U-2): exactly the issued invoices, proformas
 * and credit notes of the own client while that client is not archived; a draft never exists for a Partner, not even
 * as a count. Cancelled is derived from the issued storno credit note and never stored on the invoice (D-14).
 * Rows are written in system runs; every number, name and snapshot is fictional and assembled at runtime.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function invoiceScopeSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A document number assembled from fragments, unique per call.
 */
function invoiceScopeNumber(string $prefix): string
{
    return implode('', [$prefix, (string) random_int(100000, 999999), (string) random_int(1000, 9999)]);
}

/**
 * Writes one document of the client in a system run: a draft by default, or an issued one carrying everything an
 * issued document must print (the database refuses less). A credit note is negative once issued.
 *
 * @param  array<string, mixed>  $attributes  forced over the defaults
 * @param  list<string>  $items  descriptions of the lines written while the document is still a draft
 */
function invoiceScopeDocument(string $clientId, InvoiceKind $kind = InvoiceKind::Invoice, bool $issued = false, array $attributes = [], array $items = []): Invoice
{
    return invoiceScopeSystem(static function () use ($clientId, $kind, $issued, $attributes, $items): Invoice {
        $isCredit = $kind === InvoiceKind::CreditNote;
        $amount = $isCredit ? -10000 : 10000;

        $invoice = new Invoice;
        $invoice->forceFill([
            'client_id' => $clientId,
            'kind' => $kind,
            'currency' => 'CZK',
            'language' => 'cs',
            'vat_mode' => 'non_payer',
            'subtotal_minor' => $amount,
            'vat_minor' => 0,
            'total_minor' => $amount,
            ...$attributes,
        ])->save();

        // The lines go in while the document is a draft: an issued document accepts no new line.
        foreach (array_values($items) as $index => $description) {
            (new InvoiceItem(['description' => $description, 'unit' => 'h']))->forceFill([
                'invoice_id' => $invoice->id,
                'position' => $index + 1,
                'quantity' => '2.0000',
                'unit_price_minor' => $amount < 0 ? 0 : 5000,
                'line_total_minor' => $amount < 0 ? 0 : 10000,
            ])->save();
        }

        if ($issued) {
            $invoice->forceFill([
                'state' => InvoiceState::Issued,
                'number' => invoiceScopeNumber(strtoupper(substr($kind->value, 0, 1))),
                'issue_date' => '2026-10-01',
                'taxable_supply_date' => '2026-10-01',
                'due_date' => $isCredit ? null : '2026-10-15',
                'variable_symbol' => $isCredit ? null : (string) random_int(1000000, 9999999),
                'supplier_snapshot' => ['name' => 'Example supplier'],
                'customer_snapshot' => ['name' => 'Example customer'],
                'bank_snapshot' => $isCredit ? null : ['account' => 'Example account'],
                'appearance_snapshot' => ['accent' => '#000000'],
                'issued_at' => now(),
            ])->save();
        }

        return $invoice->refresh();
    });
}

/**
 * The raw database row of a document, as one array, for an "unchanged" comparison.
 *
 * @return array<string, mixed>
 */
function invoiceScopeRawRow(string $id): array
{
    return (array) DB::table('invoices')->where('id', $id)->first();
}

/**
 * The ids the current user sees, sorted.
 *
 * @return list<string>
 */
function invoiceScopeIds(): array
{
    $ids = Invoice::query()->pluck('id')->all();
    sort($ids, SORT_STRING);

    return array_values($ids);
}

/**
 * @param  list<Invoice>  $documents
 * @return list<string>
 */
function invoiceScopeIdsOf(array $documents): array
{
    $ids = array_map(static fn (Invoice $invoice): string => $invoice->id, $documents);
    sort($ids, SORT_STRING);

    return $ids;
}

beforeEach(function (): void {
    [$this->clientA, $this->clientB] = Canary::twoClients();

    $this->itemTextA = Canary::canary('item_a');
    $this->itemTextDraftA = Canary::canary('item_draft_a');
    $this->itemTextB = Canary::canary('item_b');

    $this->invoiceA = invoiceScopeDocument($this->clientA, InvoiceKind::Invoice, issued: true, items: [$this->itemTextA]);
    $this->proformaA = invoiceScopeDocument($this->clientA, InvoiceKind::Proforma, issued: true);
    $this->creditNoteA = invoiceScopeDocument($this->clientA, InvoiceKind::CreditNote, issued: true, attributes: ['credited_invoice_id' => $this->invoiceA->id]);
    $this->draftA = invoiceScopeDocument($this->clientA, items: [$this->itemTextDraftA]);
    $this->invoiceB = invoiceScopeDocument($this->clientB, InvoiceKind::Invoice, issued: true, items: [$this->itemTextB]);
    $this->draftB = invoiceScopeDocument($this->clientB);
});

describe('Partner query', function (): void {
    it('shows Partner A exactly the issued invoice, proforma and credit note of the own client', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        expect(invoiceScopeIds())->toBe(invoiceScopeIdsOf([$this->invoiceA, $this->proformaA, $this->creditNoteA]))
            ->and(Invoice::query()->count())->toBe(3);
    });

    it('never shows a draft to a Partner, not even as a count or by its id', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        expect(Invoice::query()->find($this->draftA->id))->toBeNull()
            ->and(Invoice::query()->whereKey($this->draftA->id)->exists())->toBeFalse()
            ->and(Invoice::query()->where('state', InvoiceState::Draft->value)->count())->toBe(0)
            ->and(Invoice::query()->whereNull('number')->count())->toBe(0);
    });

    it('does not show Partner A a document of client B, not even by its id', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        expect(Invoice::query()->find($this->invoiceB->id))->toBeNull()
            ->and(Invoice::query()->where('client_id', $this->clientB)->count())->toBe(0);
    });

    it('keeps the constraint when the Partner adds an or-condition', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $ids = Invoice::query()
            ->where('client_id', $this->clientA)
            ->orWhere('client_id', $this->clientB)
            ->orWhere('state', InvoiceState::Draft->value)
            ->pluck('id')
            ->all();
        sort($ids, SORT_STRING);

        expect($ids)->toBe(invoiceScopeIdsOf([$this->invoiceA, $this->proformaA, $this->creditNoteA]));
    });

    it('shows the Partner of client B only the issued invoice of client B', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientB));

        expect(invoiceScopeIds())->toBe([$this->invoiceB->id]);
    });

    it('hides every document of an archived client and shows them again after the client is restored', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        invoiceScopeSystem(fn () => Client::query()->whereKey($this->clientA)->firstOrFail()->delete());
        $this->actingAs($partner);

        expect(invoiceScopeIds())->toBe([]);

        invoiceScopeSystem(fn () => Client::withTrashed()->whereKey($this->clientA)->firstOrFail()->restore());

        expect(invoiceScopeIds())->toBe(invoiceScopeIdsOf([$this->invoiceA, $this->proformaA, $this->creditNoteA]));
    });

    it('returns null for the client of a document, because Client stays closed to Partners', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        expect(Invoice::query()->firstOrFail()->client)->toBeNull();
    });
});

describe('Admin and system run', function (): void {
    it('shows the Admin every document including drafts and other clients', function (): void {
        $this->actingAs(Canary::admin());

        expect(invoiceScopeIds())->toBe(invoiceScopeIdsOf([
            $this->invoiceA, $this->proformaA, $this->creditNoteA, $this->draftA, $this->invoiceB, $this->draftB,
        ]));
    });

    it('shows the Admin the documents of an archived client, with the client', function (): void {
        invoiceScopeSystem(fn () => Client::query()->whereKey($this->clientA)->firstOrFail()->delete());

        $this->actingAs(Canary::admin());

        expect(Invoice::query()->where('client_id', $this->clientA)->count())->toBe(4)
            ->and(Invoice::query()->findOrFail($this->invoiceA->id)->client?->id)->toBe($this->clientA);
    });

    it('shows a system run every document without a user', function (): void {
        expect(invoiceScopeSystem(static fn (): int => Invoice::query()->count()))->toBe(6)
            ->and(Invoice::query()->count())->toBe(0);
    });
});

describe('fail-closed states', function (): void {
    it('shows a guest, a Partner without a client and a role-less user no document', function (): void {
        expect(Invoice::query()->count())->toBe(0);

        $this->actingAs(Canary::partnerFor(null));
        expect(Invoice::query()->count())->toBe(0);

        $this->actingAs(Canary::userWithoutRole($this->clientA));
        expect(Invoice::query()->count())->toBe(0);
    });
});

describe('InvoicePolicy', function (): void {
    it('grants a Partner viewAny and view of an issued document of the own client, of every kind', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        expect($partner->can('viewAny', Invoice::class))->toBeTrue()
            ->and($partner->can('view', $this->invoiceA))->toBeTrue()
            ->and($partner->can('view', $this->proformaA))->toBeTrue()
            ->and($partner->can('view', $this->creditNoteA))->toBeTrue();
    });

    it('denies a Partner view of a draft and of any document of another client', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        expect(Gate::forUser($partner)->allows('view', $this->draftA))->toBeFalse()
            ->and(Gate::forUser($partner)->allows('view', $this->invoiceB))->toBeFalse()
            ->and(Gate::forUser($partner)->allows('view', $this->draftB))->toBeFalse();
    });

    it('denies a Partner every write ability on every document', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        expect($partner->can('create', Invoice::class))->toBeFalse();

        foreach ([$this->invoiceA, $this->draftA, $this->invoiceB] as $document) {
            foreach (['update', 'delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
                expect($partner->can($ability, $document))->toBeFalse("{$ability} must be denied for a Partner");
            }
        }
    });

    it('denies a Partner without a client and a role-less user everything', function (): void {
        foreach ([Canary::partnerFor(null), Canary::userWithoutRole($this->clientA)] as $user) {
            expect($user->can('viewAny', Invoice::class))->toBeFalse()
                ->and($user->can('view', $this->invoiceA))->toBeFalse()
                ->and($user->can('create', Invoice::class))->toBeFalse();
        }
    });

    it('lets the Admin do everything', function (): void {
        $admin = Canary::admin();

        expect($admin->can('viewAny', Invoice::class))->toBeTrue()
            ->and($admin->can('create', Invoice::class))->toBeTrue();

        foreach ([$this->invoiceA, $this->draftA, $this->invoiceB] as $document) {
            foreach (['view', 'update', 'delete'] as $ability) {
                expect($admin->can($ability, $document))->toBeTrue("{$ability} must be allowed for the Admin");
            }
        }
    });
});

describe('mass assignment and history', function (): void {
    it('makes only the printed note fillable, so no request can write the client, state, number, totals or snapshots', function (): void {
        expect((new Invoice)->getFillable())->toBe(['note'])
            ->and(fn () => new Invoice(['client_id' => $this->clientB]))->toThrow(MassAssignmentException::class)
            ->and(fn () => new Invoice(['state' => 'issued']))->toThrow(MassAssignmentException::class)
            ->and(fn () => new Invoice(['total_minor' => 1]))->toThrow(MassAssignmentException::class);
    });

    it('writes the history of a saved draft under the morph alias invoice, without the note and the snapshots', function (): void {
        $this->actingAs(Canary::admin());

        $draft = Invoice::query()->findOrFail($this->draftA->id);
        $draft->forceFill(['currency' => 'EUR', 'note' => 'A printed note that must stay out of the history'])->save();

        $row = Activity::query()->where('subject_type', 'invoice')->where('subject_id', $draft->id)->latest('id')->firstOrFail();
        $changes = (string) json_encode($row->attribute_changes);

        expect($row->subject_type)->toBe('invoice')
            ->and($changes)->toContain('EUR')
            ->and($changes)->not->toContain('printed note')
            ->and($changes)->not->toContain('snapshot');
    });

    it('lists no free-text column and no snapshot in the logged attributes', function (): void {
        $logged = Invoice::loggedAttributes();

        expect($logged)->toContain('number', 'state', 'kind', 'client_id', 'currency', 'language', 'total_minor', 'proforma_id', 'credited_invoice_id', 'cancels_invoice')
            ->and($logged)->not->toContain('note')
            ->and(array_filter($logged, static fn (string $name): bool => str_ends_with($name, '_snapshot')))->toBe([]);
    });
});

describe('derived cancellation (D-14, R-04-5)', function (): void {
    it('is cancelled exactly when an issued credit note with cancels_invoice names the invoice, for the Admin and the Partner', function (): void {
        $invoice = invoiceScopeDocument($this->clientA, InvoiceKind::Invoice, issued: true);
        $before = invoiceScopeRawRow($invoice->id);

        $this->actingAs(Canary::admin());

        expect(Invoice::query()->findOrFail($invoice->id)->isCancelled())->toBeFalse();

        $storno = invoiceScopeDocument($this->clientA, InvoiceKind::CreditNote, issued: true, attributes: [
            'credited_invoice_id' => $invoice->id,
            'cancels_invoice' => true,
        ]);

        foreach ([Canary::admin(), Canary::partnerFor($this->clientA)] as $user) {
            $this->actingAs($user);

            $found = Invoice::query()->findOrFail($invoice->id);

            expect($found->isCancelled())->toBeTrue()
                ->and($found->stornoCreditNote?->id)->toBe($storno->id)
                ->and(Invoice::query()->cancelled()->pluck('id')->all())->toBe([$invoice->id])
                ->and(Invoice::query()->notCancelled()->pluck('id')->all())->not->toContain($invoice->id)
                ->and(Invoice::query()->whereRaw(Invoice::CANCELLED_SQL)->pluck('id')->all())->toBe([$invoice->id]);
        }

        // Nothing is written on the cancelled invoice: the marker is the credit note.
        expect(invoiceScopeRawRow($invoice->id))->toBe($before);
    });

    it('reads the cancelled flag of a list from the loaded withExists attribute without a query per row', function (): void {
        $invoice = invoiceScopeDocument($this->clientA, InvoiceKind::Invoice, issued: true);
        invoiceScopeDocument($this->clientA, InvoiceKind::CreditNote, issued: true, attributes: [
            'credited_invoice_id' => $invoice->id,
            'cancels_invoice' => true,
        ]);

        $this->actingAs(Canary::admin());

        $rows = Invoice::query()->withExists('stornoCreditNote')->get()->keyBy('id');

        DB::enableQueryLog();
        $flags = $rows->map(static fn (Invoice $row): bool => $row->isCancelled());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($flags[$invoice->id])->toBeTrue()
            ->and($flags[$this->invoiceA->id])->toBeFalse()
            ->and($queries)->toBe(0);
    });

    it('leaves the invoice uncancelled by a partial credit note and by a storno credit note that is still a draft', function (): void {
        $partial = invoiceScopeDocument($this->clientA, InvoiceKind::Invoice, issued: true);
        invoiceScopeDocument($this->clientA, InvoiceKind::CreditNote, issued: true, attributes: ['credited_invoice_id' => $partial->id]);

        $draftStorno = invoiceScopeDocument($this->clientA, InvoiceKind::Invoice, issued: true);
        invoiceScopeDocument($this->clientA, InvoiceKind::CreditNote, attributes: ['credited_invoice_id' => $draftStorno->id, 'cancels_invoice' => true]);

        $this->actingAs(Canary::admin());

        expect(Invoice::query()->findOrFail($partial->id)->isCancelled())->toBeFalse()
            ->and(Invoice::query()->findOrFail($draftStorno->id)->isCancelled())->toBeFalse()
            ->and(Invoice::query()->cancelled()->count())->toBe(0);
    });

    it('does not show a Partner the cancelled state through a credit note of another client', function (): void {
        // The database keeps a credit note and its invoice of one client, so the Partner of B never sees A's pair.
        $invoice = invoiceScopeDocument($this->clientA, InvoiceKind::Invoice, issued: true);
        invoiceScopeDocument($this->clientA, InvoiceKind::CreditNote, issued: true, attributes: ['credited_invoice_id' => $invoice->id, 'cancels_invoice' => true]);

        $this->actingAs(Canary::partnerFor($this->clientB));

        expect(Invoice::query()->cancelled()->count())->toBe(0)
            ->and(Invoice::query()->find($invoice->id))->toBeNull();
    });
});

describe('totals', function (): void {
    it('reads the totals as Money in the currency of the document', function (): void {
        $this->actingAs(Canary::admin());

        $invoice = Invoice::query()->findOrFail($this->invoiceA->id);

        expect($invoice->total())->toEqual(Money::ofMinor(10000, 'CZK'))
            ->and($invoice->subtotal())->toEqual(Money::ofMinor(10000, 'CZK'))
            ->and($invoice->isIssued())->toBeTrue()
            ->and($invoice->isDraft())->toBeFalse()
            ->and(Invoice::query()->findOrFail($this->draftA->id)->isDraft())->toBeTrue();
    });
});

describe('InvoiceItem', function (): void {
    it('shows Partner A the lines of the own issued document and none of the own draft or of client B', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $descriptions = InvoiceItem::query()->pluck('description')->all();

        expect($descriptions)->toBe([$this->itemTextA])
            ->and(InvoiceItem::query()->count())->toBe(1)
            ->and(InvoiceItem::query()->where('description', $this->itemTextDraftA)->exists())->toBeFalse()
            ->and(InvoiceItem::query()->where('description', $this->itemTextB)->exists())->toBeFalse();
    });

    it('hides the lines of an archived client and shows the Admin every line', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        invoiceScopeSystem(fn () => Client::query()->whereKey($this->clientA)->firstOrFail()->delete());
        $this->actingAs($partner);

        expect(InvoiceItem::query()->count())->toBe(0);

        $this->actingAs(Canary::admin());

        expect(InvoiceItem::query()->count())->toBe(3);
    });

    it('keeps the constraint when the Partner adds an or-condition', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $descriptions = InvoiceItem::query()
            ->where('description', $this->itemTextA)
            ->orWhere('description', $this->itemTextB)
            ->orWhere('description', $this->itemTextDraftA)
            ->pluck('description')
            ->all();

        expect($descriptions)->toBe([$this->itemTextA]);
    });

    it('shows a guest, a Partner without a client and a role-less user no line', function (): void {
        expect(InvoiceItem::query()->count())->toBe(0);

        $this->actingAs(Canary::partnerFor(null));
        expect(InvoiceItem::query()->count())->toBe(0);

        $this->actingAs(Canary::userWithoutRole($this->clientA));
        expect(InvoiceItem::query()->count())->toBe(0);
    });

    it('grants a Partner view of a line of an own issued document only, and every write ability on no line', function (): void {
        $partner = Canary::partnerFor($this->clientA);
        $this->actingAs($partner);

        $own = invoiceScopeSystem(fn () => InvoiceItem::query()->where('description', $this->itemTextA)->sole());
        $draft = invoiceScopeSystem(fn () => InvoiceItem::query()->where('description', $this->itemTextDraftA)->sole());
        $other = invoiceScopeSystem(fn () => InvoiceItem::query()->where('description', $this->itemTextB)->sole());

        expect($partner->can('viewAny', InvoiceItem::class))->toBeTrue()
            ->and($partner->can('view', $own))->toBeTrue()
            ->and($partner->can('view', $draft))->toBeFalse()
            ->and($partner->can('view', $other))->toBeFalse()
            ->and($partner->can('create', InvoiceItem::class))->toBeFalse();

        foreach ([$own, $draft, $other] as $item) {
            foreach (['update', 'delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
                expect($partner->can($ability, $item))->toBeFalse("{$ability} must be denied for a Partner");
            }
        }
    });

    it('denies a Partner without a client and a role-less user every ability and lets the Admin do everything', function (): void {
        $item = invoiceScopeSystem(fn () => InvoiceItem::query()->where('description', $this->itemTextA)->sole());

        foreach ([Canary::partnerFor(null), Canary::userWithoutRole($this->clientA)] as $user) {
            expect($user->can('viewAny', InvoiceItem::class))->toBeFalse()
                ->and($user->can('view', $item))->toBeFalse();
        }

        $admin = Canary::admin();

        expect($admin->can('viewAny', InvoiceItem::class))->toBeTrue()
            ->and($admin->can('view', $item))->toBeTrue()
            ->and($admin->can('update', $item))->toBeTrue();
    });

    it('computes the money of a line in the currency of the document', function (): void {
        $this->actingAs(Canary::admin());

        $item = InvoiceItem::query()->where('description', $this->itemTextA)->sole();

        expect($item->unitPrice())->toEqual(Money::ofMinor(5000, 'CZK'))
            ->and($item->lineTotal())->toEqual(Money::ofMinor(10000, 'CZK'))
            ->and($item->quantity())->toEqual(InvoiceQuantity::fromDecimal('2'))
            ->and($item->price_converted)->toBeFalse()
            ->and($item->invoice->id)->toBe($this->invoiceA->id);
    });

    it('lists the lines of a document by position through the relation', function (): void {
        $document = invoiceScopeDocument($this->clientA, InvoiceKind::Invoice, issued: true, items: ['Second text', 'First text']);

        $this->actingAs(Canary::admin());

        expect(Invoice::query()->findOrFail($document->id)->items->pluck('position')->all())->toBe([1, 2]);
    });

    it('makes neither the document, the amounts nor the origin fillable', function (): void {
        expect((new InvoiceItem)->getFillable())->toBe(['description', 'unit'])
            ->and(fn () => new InvoiceItem(['invoice_id' => $this->invoiceA->id]))->toThrow(MassAssignmentException::class)
            ->and(fn () => new InvoiceItem(['line_total_minor' => 1]))->toThrow(MassAssignmentException::class)
            ->and(fn () => new InvoiceItem(['price_converted' => true]))->toThrow(MassAssignmentException::class);
    });
});
