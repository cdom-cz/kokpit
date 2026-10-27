<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\CashReceiptPdfUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use App\Filament\Partner\Resources\PartnerInvoiceResource\RelationManagers\PartnerCashReceiptsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The Partner's read-only view of cash receipts and their one download route (Phase 15; D-13, AS-4; P15-11, P15-12):
 * the receipts of an own issued document are listed on its detail and downloaded through a signed, short-lived,
 * user-bound route; another client's receipt, an unknown or malformed id and a receipt without a file are all 404. The
 * cancel reason, the payment row and the lock state never reach the Partner. Every name, number and amount is fictional.
 */

beforeEach(function (): void {
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));

    $this->admin = Canary::admin();
    $this->clientA = InvoiceFixtures::client();
    $this->clientB = InvoiceFixtures::client();
    $this->partnerA = Canary::partnerFor($this->clientA->id);
    $this->partnerB = Canary::partnerFor($this->clientB->id);
});

/**
 * Pays 1 500 CZK in cash on a new issued invoice of the client, as the Admin through the write core; the payment
 * issues the receipt and its PDF. A note is written on the payment, so a test can prove that it never leaks.
 *
 * @return array{0: Invoice, 1: CashReceipt}
 */
function cashReceiptPartnerPay(object $client, string $note = 'Example payment note'): array
{
    $admin = Canary::admin();
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    test()->actingAs($admin);

    $payment = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '1500', 'paid_on' => '2026-10-14', 'method' => 'cash', 'note' => $note]);
    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();

    return [$invoice->refresh(), $receipt];
}

/**
 * A signed link for any receipt id, as the application would sign it for the given user.
 */
function cashReceiptPartnerLink(string $receiptId, string $userId): string
{
    return URL::temporarySignedRoute(CashReceiptPdfUrl::ROUTE, now()->addSeconds((int) config('kokpit.documents.signed_url_ttl_seconds')), [
        'cashReceipt' => $receiptId,
        'u' => $userId,
    ]);
}

/**
 * The bytes stored for the PDF of a receipt, read as system.
 */
function cashReceiptPartnerBytes(CashReceipt $receipt): string
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($receipt): string {
        $document = Document::query()->findOrFail($receipt->pdf_document_id);
        $media = $document->getFirstMedia(Document::MEDIA_COLLECTION);

        return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    });
}

describe('the list on the detail of an own document', function (): void {
    it('shows the Partner one row with number, date received, amount and the valid badge, and nothing else', function (): void {
        [$invoice, $receipt] = cashReceiptPartnerPay($this->clientA);

        $this->actingAs($this->partnerA);

        $component = Livewire::test(PartnerCashReceiptsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewPartnerInvoice::class])
            ->assertCanSeeTableRecords([$receipt])
            ->assertCountTableRecords(1)
            ->assertTableColumnStateSet('number', $receipt->number, $receipt)
            ->assertTableColumnFormattedStateSet('received_on', '14. 10. 2026', $receipt)
            ->assertTableColumnFormattedStateSet('amount', $receipt->amount()->format('cs'), $receipt)
            ->assertTableColumnFormattedStateSet('state', 'Platný', $receipt);

        $table = $component->instance()->getTable();

        expect(array_keys($table->getColumns()))->toBe(['number', 'received_on', 'amount', 'state'])
            ->and($table->getFilters())->toBe([])
            ->and($table->getBulkActions())->toBe([])
            ->and($table->getHeaderActions())->toBe([])
            ->and(array_keys($table->getFlatActions()))->toBe(['download'])
            ->and($table->isSearchable())->toBeFalse();
    });

    it('marks a cancelled receipt as cancelled and keeps it listed', function (): void {
        [$invoice, $receipt] = cashReceiptPartnerPay($this->clientA);

        app(PartnerContext::class)->runAsSystem(static function () use ($receipt): void {
            CashReceipt::query()->findOrFail($receipt->id)->forceFill(['cancelled_at' => CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Prague')])->save();
        });

        $this->actingAs($this->partnerA);

        Livewire::test(PartnerCashReceiptsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewPartnerInvoice::class])
            ->assertCountTableRecords(1)
            ->assertTableColumnFormattedStateSet('state', 'Zrušen', $receipt->refresh());
    });

    it('renders on the detail page without the payment note, the cancel reason or the lock state', function (): void {
        [$invoice, $receipt] = cashReceiptPartnerPay($this->clientA, 'Example internal payment note');

        app(PartnerContext::class)->runAsSystem(static function () use ($receipt): void {
            CashReceipt::query()->findOrFail($receipt->id)->forceFill(['cancelled_at' => CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Prague')])->save();
        });

        $this->actingAs($this->partnerA);

        $body = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $invoice]))->assertOk()->getContent();

        expect($body)->toContain('Příjmové doklady')
            ->and($body)->toContain($receipt->number)
            ->and($body)->toContain('Zrušen')
            ->and($body)->not->toContain('Example internal payment note')
            ->and(mb_strtolower($body))->not->toContain('lock')
            ->and(mb_strtolower($body))->not->toContain('uzamčen');
    });

    it('lists no receipt of another client and does not offer the tab for it', function (): void {
        [$invoiceB, $receiptB] = cashReceiptPartnerPay($this->clientB);

        $this->actingAs($this->partnerA);

        expect(Invoice::query()->whereKey($invoiceB->id)->exists())->toBeFalse()
            ->and(CashReceipt::query()->whereKey($receiptB->id)->exists())->toBeFalse();
    });

    it('registers the relation manager as the only relation of the Partner invoice resource', function (): void {
        expect(PartnerInvoiceResource::getRelations())->toBe([PartnerCashReceiptsRelationManager::class]);
    });

    it('hands the Partner the signed receipt link at click time and the link returns the PDF', function (): void {
        [$invoice, $receipt] = cashReceiptPartnerPay($this->clientA);

        $this->actingAs($this->partnerA);

        $component = Livewire::test(PartnerCashReceiptsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewPartnerInvoice::class])
            ->callAction(TestAction::make('download')->table($receipt));

        $target = $component->effects['redirect'] ?? null;

        expect($target)->toBeString()
            ->and(parse_url($target, PHP_URL_PATH))->toBe(parse_url(CashReceiptPdfUrl::download($receipt, $this->partnerA), PHP_URL_PATH));

        $this->get($target)->assertOk()->assertHeader('content-type', 'application/pdf');
    });
});

describe('the download route', function (): void {
    it('streams the stored PDF of the own receipt as a private, non-sniffable attachment', function (): void {
        [, $receipt] = cashReceiptPartnerPay($this->clientA);
        $bytes = cashReceiptPartnerBytes($receipt);

        $this->actingAs($this->partnerA);

        $url = CashReceiptPdfUrl::download($receipt, $this->partnerA);

        expect(parse_url($url, PHP_URL_PATH))->toStartWith('/admin/files/cash-receipts/'.$receipt->id);

        $response = $this->get($url)->assertOk();

        expect($bytes)->not->toBe('')
            ->and($response->streamedContent())->toBe($bytes)
            ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($bytes))
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    });

    it('streams any receipt to the Admin', function (): void {
        [, $receipt] = cashReceiptPartnerPay($this->clientA);

        $this->actingAs($this->admin);

        $this->get(CashReceiptPdfUrl::download($receipt, $this->admin))->assertOk();
    });

    it('answers a Partner of another client 404, never 403 and never the id', function (): void {
        [, $receipt] = cashReceiptPartnerPay($this->clientA);

        $this->actingAs($this->partnerB);

        $response = $this->get(CashReceiptPdfUrl::download($receipt, $this->partnerB))->assertNotFound();

        expect(str_contains((string) $response->getContent(), (string) $receipt->id))->toBeFalse();
    });

    it('refuses a link signed for another user, an unsigned and a tampered link with 403', function (): void {
        [, $receipt] = cashReceiptPartnerPay($this->clientA);

        $this->actingAs($this->partnerB);

        $unsigned = route(CashReceiptPdfUrl::ROUTE, ['cashReceipt' => $receipt->id, 'u' => $this->partnerB->getKey()]);
        $signed = CashReceiptPdfUrl::download($receipt, $this->partnerB);
        $tampered = substr($signed, 0, -1).(str_ends_with($signed, 'a') ? 'b' : 'a');
        $foreign = CashReceiptPdfUrl::download($receipt, $this->partnerA);

        foreach ([$unsigned, $tampered, $foreign] as $link) {
            expect($this->get($link)->getStatusCode())->toBe(403, $link);
        }
    });

    it('answers 404 for a malformed and for an unknown id, to the Partner and the Admin', function (): void {
        foreach ([$this->partnerA, $this->admin] as $user) {
            $this->actingAs($user);

            foreach (['not-a-uuid', (string) Str::uuid7()] as $id) {
                $this->get(cashReceiptPartnerLink($id, $user->getKey()))->assertNotFound();
            }
        }
    });

    it('answers 404 for a receipt without a stored PDF document, to the Partner and the Admin', function (): void {
        [, $receipt] = cashReceiptPartnerPay($this->clientA);

        // Mutation style: the guard that clears the link only together with its file is dropped for this test only
        // (it is rolled back), so the receipt is left without a PDF.
        DB::unprepared('ALTER TABLE cash_receipts DISABLE TRIGGER cash_receipts_pdf_link_guard');
        DB::table('cash_receipts')->where('id', $receipt->id)->update(['pdf_document_id' => null]);

        foreach ([$this->partnerA, $this->admin] as $user) {
            $this->actingAs($user);

            $this->get(CashReceiptPdfUrl::download($receipt, $user))->assertNotFound();
        }
    });

    it('answers 404 when the stored object is gone', function (): void {
        [, $receipt] = cashReceiptPartnerPay($this->clientA);

        app(PartnerContext::class)->runAsSystem(static function () use ($receipt): void {
            $media = Document::query()->findOrFail($receipt->pdf_document_id)->getFirstMedia(Document::MEDIA_COLLECTION);
            Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());
        });

        $this->actingAs($this->partnerA);

        $this->get(CashReceiptPdfUrl::download($receipt, $this->partnerA))->assertNotFound();
    });

    it('sends a guest who opens a valid link to the panel login', function (): void {
        [, $receipt] = cashReceiptPartnerPay($this->clientA);
        $link = CashReceiptPdfUrl::download($receipt, $this->partnerA);

        auth()->forgetGuards();

        $this->get($link)->assertRedirect(Filament::getLoginUrl());
    });
});
