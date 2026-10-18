<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The system-callable write core of a payment (PA-06, PA-08; D-10, D-13, D-14): a card payment of an issued invoice
 * arrives from a queue job with no user and is booked exactly like a manual one, income included. Every name and
 * amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Records the payment the way a queue job does: no user, the system context, one transaction, the document locked.
 */
function recordAsJob(Invoice $invoice, PaymentFacts $facts, ?User $actor = null): InvoicePayment
{
    // A queue job has no session: whoever the fixtures logged in is gone.
    Auth::forgetGuards();

    return app(PartnerContext::class)->runAsSystem(static fn (): InvoicePayment => DB::transaction(static function () use ($invoice, $facts, $actor): InvoicePayment {
        $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

        return app(RecordPayment::class)->recordLocked($locked, $facts, $actor);
    }));
}

function cardFacts(string $amountMajor = '12500.00', string $currency = 'CZK', PaymentMethod $method = PaymentMethod::Card): PaymentFacts
{
    return new PaymentFacts(Money::fromMajor($amountMajor, $currency), '2026-10-14', $method, '2026-10-14', null, null);
}

it('lets a job without a user record a card payment of an issued invoice and books the income once', function (): void {
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $before = DB::table('invoices')->where('id', $invoice->id)->first();

    $payment = recordAsJob($invoice, cardFacts());
    $this->actingAs(Canary::admin());

    $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();
    $row = DB::table('invoice_payments')->where('id', $payment->id)->first();

    expect(InvoicePayment::query()->count())->toBe(1)
        ->and($row->method)->toBe('card')
        ->and($row->created_by)->toBeNull()
        ->and($payment->amount_minor)->toBe(1250000)
        ->and(Transaction::query()->count())->toBe(1)
        ->and($income->type)->toBe(TransactionType::Income)
        ->and($income->category_id)->toBe(TransactionCategory::invoicePayments()->id)
        ->and($income->transaction_date->toDateString())->toBe('2026-10-14')
        ->and($income->description)->toBe('Platba kartou faktury '.$invoice->number)
        ->and($income->invoice_id)->toBe($invoice->id)
        ->and($income->invoice_payment_id)->toBe($payment->id)
        ->and(app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining'])->toBe(0)
        ->and(DB::table('invoices')->where('id', $invoice->id)->first())->toEqual($before);

    $created = Activity::query()->where('subject_type', 'invoice_payment')->where('subject_id', $payment->id)->where('event', 'created')->sole();
    expect($created->causer_id)->toBeNull();
});
