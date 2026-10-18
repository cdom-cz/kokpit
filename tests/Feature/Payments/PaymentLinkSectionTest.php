<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\RegeneratePaymentLink;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Support\PaymentLinkSection;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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

/**
 * The text of the page without markup, whitespace collapsed.
 */
function linkText(Testable $page): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($page->html()), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/**
 * The notifications sent during the request, as title, body and status.
 *
 * @return list<array{title: string, body: string, status: string}>
 */
function linkNotifications(): array
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

/**
 * A payment through the manual path, as the Admin.
 */
function linkPay(Invoice $invoice, string $amount): void
{
    app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14']);
}

describe('the states', function (): void {
    it('shows "Vypnuto" with its reason and no action when the switch was off at issue', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['online_payment_enabled' => false]));

        $page = linkPage($invoice)
            ->assertSee('Vypnuto')
            ->assertSee('Při vystavení nebyla platba kartou zapnutá.')
            ->assertDontSee('Vytvořit odkaz znovu')
            ->assertDontSee('Deaktivovat odkaz')
            ->assertDontSee('Odkaz pro klienta')
            ->assertActionHidden('payByCard');

        expect(linkText($page))->toMatch('/Platba kartou Ne\b/u');
    });

    it('shows the frozen switch of a card-on document as "Ano"', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        expect(linkText(linkPage($invoice)))->toMatch('/Platba kartou Ano\b/u');
    });

    it('shows a failed link with the fixed reason, the danger banner and a working regenerate button, and never the provider text', function (): void {
        $this->stripe->failNextCreate('invalid_request');
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        expect(newestLink($invoice)?->state)->toBe(PaymentLinkState::Failed);

        $page = linkPage($invoice)
            ->assertSee('Vytvoření selhalo')
            ->assertSee('Stripe odkaz se nepodařilo vytvořit. Zkuste to znovu; pokud chyba přetrvá, zkontrolujte nastavení Stripe v Systému.')
            ->assertSee('Odkaz na platbu kartou se nepodařilo vytvořit.')
            ->assertSee('Odkaz na platbu se připravuje')
            ->assertDontSee('invalid_request')
            ->assertDontSee('Doklad nemá aktivní odkaz na platbu kartou')
            ->assertActionHidden('payByCard');

        // The same button sits in the banner and in the section header; the banner one is addressed by its key.
        $page->callAction(linkAction('regeneratePaymentLink', 'payment_link_failed'))
            ->assertSee('Aktivní')
            ->assertDontSee('Odkaz na platbu kartou se nepodařilo vytvořit.')
            ->assertActionVisible('payByCard');

        expect(newestLink($invoice)?->state)->toBe(PaymentLinkState::Active);
    });

    it('explains a link deactivated by a partial payment, warns with the remaining amount and offers regeneration', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        linkPay($invoice, '2500.00');

        $page = linkPage($invoice)
            ->assertSee('Deaktivován')
            ->assertSee('Po částečné platbě se nabízená částka změnila.')
            ->assertSee('Doklad nemá aktivní odkaz na platbu kartou.')
            ->assertSee('na zbývající částku')
            ->assertSee(Money::ofMinor(1000000, 'CZK')->format('cs'))
            ->assertActionHidden('payByCard')
            ->assertDontSee('Deaktivovat odkaz');

        $page->callAction(linkAction('regeneratePaymentLink', 'payment_link_inactive'))
            ->assertSee('Aktivní')
            ->assertDontSee('Doklad nemá aktivní odkaz na platbu kartou.')
            ->assertActionVisible('payByCard');

        $link = newestLink($invoice);

        expect($link?->state)->toBe(PaymentLinkState::Active)
            ->and($link?->amount_minor)->toBe(1000000);
    });

    it('offers nothing on a paid document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        linkPay($invoice, '12500.00');

        linkPage($invoice)
            ->assertSee('Doklad je zaplacený.')
            ->assertDontSee('Vytvořit odkaz znovu')
            ->assertDontSee('Deaktivovat odkaz')
            ->assertDontSee('Doklad nemá aktivní odkaz na platbu kartou')
            ->assertActionHidden('payByCard');
    });

    it('offers nothing on a cancelled document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        linkPage($invoice->refresh())
            ->assertSee('Doklad byl stornován.')
            ->assertDontSee('Vytvořit odkaz znovu')
            ->assertDontSee('Deaktivovat odkaz')
            ->assertDontSee('Doklad nemá aktivní odkaz na platbu kartou')
            ->assertActionHidden('payByCard');
    });

    it('shows the section on a proforma and none on a credit note', function (): void {
        $client = CardPaymentSetup::client();
        $proforma = CardPaymentSetup::issued($client, InvoiceKind::Proforma);
        $invoice = CardPaymentSetup::issued($client);
        $credit = app(IssueCreditNote::class)->storno($this->admin, $invoice);

        linkPage($proforma)->assertSee('Stav odkazu')->assertSee('Aktivní')->assertActionVisible('payByCard');

        $page = linkPage($credit)->assertDontSee('Stav odkazu')->assertDontSee('Odkaz pro klienta')->assertActionHidden('payByCard');

        expect(linkText($page))->not->toMatch('/Platba kartou (Ano|Ne)\b/u');
    });

    it('shows a danger toast and changes nothing when the Action refuses the regeneration', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $this->stripe->failNextCreate('invalid_request');
        $failed = CardPaymentSetup::issued(CardPaymentSetup::client());
        $before = newestLink($failed)?->id;

        // A pending link appears between the render and the click: the Action refuses under payment_link.
        app()->instance(RegeneratePaymentLink::class, new class
        {
            public function handle(): never
            {
                throw ValidationException::withMessages(['payment_link' => __('payment_links.errors.preparing')]);
            }
        });

        linkPage($failed)->callAction(linkAction('regeneratePaymentLink'));

        $sent = linkNotifications();

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['status'])->toBe('danger')
            ->and($sent[0]['body'])->toBe(__('payment_links.errors.preparing'))
            ->and(newestLink($failed)?->id)->toBe($before)
            ->and(newestLink($invoice)?->state)->toBe(PaymentLinkState::Active);
    });

    it('refuses a Partner before any action runs', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);

        $this->actingAs(Canary::partnerFor($client->id));

        $this->get(InvoiceResource::getUrl('view', ['record' => $invoice]))->assertForbidden();
        linkPage($invoice)->assertForbidden();

        expect(newestLink($invoice)?->state)->toBe(PaymentLinkState::Active);
    });
});
