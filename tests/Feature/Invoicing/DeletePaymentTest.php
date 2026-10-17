<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\RecordPayment;
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
