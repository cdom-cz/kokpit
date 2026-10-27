<?php

declare(strict_types=1);

use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;

/*
 * The cash receipt model (Phase 15, D-13): a Partner reads the receipts of the own client only, for an issued document
 * and a client that is not archived; no Partner writes one; the payment behind a receipt is Admin-only and stays empty
 * for a Partner. Rows are written with forceFill in system runs, as the Actions will write them; every name, number
 * and amount is fictional.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function cashReceiptModelSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Writes an issued invoice of the client, a cash payment of it and the receipt of that payment.
 *
 * @return array{invoice: Invoice, payment: InvoicePayment, receipt: CashReceipt}
 */
function cashReceiptModelIssued(string $clientId, string $userId, string $purposeCanary): array
{
    return cashReceiptModelSystem(static function () use ($clientId, $userId, $purposeCanary): array {
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

        $payment = new InvoicePayment;
        $payment->forceFill([
            'invoice_id' => $invoice->id,
            'currency' => 'CZK',
            'amount_minor' => 4000,
            'paid_on' => '2026-10-20',
            'method' => PaymentMethod::Cash,
            'created_by' => $userId,
        ])->save();

        $receipt = new CashReceipt;
        $receipt->forceFill([
            'invoice_id' => $invoice->id,
            'client_id' => $clientId,
            'invoice_payment_id' => $payment->id,
            'number' => implode('', ['PPD', (string) random_int(100000, 999999)]),
            'document_number' => $invoice->number,
            'issued_on' => '2026-10-20',
            'received_on' => '2026-10-20',
            'amount_minor' => 4000,
            'currency' => 'CZK',
            'purpose' => $purposeCanary,
            'language' => 'cs',
            'supplier_snapshot' => ['name' => 'Example supplier'],
            'customer_snapshot' => ['name' => 'Example customer'],
            'appearance_snapshot' => ['accent' => '#000000'],
        ])->save();

        return ['invoice' => $invoice->refresh(), 'payment' => $payment->refresh(), 'receipt' => $receipt->refresh()];
    });
}

beforeEach(function (): void {
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->adminUser = Canary::admin();
    $this->canaryA = Canary::canary('receipt_a');
    $this->canaryB = Canary::canary('receipt_b');

    $this->a = cashReceiptModelIssued($this->clientA, $this->adminUser->id, $this->canaryA);
    $this->b = cashReceiptModelIssued($this->clientB, $this->adminUser->id, $this->canaryB);
});

describe('tracer: the receipt of a cash payment is read by the Partner of its client only', function (): void {
    it('casts the columns and answers the money and the state', function (): void {
        $this->actingAs($this->adminUser);

        $receipt = CashReceipt::query()->findOrFail($this->a['receipt']->id);

        expect($receipt->amount()->minor)->toBe(4000)
            ->and($receipt->amount()->currency)->toBe('CZK')
            ->and($receipt->lock_state)->toBe(CashReceiptLockState::Open)
            ->and($receipt->language)->toBe(InvoiceLanguage::Czech)
            ->and($receipt->issued_on->toDateString())->toBe('2026-10-20')
            ->and($receipt->received_on->toDateString())->toBe('2026-10-20')
            ->and($receipt->supplier_snapshot)->toBe(['name' => 'Example supplier'])
            ->and($receipt->document_number)->toBe($this->a['invoice']->number)
            ->and($receipt->isLocked())->toBeFalse()
            ->and($receipt->isCancelled())->toBeFalse()
            ->and($receipt->cancelled_at)->toBeNull();
    });

    it('lets the Partner of the client read the own receipt through the scope and the policy', function (): void {
        $partner = Canary::partnerFor($this->clientA);
        $this->actingAs($partner);

        $receipts = CashReceipt::query()->get();

        expect($receipts->pluck('id')->all())->toBe([$this->a['receipt']->id])
            ->and(Gate::forUser($partner)->allows('viewAny', CashReceipt::class))->toBeTrue()
            ->and(Gate::forUser($partner)->allows('view', $receipts->first()))->toBeTrue();
    });

    it('denies the Partner every write: update, delete and create', function (): void {
        $partner = Canary::partnerFor($this->clientA);
        $this->actingAs($partner);

        $receipt = CashReceipt::query()->findOrFail($this->a['receipt']->id);

        expect(Gate::forUser($partner)->denies('update', $receipt))->toBeTrue()
            ->and(Gate::forUser($partner)->denies('delete', $receipt))->toBeTrue()
            ->and(Gate::forUser($partner)->denies('create', CashReceipt::class))->toBeTrue();
    });

    it('gives the Partner no payment through the receipt', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $receipt = CashReceipt::query()->findOrFail($this->a['receipt']->id);

        expect($receipt->payment)->toBeNull()
            ->and($receipt->invoice_payment_id)->toBe($this->a['payment']->id);
    });

    it('lets the Admin read the payment through the receipt', function (): void {
        $this->actingAs($this->adminUser);

        $receipt = CashReceipt::query()->findOrFail($this->a['receipt']->id);

        expect($receipt->payment?->id)->toBe($this->a['payment']->id)
            ->and($receipt->invoice->id)->toBe($this->a['invoice']->id)
            ->and($receipt->pdfDocument)->toBeNull();
    });

    it('shows the Partner of another client none of the receipts of the first client', function (): void {
        $partnerB = Canary::partnerFor($this->clientB);
        $this->actingAs($partnerB);

        expect(CashReceipt::query()->pluck('id')->all())->toBe([$this->b['receipt']->id])
            ->and(CashReceipt::query()->find($this->a['receipt']->id))->toBeNull()
            ->and(Gate::forUser($partnerB)->denies('view', $this->a['receipt']))->toBeTrue();
    });

    it('hides the receipts of an archived client and lets the Admin read every receipt', function (): void {
        cashReceiptModelSystem(fn () => Client::query()->whereKey($this->clientA)->firstOrFail()->delete());

        $this->actingAs(Canary::partnerFor($this->clientA));
        expect(CashReceipt::query()->count())->toBe(0);

        $this->actingAs($this->adminUser);
        expect(CashReceipt::query()->pluck('id')->all())->toEqualCanonicalizing([$this->a['receipt']->id, $this->b['receipt']->id]);
    });

    it('keeps a cancelled receipt visible to the Partner, with no reason on the row', function (): void {
        cashReceiptModelSystem(function (): void {
            $receipt = CashReceipt::query()->findOrFail($this->a['receipt']->id);
            $receipt->forceFill(['cancelled_at' => now()])->save();
        });

        $this->actingAs(Canary::partnerFor($this->clientA));

        $receipt = CashReceipt::query()->findOrFail($this->a['receipt']->id);

        expect($receipt->isCancelled())->toBeTrue()
            ->and(array_keys($receipt->getAttributes()))->not->toContain('cancel_reason', 'unlock_reason', 'cancelled_by');
    });

    it('resolves the morph alias of the receipt', function (): void {
        expect(Relation::getMorphedModel('cash_receipt'))->toBe(CashReceipt::class);
    });

    it('logs nothing through model events and has no mass-assignable column', function (): void {
        expect(class_uses_recursive(CashReceipt::class))->not->toContain(LogsAllowlistedActivity::class)
            ->and((new CashReceipt)->getFillable())->toBe([]);
    });
});
