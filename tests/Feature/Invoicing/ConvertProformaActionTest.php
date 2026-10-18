<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The conversion of a paid proforma from its own detail (IN-06, FI-03; D-15, D-18; UI-SPEC E5): one confirmation, the
 * success path of the issue and a conversion that fails or lost the race. Every name and amount is fictional.
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

function conversionPage(Invoice $proforma): Testable
{
    return Livewire::test(ViewInvoice::class, ['record' => $proforma->getRouteKey()]);
}

/**
 * A paid proforma without an invoice.
 */
function paidProforma(): Invoice
{
    $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);
    app(RecordPayment::class)->handle(Canary::admin(), $proforma, ['amount' => '1000.00', 'paid_on' => '2026-10-13']);

    return $proforma;
}

/**
 * The notifications sent so far, flushed from the mailbox.
 *
 * @return list<array{title: string, body: string, status: string}>
 */
function conversionNotifications(): array
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

describe('a paid proforma without an invoice', function (): void {
    it('shows the primary Vystavit fakturu and the danger banner', function (): void {
        $proforma = paidProforma();

        $page = conversionPage($proforma)
            ->assertSee('Záloha je zaplacená, ale faktura k ní zatím nebyla vystavena.')
            ->assertActionVisible('convertProforma')
            ->assertActionHasLabel('convertProforma', 'Vystavit fakturu')
            ->assertActionHasColor('convertProforma', 'primary')
            ->assertActionHidden('recordPayment');

        expect($page->html())->toContain('Vystavit fakturu');
    });

    it('converts after one confirmation, shows the invoice toast and opens the new invoice with send=1', function (): void {
        $proforma = paidProforma();

        $page = conversionPage($proforma)
            ->mountAction('convertProforma')
            ->assertMountedActionModalSee([
                'Vystavit fakturu ze zálohy '.$proforma->number.'?',
                'Faktura dostane vlastní číslo z řady faktur, převezme položky zálohy a bude s ní propojena.',
                'Platba ze zálohy se převede na fakturu a příjem se zaúčtuje s původním datem platby.',
                'Nevystavovat',
            ])
            ->callMountedAction()
            ->assertNotified('Faktura byla vystavena');

        $invoice = Invoice::query()->where('proforma_id', $proforma->id)->sole();

        $page->assertRedirect(InvoiceResource::getUrl('view', ['record' => $invoice, 'send' => 1]));

        expect($invoice->kind)->toBe(InvoiceKind::Invoice)
            ->and($invoice->isIssued())->toBeTrue()
            ->and($invoice->payments()->count())->toBe(1)
            ->and(Transaction::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
    });

    it('then shows Převedeno na fakturu with the related invoice and no action', function (): void {
        $proforma = paidProforma();
        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        conversionPage($proforma)
            ->assertSee('Převedeno na fakturu')
            ->assertSee('Související doklady')
            ->assertSee($invoice->number)
            ->assertDontSee('Záloha je zaplacená, ale faktura')
            ->assertActionHidden('convertProforma')
            ->assertActionHidden('recordPayment');
    });

    it('leaves the proforma unconverted and shows the fixed copy when the render fails', function (): void {
        $proforma = paidProforma();
        $secret = 'The renderer fell over at '.str_repeat('x', 6);

        View::composer('pdf.invoice', static function () use ($secret): void {
            throw new RuntimeException($secret);
        });

        $page = conversionPage($proforma)->mountAction('convertProforma')->callMountedAction();
        $sent = conversionNotifications();

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['status'])->toBe('danger')
            ->and($sent[0]['title'])->toBe(__('invoices.issue.failed_title'))
            ->and($sent[0]['body'])->toBe(__('invoices.issue.failed_body'))
            ->and(json_encode($sent))->not->toContain($secret);

        $page->assertNoRedirect();

        expect($page->html())->not->toContain($secret)
            ->and(Invoice::query()->where('proforma_id', $proforma->id)->exists())->toBeFalse();
    });

    it('issues no second invoice when the proforma was converted in another tab meanwhile', function (): void {
        $proforma = paidProforma();

        $page = conversionPage($proforma)->mountAction('convertProforma');

        app(ConvertProforma::class)->handle($this->admin, $proforma);

        // The action is hidden on the fresh state, so the confirmation of the stale tab does nothing.
        $page->callMountedAction()->assertNoRedirect();

        expect(Invoice::query()->where('proforma_id', $proforma->id)->count())->toBe(1);
    });

    it('shows the Czech reason of a refusal that reaches the Action and keeps the page', function (): void {
        $proforma = paidProforma();

        app()->bind(ConvertProforma::class, static fn (): object => new class
        {
            public function handle(): never
            {
                throw ValidationException::withMessages(['proforma' => __('invoices.errors.proforma_converted')]);
            }
        });

        $page = conversionPage($proforma)->mountAction('convertProforma')->callMountedAction()->assertNoRedirect();
        $sent = conversionNotifications();

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['status'])->toBe('danger')
            ->and($sent[0]['body'])->toBe(__('invoices.errors.proforma_converted'))
            ->and(Invoice::query()->where('proforma_id', $proforma->id)->exists())->toBeFalse();

        $page->assertSuccessful();
    });
});

describe('where the action is not offered', function (): void {
    it('hides it on an unpaid proforma and shows the payment action instead', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);

        conversionPage($proforma)
            ->assertActionHidden('convertProforma')
            ->assertActionVisible('recordPayment')
            ->assertDontSee('Záloha je zaplacená');
    });

    it('hides it on a partly paid proforma, on an invoice and answers a Partner with 403', function (): void {
        $client = InvoiceFixtures::client();
        $partly = InvoiceFixtures::issued($client, [], InvoiceKind::Proforma);
        app(RecordPayment::class)->handle($this->admin, $partly, ['amount' => '400.00', 'paid_on' => '2026-10-13']);
        $invoice = InvoiceFixtures::issued($client);

        conversionPage($partly)->assertActionHidden('convertProforma');
        conversionPage($invoice)->assertActionHidden('convertProforma');

        $this->actingAs(Canary::partnerFor($client->id));

        conversionPage($partly)->assertForbidden();
    });
});
