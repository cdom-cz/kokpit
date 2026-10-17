<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Actions\DeleteTransaction;
use App\Domain\Finance\Actions\UpdateTransaction;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\FinanceApiFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The income of an invoice payment changes only in its date and category on the finance side and is never deleted
 * there (FI-03; D-19 as amended on 2026-10-17, ruling R-19-1, Phase 9 D-14). The Actions refuse in Czech before anything
 * is touched and the database guard of plan 10-06 refuses the same for every other writer. Every name and amount is
 * fictional.
 */

/**
 * An issued invoice of a new client paid in full through RecordPayment, which books the income.
 *
 * @return array{0: User, 1: Invoice, 2: InvoicePayment, 3: Transaction, 4: Client}
 */
function lockIncome(string $currency = 'CZK'): array
{
    $foreign = $currency !== 'CZK';
    $amount = $foreign ? '100.00' : '12500.00';

    if ($foreign) {
        InvoiceFixtures::cnbRate($currency, '2026-10-14', '25.000');
        InvoiceFixtures::cnbRate($currency, '2026-10-10', '24.500');
        InvoiceFixtures::cnbRate($currency, '2026-10-12', '24.000');
    }

    $admin = Canary::admin();
    $client = InvoiceFixtures::client(['currency' => $currency]);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', $amount)]);
    test()->actingAs($admin);

    $payment = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => $foreign ? '100,00' : '12 500,00', 'paid_on' => '2026-10-10']);
    $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();

    return [$admin, $invoice, $payment, $income, $client];
}

/**
 * The field errors of a ValidationException a callback raises, or null when it raises nothing.
 *
 * @return array<string, list<string>>|null
 */
function lockErrors(Closure $callback): ?array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

/**
 * The stored columns of a transaction as the database holds them, for "nothing changed" comparisons.
 *
 * @return array<string, mixed>
 */
function lockRow(Transaction $transaction): array
{
    return (array) DB::table('transactions')->where('id', $transaction->id)->first();
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

describe('changing a payment income', function (): void {
    it('refuses each locked field with the Czech message naming the invoice, under that key, and changes nothing', function (): void {
        [$admin, $invoice, , $income, $client] = lockIncome('EUR');
        $other = InvoiceFixtures::client();
        $project = ApiFixtures::project($client);
        $before = lockRow($income);
        $message = __('invoices.errors.payment_transaction_locked', ['number' => $invoice->number]);

        $attempts = [
            'amount' => ['amount' => '999,00'],
            'currency' => ['currency' => 'CZK'],
            'type' => ['type' => 'expense'],
            'client_id' => ['client_id' => $other->id],
            'project_id' => ['project_id' => $project->id],
            'description' => ['description' => 'Example edited text'],
            'counterparty' => ['counterparty' => 'Example other party'],
            'document_number' => ['document_number' => 'EX-1'],
            'invoice_id' => ['invoice_id' => '0199e3b0-1a2b-7c3d-8e4f-a1b2c3d4e5f6'],
            'czk_amount' => ['czk_amount' => '5000,00'],
            'czk_manual' => ['czk_manual' => true],
            'exchange_rate' => ['exchange_rate' => '26'],
            'exchange_unit_amount' => ['exchange_unit_amount' => 1, 'exchange_rate' => '26'],
        ];

        foreach ($attempts as $key => $data) {
            $errors = lockErrors(fn () => app(UpdateTransaction::class)->handle($admin, $income, $data));

            expect($errors)->not->toBeNull($key)
                ->and($errors[$key] ?? null)->toBe([$message], $key)
                ->and(lockRow($income))->toBe($before, $key);
        }

        expect(strip_tags($message))->toContain((string) $invoice->number)
            ->and($message)->toContain('v detailu faktury');
    });

    it('lets a form resend the unchanged values and changes only the date, the CZK figure following the CNB list of the new day', function (): void {
        [$admin, , , $income] = lockIncome('EUR');

        expect($income->czk_minor)->toBe(245000);

        $changed = app(UpdateTransaction::class)->handle($admin, $income, [
            'type' => 'income',
            'transaction_date' => '2026-10-12',
            'category_id' => $income->category_id,
            'amount' => '100,00',
            'currency' => 'EUR',
            'description' => $income->description,
            'counterparty' => $income->counterparty,
            'document_number' => $income->document_number,
            'client_id' => $income->client_id,
            'czk_manual' => false,
            'czk_amount' => '',
        ]);

        expect($changed->transaction_date->toDateString())->toBe('2026-10-12')
            ->and($changed->czk_minor)->toBe(240000)
            ->and($changed->czk_source)->toBe(CzkSource::Cnb)
            ->and($changed->amount_minor)->toBe($income->amount_minor)
            ->and($changed->invoice_payment_id)->toBe($income->invoice_payment_id);
    });

    it('lets the category change to another income category and refuses an expense category', function (): void {
        [$admin, , , $income] = lockIncome();
        $incomeCategory = FinanceApiFixtures::incomeCategory();

        $changed = app(UpdateTransaction::class)->handle($admin, $income, ['category_id' => $incomeCategory->id]);

        expect($changed->category_id)->toBe($incomeCategory->id)
            ->and($changed->category_id)->not->toBe(TransactionCategory::invoicePayments()->id);

        $errors = lockErrors(fn () => app(UpdateTransaction::class)->handle($admin, $changed, ['category_id' => FinanceApiFixtures::softwareCategory()->id]));

        expect($errors)->toHaveKey('category_id')
            ->and($errors['category_id'] ?? null)->toBe([__('finance.transactions.errors.category_type_mismatch')])
            ->and(Transaction::query()->whereKey($income->id)->value('category_id'))->toBe($incomeCategory->id);
    });

    it('still edits an ordinary transaction freely', function (): void {
        $admin = Canary::admin();
        $ordinary = FinanceApiFixtures::transaction($admin);
        $this->actingAs($admin);

        $changed = app(UpdateTransaction::class)->handle($admin, $ordinary, ['amount' => '99,00', 'description' => 'Example corrected']);

        expect($changed->amount_minor)->toBe(9900)
            ->and($changed->description)->toBe('Example corrected');
    });

    it('refuses a Partner', function (): void {
        [, , , $income, $client] = lockIncome();
        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(fn () => app(UpdateTransaction::class)->handle($partner, $income, ['description' => 'Example edited text']))
            ->toThrow(AuthorizationException::class);
    });
});

describe('deleting a payment income', function (): void {
    it('is refused with the Czech message naming the invoice, before the receipt or anything else is touched', function (): void {
        [$admin, $invoice, $payment, $income] = lockIncome();
        app(AttachDocuments::class)->handle($admin, $income, [DocumentFixtures::upload('receipt-example-1.txt')]);
        $files = Storage::disk('local')->allFiles();
        $receipts = DB::table('documents')->count();

        $errors = lockErrors(fn () => app(DeleteTransaction::class)->handle($admin, $income));

        expect($errors)->toBe(['transaction' => [__('invoices.errors.payment_transaction_delete', ['number' => $invoice->number])]])
            ->and($errors['transaction'][0] ?? '')->toContain((string) $invoice->number)
            ->and(Transaction::query()->whereKey($income->id)->exists())->toBeTrue()
            ->and(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
            ->and(DB::table('documents')->count())->toBe($receipts)
            ->and($receipts)->toBe(1)
            ->and(Document::query()->where('owner_id', $income->id)->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toBe($files);
    });

    it('still deletes an ordinary transaction with its receipt', function (): void {
        $admin = Canary::admin();
        $ordinary = FinanceApiFixtures::transaction($admin);
        $this->actingAs($admin);
        app(AttachDocuments::class)->handle($admin, $ordinary, [DocumentFixtures::upload('receipt-example-2.txt')]);

        app(DeleteTransaction::class)->handle($admin, $ordinary);

        expect(Transaction::query()->whereKey($ordinary->id)->exists())->toBeFalse()
            ->and(DB::table('documents')->count())->toBe(0);
    });

    it('refuses a Partner', function (): void {
        [, , , $income, $client] = lockIncome();
        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(fn () => app(DeleteTransaction::class)->handle($partner, $income))->toThrow(AuthorizationException::class);
    });
});

describe('the database guard behind the Actions', function (): void {
    it('refuses raw SQL that changes a locked column or deletes the income (KP001)', function (): void {
        [, , , $income] = lockIncome();

        RawSql::expectSqlState('KP001', fn () => DB::table('transactions')->where('id', $income->id)->update(['amount_minor' => 1]));
        RawSql::expectSqlState('KP001', fn () => DB::table('transactions')->where('id', $income->id)->update(['description' => 'Example raw text']));
        RawSql::expectSqlState('KP001', fn () => DB::table('transactions')->where('id', $income->id)->update(['invoice_id' => null, 'invoice_payment_id' => null]));
        RawSql::expectSqlState('KP001', fn () => DB::table('transactions')->where('id', $income->id)->delete());
        RawSql::expectAllowed(fn () => DB::table('transactions')->where('id', $income->id)->update(['transaction_date' => '2026-10-11']));

        expect(Transaction::query()->whereKey($income->id)->value('amount_minor'))->toBe($income->amount_minor);
    });

    it('lets neither half of a payment and its income disappear alone (R-06-1, R-06-7)', function (): void {
        [, , $payment, $income] = lockIncome();

        // The payment without the deletion flag: refused by its own guard.
        RawSql::expectSqlState('KP001', fn () => DB::table('invoice_payments')->where('id', $payment->id)->delete());

        // With the flag but while its income still exists: refused by the foreign key.
        RawSql::expectSqlState('23503', function () use ($payment): void {
            DB::select("SELECT set_config('kokpit.payment_deletion', ?, true)", [$payment->id]);
            DB::table('invoice_payments')->where('id', $payment->id)->delete();
        });

        // The flagged deletion of the income alone cannot commit: the pair rule raises when the payment survives.
        RawSql::expectSqlState('KP001', function () use ($payment, $income): void {
            DB::select("SELECT set_config('kokpit.payment_deletion', ?, true)", [$payment->id]);
            DB::statement('SET CONSTRAINTS transactions_payment_income_pair_check IMMEDIATE');
            DB::table('transactions')->where('id', $income->id)->delete();
        });

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
            ->and(Transaction::query()->whereKey($income->id)->exists())->toBeTrue();
    });
});
