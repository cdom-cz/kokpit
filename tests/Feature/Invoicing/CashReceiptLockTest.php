<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UnlockCashReceipt;
use App\Domain\Invoicing\Actions\UpdateCashReceipt;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Invoicing\Pdf\CashReceiptPdf;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;

/*
 * The lock of a cash receipt (Phase 15; D-10, D-11; P15-07, P15-08): editable while open with the stored PDF following
 * the edit, locked by the successful send of the payment confirmation, opened again only by the Admin with a reason
 * that is written to the Admin-only audit log. Every name, address and amount is fictional.
 */

beforeEach(function (): void {
    Queue::fake();
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    Mail::getSymfonyTransport()->flush();
});

/**
 * Records a cash payment of 1 500 CZK with the queue held back, on a new issued invoice of a client with an invoice
 * e-mail address, and returns the receipt of the payment and the row of its confirmation e-mail.
 *
 * @return array{0: CashReceipt, 1: PaymentConfirmation}
 */
function cashReceiptLockPay(): array
{
    $client = InvoiceFixtures::client(['name' => 'Example Client s.r.o.', 'invoice_email' => exampleEmail('client-invoice')]);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);

    $payment = app(RecordPayment::class)->handle(test()->admin, $invoice, ['amount' => '1500', 'paid_on' => '2026-10-14', 'method' => 'cash']);

    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();
    $confirmation = app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmation => PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->sole());

    return [$receipt, $confirmation];
}

/**
 * One try of the sending job through its own middleware: the successful send locks the receipt.
 */
function cashReceiptLockSend(PaymentConfirmation $confirmation): void
{
    $job = new SendPaymentConfirmation($confirmation->id);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (SendPaymentConfirmation $job): mixed => app()->call($job->handle(...)));
}

function cashReceiptLockReceipt(CashReceipt $receipt): CashReceipt
{
    return CashReceipt::query()->findOrFail($receipt->id);
}

/**
 * The rows of an event of a receipt in the activity log.
 *
 * @return list<Activity>
 */
function cashReceiptLockRows(CashReceipt $receipt, string $event): array
{
    return Activity::query()
        ->where('subject_type', 'cash_receipt')
        ->where('subject_id', $receipt->id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->get()
        ->all();
}

/**
 * The bytes of the stored PDF of a receipt, read from the media disk.
 */
function cashReceiptLockStoredBytes(CashReceipt $receipt): string
{
    $media = Document::query()->findOrFail(cashReceiptLockReceipt($receipt)->pdf_document_id)->getFirstMedia(Document::MEDIA_COLLECTION);

    return (string) Storage::disk($media?->disk)->get((string) $media?->getPathRelativeToRoot());
}

/**
 * The error messages of a refused call, by key.
 *
 * @param  callable(): mixed  $call
 * @return array<string, list<string>>
 */
function cashReceiptLockErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

describe('the edit of an open receipt (D-10, D-11)', function (): void {
    it('saves the date, the purpose and the customer line, replaces the stored PDF and audits the change', function (): void {
        [$receipt] = cashReceiptLockPay();
        $oldDocument = (string) $receipt->pdf_document_id;

        $updated = app(UpdateCashReceipt::class)->handle($this->admin, $receipt, [
            'received_on' => '2026-10-13',
            'purpose' => 'Úhrada v hotovosti na pobočce',
            'customer' => ['name' => 'Example Buyer s.r.o.'],
        ]);

        $row = cashReceiptLockReceipt($receipt);

        expect($updated->received_on->format('Y-m-d'))->toBe('2026-10-13')
            ->and($row->received_on->format('Y-m-d'))->toBe('2026-10-13')
            ->and($row->purpose)->toBe('Úhrada v hotovosti na pobočce')
            ->and($row->customer_snapshot['name'] ?? null)->toBe('Example Buyer s.r.o.')
            ->and($row->number)->toBe($receipt->number)
            ->and($row->amount_minor)->toBe(150000)
            ->and($row->currency)->toBe('CZK')
            ->and($row->lock_state)->toBe(CashReceiptLockState::Open)
            ->and($row->pdf_document_id)->not->toBeNull()
            ->and($row->pdf_document_id)->not->toBe($oldDocument)
            ->and(Document::query()->whereKey($oldDocument)->exists())->toBeFalse()
            ->and(Document::query()->where('owner_type', 'cash_receipt')->where('owner_id', $receipt->id)->count())->toBe(1);

        $html = app(CashReceiptPdf::class)->html($row);

        expect($html)->toContain('Úhrada v hotovosti na pobočce')
            ->and($html)->toContain('Example Buyer s.r.o.');

        $text = PdfText::flat(cashReceiptLockStoredBytes($receipt));

        if ($text !== null) {
            expect($text)->toContain('Úhrada v hotovosti na pobočce')
                ->and($text)->toContain('Example Buyer s.r.o.');
        }

        $rows = cashReceiptLockRows($receipt, 'cash_receipt_edited');

        expect($rows)->toHaveCount(1);

        $changes = $rows[0]->attribute_changes;

        expect($changes?->get('attributes'))->toMatchArray([
            'received_on' => '2026-10-13',
            'purpose' => 'Úhrada v hotovosti na pobočce',
            'customer.name' => 'Example Buyer s.r.o.',
        ])->and($changes?->get('old'))->toMatchArray([
            'received_on' => '2026-10-14',
            'customer.name' => 'Example Client s.r.o.',
        ]);
    });

    it('refuses the same edit once the confirmation send has locked the receipt', function (): void {
        [$receipt, $confirmation] = cashReceiptLockPay();

        cashReceiptLockSend($confirmation);

        expect(cashReceiptLockReceipt($receipt)->lock_state)->toBe(CashReceiptLockState::Locked);

        $errors = cashReceiptLockErrors(fn () => app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['purpose' => 'Úhrada v hotovosti na pobočce']));

        expect($errors)->toHaveKey('receipt')
            ->and($errors['receipt'][0])->toBe(__('cash_receipts.errors.locked'))
            ->and(cashReceiptLockReceipt($receipt)->purpose)->toBe($receipt->purpose)
            ->and(cashReceiptLockRows($receipt, 'cash_receipt_edited'))->toBe([]);
    });
});

describe('the Admin unlock (D-10)', function (): void {
    it('refuses a reason shorter than ten characters and leaves the receipt locked', function (): void {
        [$receipt, $confirmation] = cashReceiptLockPay();
        cashReceiptLockSend($confirmation);

        $errors = cashReceiptLockErrors(fn () => app(UnlockCashReceipt::class)->handle($this->admin, $receipt, '123456789'));

        expect($errors)->toHaveKey('reason')
            ->and(cashReceiptLockReceipt($receipt)->lock_state)->toBe(CashReceiptLockState::Locked)
            ->and(cashReceiptLockRows($receipt, 'cash_receipt_unlocked'))->toBe([]);
    });

    it('refuses a reason longer than 500 characters', function (): void {
        [$receipt, $confirmation] = cashReceiptLockPay();
        cashReceiptLockSend($confirmation);

        $errors = cashReceiptLockErrors(fn () => app(UnlockCashReceipt::class)->handle($this->admin, $receipt, str_repeat('x', 501)));

        expect($errors)->toHaveKey('reason')
            ->and(cashReceiptLockReceipt($receipt)->lock_state)->toBe(CashReceiptLockState::Locked);
    });

    it('opens a locked receipt with a reason, records it, changes nothing else, and then the edit succeeds', function (): void {
        [$receipt, $confirmation] = cashReceiptLockPay();
        cashReceiptLockSend($confirmation);
        $locked = cashReceiptLockReceipt($receipt);

        $opened = app(UnlockCashReceipt::class)->handle($this->admin, $receipt, '  Oprava jména plátce po odeslání  ');

        $row = cashReceiptLockReceipt($receipt);

        expect($opened->lock_state)->toBe(CashReceiptLockState::Open)
            ->and($row->lock_state)->toBe(CashReceiptLockState::Open)
            ->and($row->purpose)->toBe($locked->purpose)
            ->and($row->received_on->format('Y-m-d'))->toBe($locked->received_on->format('Y-m-d'))
            ->and($row->customer_snapshot)->toBe($locked->customer_snapshot)
            ->and($row->pdf_document_id)->toBe($locked->pdf_document_id)
            ->and(DB::scalar("select current_setting('kokpit.cash_receipt_unlock', true)"))->toBeIn([null, '']);

        $rows = cashReceiptLockRows($receipt, 'cash_receipt_unlocked');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->causer_id)->toBe($this->admin->id)
            ->and($rows[0]->attribute_changes?->get('attributes'))->toMatchArray(['lock_state' => 'open', 'unlock_reason' => 'Oprava jména plátce po odeslání']);

        $edited = app(UpdateCashReceipt::class)->handle($this->admin, $receipt, ['customer' => ['name' => 'Example Buyer s.r.o.']]);

        expect($edited->customer_snapshot['name'] ?? null)->toBe('Example Buyer s.r.o.')
            ->and(cashReceiptLockRows($receipt, 'cash_receipt_edited'))->toHaveCount(1);
    });

    it('refuses to unlock a receipt that is not locked', function (): void {
        [$receipt] = cashReceiptLockPay();

        $errors = cashReceiptLockErrors(fn () => app(UnlockCashReceipt::class)->handle($this->admin, $receipt, 'Oprava jména plátce po odeslání'));

        expect($errors)->toHaveKey('receipt')
            ->and($errors['receipt'][0])->toBe(__('cash_receipts.errors.not_locked'))
            ->and(cashReceiptLockRows($receipt, 'cash_receipt_unlocked'))->toBe([]);
    });

    it('refuses a Partner the edit and the unlock', function (): void {
        [$receipt, $confirmation] = cashReceiptLockPay();
        cashReceiptLockSend($confirmation);
        $partner = Canary::partnerFor($receipt->client_id);

        expect($partner)->toBeInstanceOf(User::class)
            ->and(fn () => app(UnlockCashReceipt::class)->handle($partner, $receipt, 'Oprava jména plátce po odeslání'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateCashReceipt::class)->handle($partner, $receipt, ['purpose' => 'Cizí úprava']))->toThrow(AuthorizationException::class)
            ->and(cashReceiptLockReceipt($receipt)->lock_state)->toBe(CashReceiptLockState::Locked);
    });
});
