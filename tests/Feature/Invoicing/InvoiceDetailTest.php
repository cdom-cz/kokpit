<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The issued document's detail (IN-03, IN-04, IN-05, IN-06, IN-07; D-12, D-13, D-16, D-18, D-21, D-23; UI-SPEC Surface
 * E): the banners that answer "is it paid and what is next", the frozen sections, the internal note and the missing
 * PDF. Every name, rate and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * The page of an issued document.
 */
function detail(Invoice $invoice): Testable
{
    return Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * Records a payment of the document as the Admin.
 */
function detailPay(Invoice $invoice, string $amount, string $paidOn = '2026-10-14'): void
{
    app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => $amount, 'paid_on' => $paidOn]);
}

function detailMoney(int $minor, string $currency = 'CZK'): string
{
    return Money::ofMinor($minor, $currency)->format('cs');
}

/**
 * The Admin-only half of the document, read as system.
 */
function detailAdmin(Invoice $invoice): InvoiceAdmin
{
    return app(PartnerContext::class)->runAsSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->firstOrFail());
}

/**
 * The notifications sent so far, flushed from the mailbox.
 *
 * @return list<array{title: string, body: string, status: string}>
 */
function detailNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $panel = new Notifications;
    $panel->mount();
    $panel->pullNotificationsFromSession();

    $sent = [];

    foreach ($panel->notifications as $notification) {
        $sent[] = ['title' => (string) $notification->getTitle(), 'body' => (string) $notification->getBody(), 'status' => (string) $notification->getStatus()];
    }

    return $sent;
}

describe('the heading and the state', function (): void {
    it('shows the number with the kind and state badges and the client link', function (): void {
        $client = InvoiceFixtures::client(['name' => 'Example detail client']);
        $invoice = InvoiceFixtures::issued($client);

        detail($invoice)
            ->assertSee($invoice->number)
            ->assertSee('Faktura')
            ->assertSee('Nezaplaceno')
            ->assertSee('Example detail client')
            ->assertSee(ClientResource::getUrl('view', ['record' => $client->id]), escape: false);
    });

    it('sends a draft to its editor', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

        detail($draft)->assertRedirect(InvoiceResource::getUrl('edit', ['record' => $draft]));
    });

    it('shows the issue facts of the document', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['payment_terms_days' => 21]));

        detail($invoice)
            ->assertSee('14. 10. 2026')
            ->assertSee('4. 11. 2026')
            ->assertSee((string) $invoice->variable_symbol)
            ->assertSee('Čeština')
            ->assertSee('Převodem')
            ->assertDontSee('Datum zdanitelného plnění');
    });
});

describe('the banners', function (): void {
    it('warns about an overdue partly paid invoice with the days and the remaining amount', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        detailPay($invoice, '400.00');

        // Due 2026-10-28; five days later.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));

        detail($invoice)
            ->assertSee('Faktura je po splatnosti o 5 dní. Zbývá uhradit '.detailMoney(60000).'.')
            ->assertSee('Po splatnosti')
            ->assertSee('Uhrazeno')
            ->assertSee(detailMoney(40000))
            ->assertSee('Zbývá uhradit')
            ->assertSee(detailMoney(60000));
    });

    it('shows no banner on a paid invoice', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        detailPay($invoice, '1000.00');

        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));

        detail($invoice)
            ->assertSee('Zaplaceno')
            ->assertDontSee('je po splatnosti')
            ->assertDontSee('K dokladu existuje dobropis');
    });

    it('declares a paid proforma without an invoice and not an unpaid one', function (): void {
        $client = InvoiceFixtures::client();
        $unpaid = InvoiceFixtures::issued($client, [], InvoiceKind::Proforma);
        $paid = InvoiceFixtures::issued($client, [], InvoiceKind::Proforma);
        detailPay($paid, '1000.00');

        detail($paid)->assertSee('Záloha je zaplacená, ale faktura k ní zatím nebyla vystavena. Příjem se zaúčtuje až při vystavení faktury.');
        detail($unpaid)->assertDontSee('Záloha je zaplacená');
    });

    it('shows a converted proforma as converted with the related invoice', function (): void {
        $client = InvoiceFixtures::client();
        $proforma = InvoiceFixtures::issued($client, [], InvoiceKind::Proforma);
        detailPay($proforma, '1000.00');
        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        detail($proforma)
            ->assertSee('Převedeno na fakturu')
            ->assertSee('Související doklady')
            ->assertSee($invoice->number)
            ->assertSee(InvoiceResource::getUrl('view', ['record' => $invoice]), escape: false)
            ->assertDontSee('Záloha je zaplacená, ale faktura');

        // The invoice names its source proforma.
        detail($invoice)->assertSee('Zdrojová záloha')->assertSee($proforma->number);
    });

    it('warns about a cancelled invoice that kept a payment and links the storno credit note', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        detailPay($invoice, '300.00');
        $credit = app(IssueCreditNote::class)->storno($this->admin, $invoice);

        detail($invoice)
            ->assertSee('Faktura byla stornována, ale platba zůstává zaevidovaná. Vrácení peněz a výdaj zaznamenejte ručně.')
            ->assertSee('Stornováno')
            ->assertSee('K dokladu existuje dobropis')
            ->assertSee($credit->number)
            ->assertSee(InvoiceResource::getUrl('view', ['record' => $credit]), escape: false);
    });

    it('does not warn about a cancelled invoice without a payment', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        detail($invoice)->assertDontSee('platba zůstává zaevidovaná');
    });

    it('names the credit note of an invoice and the invoice a credit note refers to', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example alpha', '4', '100.00')]);
        $line = InvoiceItem::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $credit = app(IssueCreditNote::class)->partial($this->admin, $invoice, [$line->id => '1']);

        detail($invoice)
            ->assertSeeHtml('K dokladu existuje dobropis <a href="'.e(InvoiceResource::getUrl('view', ['record' => $credit])).'" style="text-decoration: underline;">'.$credit->number.'</a>.')
            ->assertSee('Související doklady');
        detail($credit)->assertSee('Související doklady')->assertSee($invoice->number);
    });

    it('has no related section when nothing is related', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        detail($invoice)->assertDontSee('Související doklady');
    });
});

describe('the frozen sections', function (): void {
    it('shows the snapshots of both parties after the client was renamed, with the lock tooltip', function (): void {
        $client = InvoiceFixtures::client(['name' => 'Example frozen name', 'street' => 'Frozen street 5']);
        $invoice = InvoiceFixtures::issued($client);

        // The subheading names the client as it is now; the party blocks keep what was frozen.
        app(PartnerContext::class)->runAsSystem(static fn (): bool => Client::query()->whereKey($client->id)->update(['street' => 'Later street 9']) > 0);

        detail($invoice)
            ->assertSee('Strany')
            ->assertSee('Dodavatel')
            ->assertSee('Odběratel')
            ->assertSee('Example Supplier s.r.o.')
            ->assertSee('Example frozen name')
            ->assertSee('Frozen street 5')
            ->assertDontSee('Later street 9')
            ->assertSee('Údaje jsou zmrazené k datu vystavení.');
    });

    it('lists the frozen lines, and shows the amounts of a credit note negative', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example alpha', '2', '250.00'), InvoiceFixtures::item('Example bravo', '1', '90.00', 'ks')]);
        $credit = InvoiceFixtures::stornoed($invoice);

        detail($invoice)
            ->assertSee('Položky')
            ->assertSee('Example alpha')
            ->assertSee('Example bravo')
            ->assertSee(detailMoney(50000))
            ->assertSee(detailMoney(9000))
            ->assertSee(detailMoney(59000));

        detail($credit)->assertSee('-'.detailMoney(50000), escape: false)->assertSee(detailMoney(-59000));
    });

    it('shows the CNB rate with its date and the CZK total of a foreign-currency invoice only', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '24.500');
        $eur = InvoiceFixtures::issued(InvoiceFixtures::client(['currency' => 'EUR']), [InvoiceFixtures::item('Example work', '2', '100.00')]);
        $czk = InvoiceFixtures::issued(InvoiceFixtures::client());

        detail($eur)
            ->assertSee('Kurz ČNB ke dni vystavení 14. 10. 2026: 24,500')
            ->assertSee('Celkem v Kč: '.detailMoney(490000));

        detail($czk)->assertDontSee('Kurz ČNB ke dni vystavení')->assertDontSee('Celkem v Kč');
    });

    it('shows the printed note read only and the internal note', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);
        app(UpdateDraft::class)->handle($this->admin, $draft, ['note' => 'Printed example note', 'internal_note' => 'Private example note']);
        $invoice = app(IssueDocument::class)->handle($this->admin, $draft);

        detail($invoice)
            ->assertSee('Poznámky')
            ->assertSee('Poznámka na dokladu')
            ->assertSee('Printed example note')
            ->assertSee('Interní poznámka')
            ->assertSee('Private example note');
    });
});

describe('the internal note', function (): void {
    it('stores the note through UpdateInternalNote and writes nothing on the issued row', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $before = (array) DB::table('invoices')->where('id', $invoice->id)->first();

        detail($invoice)
            ->mountAction(TestAction::make('editNote')->schemaComponent('internal_note', schema: 'infolist'))
            ->assertMountedActionModalSee('Interní poznámka')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $component = detail($invoice);
        $component->callAction(TestAction::make('editNote')->schemaComponent('internal_note', schema: 'infolist'), ['internal_note' => '  Example follow-up  '])
            ->assertHasNoActionErrors()
            ->assertNotified('Poznámka byla uložena');

        expect(detailAdmin($invoice)->internal_note)->toBe('Example follow-up')
            ->and((array) DB::table('invoices')->where('id', $invoice->id)->first())->toBe($before);

        $component->assertSee('Example follow-up');
    });

    it('refuses a note over the limit under the field', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        detail($invoice)
            ->callAction(TestAction::make('editNote')->schemaComponent('internal_note', schema: 'infolist'), ['internal_note' => str_repeat('x', 2001)])
            ->assertHasActionErrors(['internal_note']);

        expect(detailAdmin($invoice)->internal_note)->toBeNull();
    });
});

describe('the stored PDF', function (): void {
    it('offers the download as a signed link and no warning while the PDF exists', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        detail($invoice)
            ->assertDontSee('PDF dokladu chybí.')
            ->assertActionVisible('downloadPdf')
            ->callAction('downloadPdf')
            ->assertRedirectContains('signature=');
    });

    it('warns when the PDF was deleted and creates it again from the snapshots', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $document = detailAdmin($invoice)->pdfDocument;
        app(DeleteDocument::class)->handle($this->admin, $document, true);

        $page = detail($invoice)
            ->assertSee('PDF dokladu chybí.')
            ->assertSee('Vytvořit PDF znovu')
            ->assertActionHidden('downloadPdf');

        $page->callAction(TestAction::make('regeneratePdf')->schemaComponent('pdf_missing', schema: 'infolist'));

        expect(detailAdmin($invoice)->pdf_document_id)->not->toBeNull()
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Document::query()->count()))->toBe(1);

        $page->assertDontSee('PDF dokladu chybí.');
    });

    it('keeps the state and shows the fixed copy when the regeneration fails', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        app(DeleteDocument::class)->handle($this->admin, detailAdmin($invoice)->pdfDocument, true);

        $secret = 'The renderer fell over at '.str_repeat('y', 6);

        View::composer('pdf.invoice', static function () use ($secret): void {
            throw new RuntimeException($secret);
        });

        $page = detail($invoice)->callAction(TestAction::make('regeneratePdf')->schemaComponent('pdf_missing', schema: 'infolist'));

        $sent = detailNotifications();

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['status'])->toBe('danger')
            ->and($sent[0]['title'])->toBe('PDF se nepodařilo vytvořit')
            ->and($sent[0]['body'])->toBe(__('invoices.detail.pdf_failed_body'))
            ->and(json_encode($sent))->not->toContain($secret)
            ->and(detailAdmin($invoice)->pdf_document_id)->toBeNull();

        $page->assertSee('PDF dokladu chybí.');
        expect($page->html())->not->toContain($secret);
    });
});

describe('access', function (): void {
    it('answers a Partner with 403 on the detail', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);

        $this->actingAs(Canary::partnerFor($client->id));

        $this->get(InvoiceResource::getUrl('view', ['record' => $invoice]))->assertForbidden();
    });

    it('re-checks the Admin on every update of the page', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);

        $page = detail($invoice);

        $this->actingAs(Canary::partnerFor($client->id));

        $page->call('$refresh')->assertForbidden();
    });
});
