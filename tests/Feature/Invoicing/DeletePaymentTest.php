<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The deletion of a mistaken payment together with its income (IN-05, IN-06, FI-03; D-13, D-15, D-16, D-17, D-19 as
 * amended on 2026-10-17, D-20; rulings R-41-1 to R-41-4). Every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 09:30:00', 'Europe/Prague'));
});

/**
 * An issued invoice of 12 500,00 Kc of a new client, issued on 2026-09-25 with the 14 day terms, so it falls due on
 * 2026-10-09. The clock is then moved to the given day and the Admin acts again.
 *
 * @return array{0: Invoice, 1: Client}
 */
function deleteIssued(string $today = '2026-10-14'): array
{
    $client = InvoiceFixtures::client();
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);

    test()->travelTo(CarbonImmutable::parse($today.' 09:30:00', 'Europe/Prague'));
    test()->actingAs(test()->admin);

    return [$invoice, $client];
}

/**
 * Records one payment of the document as the Admin.
 */
function deletePay(Invoice $document, string $amount, string $paidOn): InvoicePayment
{
    return app(RecordPayment::class)->handle(test()->admin, $document, ['amount' => $amount, 'paid_on' => $paidOn]);
}

/**
 * @return array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}
 */
function deleteStatus(Invoice $document): array
{
    $stored = app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->whereKey($document->id)->firstOrFail());

    return app(ReceivablesReader::class)->statuses([$stored], ReceivablesReader::today())[$document->id];
}

/**
 * The stored row of the document, every column.
 *
 * @return array<string, mixed>
 */
function deleteInvoiceRow(Invoice $document): array
{
    return (array) DB::table('invoices')->where('id', $document->id)->first();
}

/**
 * Attaches one fictional receipt to the transaction and returns its document and stored object path.
 *
 * @return array{0: Document, 1: string}
 */
function deleteReceipt(Transaction $income): array
{
    $document = array_values(app(AttachDocuments::class)->handle(test()->admin, $income, [DocumentFixtures::upload('receipt-example.txt')])->all())[0];
    $path = app(PartnerContext::class)->runAsSystem(
        static fn (): string => Media::query()->where('model_id', $document->id)->firstOrFail()->getPathRelativeToRoot(),
    );

    return [$document, $path];
}

/**
 * The deletion activity rows of a subject type, as the database holds them.
 *
 * @return list<Activity>
 */
function deleteActivities(string $subjectType): array
{
    return Activity::query()->where('subject_type', $subjectType)->where('event', 'deleted')->get()->all();
}

describe('the tracer: the Admin deletes the single full payment of an overdue invoice', function (): void {
    it('removes the payment, its income and its receipt together and leaves the invoice overdue again', function (): void {
        [$invoice] = deleteIssued();
        $payment = deletePay($invoice, '12 500,00', '2026-10-13');
        $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();
        [$receipt, $path] = deleteReceipt($income);
        $before = deleteInvoiceRow($invoice);

        expect(deleteStatus($invoice)['status'])->toBe(InvoiceStatus::Paid)
            ->and(Storage::disk('local')->exists($path))->toBeTrue();

        app(DeletePayment::class)->handle($this->admin, $payment);

        expect(InvoicePayment::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0)
            ->and(Document::query()->whereKey($receipt->id)->exists())->toBeFalse()
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Media::query()->where('model_id', $receipt->id)->count()))->toBe(0)
            ->and(Storage::disk('local')->exists($path))->toBeFalse();

        // D-13: nothing is written on the issued invoice, not even updated_at.
        expect(deleteInvoiceRow($invoice))->toBe($before);

        // D-20: the balance and the overdue state follow the remaining payments.
        $balance = app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id];
        $status = deleteStatus($invoice);

        expect($balance['remaining'])->toBe(1250000)
            ->and($status['status'])->toBe(InvoiceStatus::Overdue)
            ->and($status['overdue_days'])->toBe(5)
            ->and(app(ReceivablesReader::class)->tabCounts(ReceivablesReader::today())['overdue'])->toBe(1);

        // The pair check of the database finds no income without its payment and no payment without its income.
        DB::statement('SET CONSTRAINTS transactions_payment_income_pair_check IMMEDIATE');

        // The flag does not outlive the sanctioned statements, even inside this wrapping transaction.
        expect(DB::selectOne("select current_setting('kokpit.payment_deletion', true) as flag")->flag)->toBeIn([null, '']);
    });

    it('writes the deleted activity of the payment and of the income with allowlisted attributes and no note', function (): void {
        [$invoice] = deleteIssued();
        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '12 500,00', 'paid_on' => '2026-10-13', 'note' => 'Private payment remark']);

        app(DeletePayment::class)->handle($this->admin, $payment);

        $payments = deleteActivities('invoice_payment');
        $transactions = deleteActivities('transaction');
        $json = (string) json_encode([$payments[0]->attribute_changes ?? null, $transactions[0]->attribute_changes ?? null]);

        expect($payments)->toHaveCount(1)
            ->and($transactions)->toHaveCount(1)
            ->and($payments[0]->subject_id)->toBe($payment->id)
            ->and($payments[0]->causer_id)->toBe($this->admin->id)
            ->and($json)->not->toContain('Private payment remark')
            ->and($json)->not->toContain('"note"');
    });

    it('leaves a bare raw DELETE of another payment refused after the deletion', function (): void {
        [$invoice] = deleteIssued();
        $first = deletePay($invoice, '5 000,00', '2026-10-12');
        $second = deletePay($invoice, '7 500,00', '2026-10-13');

        app(DeletePayment::class)->handle($this->admin, $first);

        RawSql::expectSqlState('KP001', static fn () => DB::table('invoice_payments')->where('id', $second->id)->delete());

        expect(InvoicePayment::query()->whereKey($second->id)->exists())->toBeTrue();
    });

    it('refuses a Partner with an AuthorizationException and leaves the payment and its income', function (): void {
        [$invoice, $client] = deleteIssued();
        $payment = deletePay($invoice, '12 500,00', '2026-10-13');
        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(fn () => app(DeletePayment::class)->handle($partner, $payment))->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
            ->and(Transaction::query()->where('invoice_payment_id', $payment->id)->exists())->toBeTrue()
            ->and(deleteActivities('invoice_payment'))->toBe([]);
    });
});

/**
 * A paid issued proforma of 12 500,00 Kc with its payment, issued on 2026-09-25.
 *
 * @return array{0: Invoice, 1: InvoicePayment, 2: Client}
 */
function deletePaidProforma(): array
{
    $client = InvoiceFixtures::client();
    $proforma = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Advance', '1', '12500.00', 'pcs')], InvoiceKind::Proforma);

    test()->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    test()->actingAs(test()->admin);

    return [$proforma, deletePay($proforma, '12 500,00', '2026-10-13'), $client];
}

/**
 * The field errors of a ValidationException a callback raises, or null when it raises nothing.
 *
 * @return array<string, list<string>>|null
 */
function deleteErrors(Closure $callback): ?array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

describe('several payments, an overpayment and a proforma that was never converted', function (): void {
    it('derives partly paid and unpaid from the remaining payments and drops the income of each deleted payment (D-17, D-20)', function (): void {
        [$invoice] = deleteIssued('2026-10-01');
        $first = deletePay($invoice, '5 000,00', '2026-10-01');
        $second = deletePay($invoice, '7 500,00', '2026-10-01');

        expect(deleteStatus($invoice)['status'])->toBe(InvoiceStatus::Paid);

        app(DeletePayment::class)->handle($this->admin, $second);

        $status = deleteStatus($invoice);

        expect($status['status'])->toBe(InvoiceStatus::PartiallyPaid)
            ->and(app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining'])->toBe(750000)
            ->and(Transaction::query()->count())->toBe(1)
            ->and(Transaction::query()->where('invoice_payment_id', $first->id)->exists())->toBeTrue();

        app(DeletePayment::class)->handle($this->admin, $first);

        expect(deleteStatus($invoice)['status'])->toBe(InvoiceStatus::Unpaid)
            ->and(Transaction::query()->count())->toBe(0)
            ->and(InvoicePayment::query()->count())->toBe(0);
    });

    it('keeps an overpaid invoice paid when only the extra payment is deleted', function (): void {
        [$invoice] = deleteIssued('2026-10-01');
        deletePay($invoice, '12 500,00', '2026-10-01');
        $extra = deletePay($invoice, '2 000,00', '2026-10-01');

        app(DeletePayment::class)->handle($this->admin, $extra);

        expect(deleteStatus($invoice)['status'])->toBe(InvoiceStatus::Paid)
            ->and(InvoicePayment::query()->count())->toBe(1)
            ->and(Transaction::query()->count())->toBe(1);
    });

    it('deletes the payment of a proforma that was never converted without touching any transaction (D-18)', function (): void {
        [$proforma, $payment] = deletePaidProforma();

        expect(deleteStatus($proforma)['status'])->toBe(InvoiceStatus::AwaitingInvoice)
            ->and(app(ReceivablesReader::class)->tabCounts(ReceivablesReader::today())['awaiting_invoice'])->toBe(1);

        app(DeletePayment::class)->handle($this->admin, $payment);

        $status = deleteStatus($proforma);

        expect(Transaction::query()->count())->toBe(0)
            ->and(InvoicePayment::query()->count())->toBe(0)
            ->and($status['status'])->not->toBe(InvoiceStatus::AwaitingInvoice)
            ->and($status['status'])->toBeIn([InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
            ->and(app(ReceivablesReader::class)->tabCounts(ReceivablesReader::today())['awaiting_invoice'])->toBe(0);
    });
});

describe('the payments of a converted proforma (IN-06, D-15, R-41-2)', function (): void {
    it('refuses the proforma payment while its carried copy exists, in the Action and in the database', function (): void {
        [$proforma, $source] = deletePaidProforma();
        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);
        $copy = InvoicePayment::query()->where('invoice_id', $invoice->id)->sole();

        $errors = deleteErrors(fn () => app(DeletePayment::class)->handle($this->admin, $source));

        expect($errors)->toHaveKey('payment')
            ->and($errors['payment'] ?? null)->toBe([__('invoices.errors.payment_delete_carried', ['number' => (string) $invoice->number])])
            ->and(InvoicePayment::query()->count())->toBe(2)
            ->and(Transaction::query()->where('invoice_payment_id', $copy->id)->exists())->toBeTrue()
            ->and(deleteActivities('invoice_payment'))->toBe([]);

        RawSql::expectSqlState('23503', static function () use ($source): void {
            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$source->id]);
            DB::table('invoice_payments')->where('id', $source->id)->delete();
        });

        expect(InvoicePayment::query()->whereKey($source->id)->exists())->toBeTrue();
    });

    it('deletes the invoice-side copy with its income, leaves the proforma payment and then allows deleting it', function (): void {
        [$proforma, $source] = deletePaidProforma();
        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);
        $copy = InvoicePayment::query()->where('invoice_id', $invoice->id)->sole();

        app(DeletePayment::class)->handle($this->admin, $copy);

        $stored = InvoicePayment::query()->whereKey($source->id)->sole();

        expect(InvoicePayment::query()->where('invoice_id', $invoice->id)->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0)
            ->and($stored->amount_minor)->toBe($source->amount_minor)
            ->and($stored->paid_on->toDateString())->toBe('2026-10-13')
            ->and(deleteStatus($invoice)['status'])->toBe(InvoiceStatus::Unpaid)
            ->and(app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining'])->toBe(1250000);

        app(DeletePayment::class)->handle($this->admin, $source);

        expect(InvoicePayment::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0)
            ->and(deleteActivities('invoice_payment'))->toHaveCount(2);
    });

    it('keeps the proforma converted while its payment still stands after the copy was deleted', function (): void {
        [$proforma] = deletePaidProforma();
        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        app(DeletePayment::class)->handle($this->admin, InvoicePayment::query()->where('invoice_id', $invoice->id)->sole());

        expect(deleteStatus($proforma)['status'])->toBe(InvoiceStatus::Converted);
    });
});

describe('a cancelled invoice (D-16)', function (): void {
    it('keeps a mistaken payment correctable after the storno and leaves the credit note untouched', function (): void {
        [$invoice] = deleteIssued();
        $payment = deletePay($invoice, '12 500,00', '2026-10-13');
        $credit = InvoiceFixtures::stornoed($invoice);
        $this->actingAs($this->admin);
        $creditBefore = deleteInvoiceRow($credit);
        $invoiceBefore = deleteInvoiceRow($invoice);

        app(DeletePayment::class)->handle($this->admin, $payment);

        expect(InvoicePayment::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0)
            ->and(deleteInvoiceRow($credit))->toBe($creditBefore)
            ->and(deleteInvoiceRow($invoice))->toBe($invoiceBefore)
            ->and(deleteStatus($invoice)['status'])->toBe(InvoiceStatus::Cancelled)
            ->and(app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining'])->toBe(0);
    });
});

describe('all or nothing', function (): void {
    it('keeps the payment, its income, the receipt and its object and logs nothing when a step fails', function (): void {
        [$invoice] = deleteIssued();
        $payment = deletePay($invoice, '12 500,00', '2026-10-13');
        $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();
        [$receipt, $path] = deleteReceipt($income);

        InvoicePayment::deleting(static function (): void {
            throw new RuntimeException('Fictional refusal of the payment row.');
        });

        expect(fn () => app(DeletePayment::class)->handle($this->admin, $payment))->toThrow(RuntimeException::class, 'Fictional refusal of the payment row.');

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
            ->and(Transaction::query()->whereKey($income->id)->exists())->toBeTrue()
            ->and(Document::query()->whereKey($receipt->id)->exists())->toBeTrue()
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Media::query()->where('model_id', $receipt->id)->count()))->toBe(1)
            ->and(Storage::disk('local')->exists($path))->toBeTrue()
            ->and(deleteActivities('invoice_payment'))->toBe([])
            ->and(deleteActivities('transaction'))->toBe([]);

        // The flag did not outlive the failed attempt: a bare raw DELETE is still refused.
        RawSql::expectSqlState('KP001', static fn () => DB::table('invoice_payments')->where('id', $payment->id)->delete());
    });

    it('refuses the second of two deletions of one payment as already gone and changes nothing else', function (): void {
        [$invoice] = deleteIssued();
        $first = deletePay($invoice, '5 000,00', '2026-10-12');
        $other = deletePay($invoice, '7 500,00', '2026-10-13');
        $stale = InvoicePayment::query()->whereKey($first->id)->sole();

        app(DeletePayment::class)->handle($this->admin, $first);

        $errors = deleteErrors(fn () => app(DeletePayment::class)->handle($this->admin, $stale));

        expect($errors)->toBe(['payment' => [__('invoices.errors.payment_missing')]])
            ->and(InvoicePayment::query()->pluck('id')->all())->toBe([$other->id])
            ->and(Transaction::query()->count())->toBe(1)
            ->and(deleteActivities('invoice_payment'))->toHaveCount(1);
    });
});
