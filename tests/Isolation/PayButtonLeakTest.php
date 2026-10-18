<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Payments\Actions\DeactivatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ListPartnerInvoices;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;

/*
 * Roadmap Phase 11 success criterion 2 (part), threats T-11-52 to T-11-54: a Partner who opens the invoice list and
 * the detail of every own document meets "Zaplatit kartou" on the one document that can be paid by card and nothing
 * else of the card-payment machinery: no link state, amount, id or failure, no Stripe address, no pay URL of another
 * document and nothing at all of the other client. Each client owns an invoice with an active, a pending, a failed
 * and a deactivated link and a paid one. The pages are matched as plain text, the Livewire snapshots included
 * (decoded), against the strings of the UI-SPEC "Never on a Partner surface" list. Each refusal is paired with a
 * positive check, so the sweep cannot pass vacuously. Every value is fictional or assembled at runtime.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));

    $this->clientA = CardPaymentSetup::client();
    $this->clientB = CardPaymentSetup::client();

    // The jobs run first (sync) for the documents with a settled link; the pending ones are issued last, under a fake queue.
    $this->a = payLeakSettled($this, $this->clientA);
    $this->b = payLeakSettled($this, $this->clientB);

    Queue::fake();
    $this->a['pending'] = CardPaymentSetup::issued($this->clientA);
    $this->b['pending'] = CardPaymentSetup::issued($this->clientB);

    $this->partnerA = Canary::partnerFor($this->clientA->id);
});

/**
 * The documents of one client whose link is active, failed, deactivated by the Admin or ended by a full payment.
 *
 * @return array<string, Invoice>
 */
function payLeakSettled(object $test, Client $client): array
{
    $docs = ['active' => CardPaymentSetup::issued($client)];

    $test->stripe->failNextCreate('invalid_request');
    $docs['failed'] = CardPaymentSetup::issued($client);

    $docs['deactivated'] = CardPaymentSetup::issued($client);
    app(DeactivatePaymentLink::class)->handle($test->admin, $docs['deactivated']);

    $docs['paid'] = CardPaymentSetup::issued($client);
    app(RecordPayment::class)->handle($test->admin, $docs['paid'], ['amount' => '12500.00', 'paid_on' => '2026-10-14']);

    return $docs;
}

/**
 * The strings of the UI-SPEC "Never on a Partner surface" list that belong to the card-payment machinery.
 *
 * @return list<string>
 */
function payLeakForbidden(): array
{
    return [
        'Platby kartou', 'Platební odkaz', 'Stav odkazu', 'Nabízená částka', 'Vytvořit odkaz znovu', 'Deaktivovat odkaz',
        'Vytvoření selhalo', 'Duplicitní', 'Nespárovaná', 'Nevyřešeno', 'Stripe', 'stripe.com',
    ];
}

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function payLeakSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The pay token of the document.
 */
function payLeakToken(Invoice $invoice): string
{
    return (string) payLeakSystem(static fn (): mixed => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->value('pay_token'));
}

/**
 * The Stripe references and the failure class of every link row of both clients, as the strings no Partner may meet.
 *
 * @return list<string>
 */
function payLeakLinkValues(): array
{
    return payLeakSystem(static function (): array {
        $values = [];

        foreach (PaymentLink::query()->get() as $link) {
            foreach ([$link->stripe_link_id, $link->stripe_url, $link->failure_class, $link->id] as $value) {
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                }
            }
        }

        return $values;
    });
}

/**
 * The text a browser or a script could read from a response: the HTML, and the decoded Livewire snapshots.
 */
function payLeakSurface(string $html): string
{
    $surface = $html;

    if (preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches) > 0) {
        foreach ($matches[1] as $snapshot) {
            $decoded = json_decode(html_entity_decode($snapshot, ENT_QUOTES), true);

            if (is_array($decoded)) {
                $surface .= "\n".json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }

    return $surface;
}

/**
 * Asserts that the response holds none of the strings, in any of the spellings it could be written in.
 *
 * @param  list<string>  $strings
 */
function payLeakAssertAbsent(string $html, string $where, array $strings): void
{
    $surface = payLeakSurface($html);

    foreach ($strings as $text) {
        foreach (array_unique([$text, trim((string) json_encode($text), '"'), e($text)]) as $form) {
            expect(str_contains($surface, $form))->toBeFalse("{$where} must not hold \"{$text}\"");
        }
    }
}

/**
 * Every token and pay URL of the documents handed in, for "must not hold".
 *
 * @param  list<Invoice>  $documents
 * @return list<string>
 */
function payLeakAddresses(array $documents): array
{
    $values = [];

    foreach ($documents as $document) {
        $token = payLeakToken($document);
        array_push($values, $token, PayUrl::for($token));
    }

    return $values;
}

describe('proves the fixtures exist: every link state is there for the Admin', function (): void {
    it('holds an active, a pending, a failed and a deactivated link and a paid document for both clients', function (): void {
        foreach ([$this->a, $this->b] as $side) {
            $states = payLeakSystem(static fn (): array => array_map(
                static fn (Invoice $invoice): ?string => PaymentLink::latestFor($invoice->id)?->state->value,
                $side,
            ));

            expect($states)->toBe(['active' => 'active', 'failed' => 'failed', 'deactivated' => 'deactivated', 'paid' => 'deactivated', 'pending' => 'pending']);

            foreach ($side as $invoice) {
                expect(payLeakToken($invoice))->not->toBe('');
            }
        }

        expect(payLeakLinkValues())->not->toBe([]);
    });
});

describe('what a Partner sees', function (): void {
    it('keeps the invoice list free of the card-payment strings, link ids and pay URLs', function (): void {
        $this->actingAs($this->partnerA);

        $hidden = [...payLeakForbidden(), ...payLeakLinkValues(), '/pay/', ...payLeakAddresses([...array_values($this->a), ...array_values($this->b)])];

        $html = (string) $this->get(PartnerInvoiceResource::getUrl())->assertOk()->assertSee($this->a['active']->number)->getContent();

        payLeakAssertAbsent($html, 'the Partner invoice list', $hidden);

        $component = Livewire::test(ListPartnerInvoices::class)->assertCanSeeTableRecords(array_values($this->a));

        payLeakAssertAbsent($component->html(), 'the Partner invoice list component', $hidden);
    });

    it('shows "Zaplatit kartou" on the detail of the active document only, with that document\'s stable URL as the only pay URL', function (): void {
        $this->actingAs($this->partnerA);

        $activeUrl = PayUrl::for(payLeakToken($this->a['active']));
        $links = payLeakLinkValues();

        foreach ($this->a as $key => $document) {
            $html = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $document]))->assertOk()->assertSee($document->number)->getContent();
            $surface = payLeakSurface($html);

            payLeakAssertAbsent($html, "the Partner detail of {$key}", [...payLeakForbidden(), ...$links, ...payLeakAddresses(array_values($this->b))]);

            if ($key === 'active') {
                expect($surface)->toContain('Zaplatit kartou')
                    ->and($surface)->toContain($activeUrl)
                    ->and(preg_match_all('#/pay/[A-Za-z0-9]+#', $html))->toBe(1);

                continue;
            }

            expect($surface)->not->toContain('Zaplatit kartou')
                ->and($surface)->not->toContain('/pay/')
                ->and($surface)->toContain('Stáhnout PDF');

            payLeakAssertAbsent($html, "the Partner detail of {$key}", payLeakAddresses([$document]));
        }
    });

    it('renders the same through the Livewire component of each own detail page', function (): void {
        $this->actingAs($this->partnerA);

        foreach ($this->a as $key => $document) {
            $component = Livewire::test(ViewPartnerInvoice::class, ['record' => $document->getRouteKey()]);

            payLeakAssertAbsent($component->html(), "the Partner detail component of {$key}", [...payLeakForbidden(), ...payLeakLinkValues()]);

            if ($key === 'active') {
                $component->assertSee('Zaplatit kartou');

                continue;
            }

            $component->assertDontSee('Zaplatit kartou');
        }
    });
});

describe('the other client', function (): void {
    it('answers 404 to the detail of the other client\'s documents, naming neither a number nor a pay URL', function (): void {
        $this->actingAs($this->partnerA);

        $ownNumbers = array_map(static fn (Invoice $invoice): string => $invoice->number, array_values($this->a));
        $links = payLeakLinkValues();

        foreach ($this->b as $key => $document) {
            $response = $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $document->id]));

            expect($response->getStatusCode())->toBe(404);

            $hidden = [...payLeakForbidden(), ...$links, ...payLeakAddresses([$document]), $document->id, 'Zaplatit kartou'];

            if (! in_array($document->number, $ownNumbers, true)) {
                $hidden[] = $document->number;
            }

            payLeakAssertAbsent((string) $response->getContent(), "the 404 of {$key}", $hidden);
        }
    });

    it('gives the Partner of client B the button on B\'s active document and none of A\'s addresses', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientB->id));

        $html = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->b['active']]))->assertOk()->getContent();

        expect($html)->toContain('Zaplatit kartou')->toContain(PayUrl::for(payLeakToken($this->b['active'])));

        payLeakAssertAbsent($html, 'the detail of client B', [...payLeakAddresses(array_values($this->a)), ...payLeakForbidden()]);
    });

    it('lets a Partner without a client see no button and no pay URL anywhere', function (): void {
        $this->actingAs(Canary::partnerFor(null));

        $urls = [PartnerInvoiceResource::getUrl(), PartnerInvoiceResource::getUrl('view', ['record' => $this->a['active']->id])];

        foreach ($urls as $url) {
            $response = $this->get($url);

            expect($response->getStatusCode())->not->toBe(500);

            payLeakAssertAbsent((string) $response->getContent(), $url, [
                ...payLeakForbidden(), ...payLeakLinkValues(), 'Zaplatit kartou', '/pay/', ...payLeakAddresses(array_values($this->a)),
            ]);
        }
    });
});
