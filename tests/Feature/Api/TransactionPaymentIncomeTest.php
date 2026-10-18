<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiDocs;
use Tests\Support\ApiFixtures;
use Tests\Support\DocumentFixtures;
use Tests\Support\FinanceApiFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The Transactions API meets the same refusals as the finance screen for the income of an invoice payment (FI-03;
 * D-19 as amended on 2026-10-17, ruling R-19-1): the update and destroy endpoints call UpdateTransaction and
 * DeleteTransaction, so a locked field answers 422 with the Czech message, a delete answers 422 and nothing is
 * deleted, and only the date and the category change. Real Sanctum tokens; every name and amount is fictional.
 */

/**
 * A EUR invoice of 100,00 paid in full on 2026-10-10 through RecordPayment, which books the income. The web session
 * of the fixtures is dropped afterwards, so the requests below are authenticated by their token only.
 *
 * @return array{0: Invoice, 1: InvoicePayment, 2: Transaction}
 */
function apiPaymentIncome(): array
{
    FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.000');
    FinanceApiFixtures::cnbRate('EUR', '2026-10-10', '24.500');
    FinanceApiFixtures::cnbRate('EUR', '2026-10-12', '24.000');

    $admin = ApiFixtures::admin();
    $client = InvoiceFixtures::client(['currency' => 'EUR']);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);
    test()->actingAs($admin);

    $payment = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '100,00', 'paid_on' => '2026-10-10']);
    $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();

    Auth::forgetGuards();

    return [$invoice, $payment, $income];
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

describe('changing the income of a payment over the API', function (): void {
    beforeEach(function (): void {
        [$this->invoice, $this->payment, $this->income] = apiPaymentIncome();
        $this->token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $this->patch = fn (array $body) => $this->withToken($this->token)
            ->patchJson('/api/v1/transactions/'.$this->income->id, $body);
    });

    it('answers 422 with the locked message under the API field name and changes nothing', function (): void {
        $before = (array) DB::table('transactions')->where('id', $this->income->id)->first();
        $message = __('invoices.errors.payment_transaction_locked', ['number' => $this->invoice->number]);

        $amount = ($this->patch)(['amount_minor' => 99900])->assertUnprocessable();
        $description = ($this->patch)(['description' => 'Example edited text'])->assertUnprocessable();
        $figure = ($this->patch)(['czk_amount_minor' => 500000])->assertUnprocessable();

        expect($amount->json('errors.amount_minor'))->toBe([$message])
            ->and($description->json('errors.description'))->toBe([$message])
            ->and($figure->json('errors.czk_amount_minor'))->toBe([$message])
            ->and((array) DB::table('transactions')->where('id', $this->income->id)->first())->toBe($before);
    });

    it('answers 200 for a change of the date only, with the CZK figure of the CNB list of the new day', function (): void {
        $response = ($this->patch)(['transaction_date' => '2026-10-12'])->assertOk();

        expect($response->json('data.transaction_date'))->toBe('2026-10-12')
            ->and($response->json('data.amount_minor'))->toBe(10000)
            ->and($response->json('data.czk_minor'))->toBe(240000)
            ->and($response->json('data.czk_source'))->toBe('cnb');
    });

    it('answers 200 for a change of the category to another income category', function (): void {
        $category = FinanceApiFixtures::incomeCategory();

        $response = ($this->patch)(['category_id' => $category->id])->assertOk();

        expect($response->json('data.category_id'))->toBe($category->id);
    });
});

describe('deleting the income of a payment over the API', function (): void {
    beforeEach(function (): void {
        [$this->invoice, $this->payment, $this->income] = apiPaymentIncome();
        $this->token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
    });

    it('answers 422 with the delete message and deletes neither the transaction nor the payment', function (): void {
        $response = $this->withToken($this->token)
            ->deleteJson('/api/v1/transactions/'.$this->income->id)
            ->assertUnprocessable();

        expect($response->json('errors.transaction'))->toBe([__('invoices.errors.payment_transaction_delete', ['number' => $this->invoice->number])])
            ->and(DB::table('transactions')->where('id', $this->income->id)->exists())->toBeTrue()
            ->and(DB::table('invoice_payments')->where('id', $this->payment->id)->exists())->toBeTrue();
    });

    it('still answers 204 for an ordinary transaction', function (): void {
        $admin = ApiFixtures::admin();
        $ordinary = FinanceApiFixtures::transaction($admin);

        $this->withToken($this->token)->deleteJson('/api/v1/transactions/'.$ordinary->id)->assertNoContent();

        expect(DB::table('transactions')->where('id', $ordinary->id)->exists())->toBeFalse()
            ->and(DB::table('transactions')->where('id', $this->income->id)->exists())->toBeTrue();
    });
});

it('documents the 422 of the destroy endpoint and names the rule', function (): void {
    $delete = ApiDocs::operation(Route::getRoutes()->getByName('api.v1.transactions.destroy'));

    expect($delete)->not->toBeNull()
        ->and($delete['responses'] ?? [])->toHaveKeys([204, 404, 409, 422])
        ->and(json_encode($delete))->toContain('deleting the payment in the invoice detail');
});
