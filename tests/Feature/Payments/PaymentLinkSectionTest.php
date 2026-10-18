<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Support\PaymentLinkSection;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;

/*
 * The "Platba kartou" section of the Admin detail, its two actions, the two banners and the header action
 * "Zaplatit kartou" (PA-01 to PA-04; D-01, D-04 to D-09, UI-SPEC Surfaces D, E and F). The gateway is the in-memory
 * fake; every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * The detail page of the document.
 */
function linkPage(Invoice $invoice): Testable
{
    return Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The action of the link section (header) or of a banner (by its key) on the page.
 */
function linkAction(string $name, string $component = PaymentLinkSection::KEY): TestAction
{
    return TestAction::make($name)->schemaComponent($component, schema: 'infolist');
}

/**
 * The newest link row of the document, read as a system run.
 */
function newestLink(Invoice $invoice): ?PaymentLink
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ?PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->orderByDesc('created_at')->orderByDesc('id')->first());
}

describe('the tracer', function (): void {
    it('shows an active link, opens the pay page from the header, deactivates and regenerates the link', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $url = PayUrl::forInvoice($invoice);

        $page = linkPage($invoice)
            ->assertSee('Platba kartou')
            ->assertSee('Stav odkazu')
            ->assertSee('Aktivní')
            ->assertSee('Nabízená částka')
            ->assertSee($url)
            ->assertDontSee('stripe.com')
            ->assertActionVisible('payByCard')
            ->assertActionHasUrl('payByCard', $url)
            ->assertActionShouldOpenUrlInNewTab('payByCard')
            ->assertDontSee('wire:poll.5s', escape: false);

        // The header order: the e-mail action, then the pay action, then the payment action.
        $actions = collect($page->instance()->getCachedHeaderActions())->map(static fn ($action): string => $action instanceof Action ? (string) $action->getName() : 'group')->values()->all();

        expect(array_search('payByCard', $actions, true))->toBeGreaterThan(array_search('sendEmail', $actions, true))
            ->and(array_search('payByCard', $actions, true))->toBeLessThan(array_search('recordPayment', $actions, true));

        $page->callAction(linkAction('deactivatePaymentLink'))
            ->assertHasNoActionErrors()
            ->assertSee('Deaktivován')
            ->assertSee('Deaktivován ručně.')
            ->assertActionHidden('payByCard')
            ->assertDontSee('Deaktivovat odkaz');

        expect(newestLink($invoice)?->state)->toBe(PaymentLinkState::Deactivated);

        Queue::fake();

        $page->callAction(linkAction('regeneratePaymentLink'))
            ->assertSee('Připravuje se')
            ->assertSee('wire:poll.5s', escape: false)
            ->assertDontSee('Vytvořit odkaz znovu');

        expect(newestLink($invoice)?->state)->toBe(PaymentLinkState::Pending);

        $job = Queue::pushed(CreatePaymentLink::class)->first();
        expect($job)->toBeInstanceOf(CreatePaymentLink::class);
        app()->call([$job, 'handle']);

        $page = linkPage($invoice)
            ->assertSee('Aktivní')
            ->assertDontSee('wire:poll.5s', escape: false)
            ->assertActionVisible('payByCard');

        expect(newestLink($invoice)?->state)->toBe(PaymentLinkState::Active);
    });

    it('never renders a Stripe address or id', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $link = newestLink($invoice);

        $html = linkPage($invoice)->html();

        expect($html)->not->toContain('stripe.com')
            ->and($html)->not->toContain((string) $link?->stripe_link_id)
            ->and($html)->toContain(PayUrl::forInvoice($invoice));
    });

    it('lists the section after the summary and before the related documents', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(), InvoiceKind::Proforma);

        linkPage($invoice)->assertSeeInOrder(['Souhrn', 'Platba kartou', 'Stav odkazu']);
    });
});
