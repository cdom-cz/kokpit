<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Payments\Actions\DeactivatePaymentLink;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentTab;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ListPartnerInvoices;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use App\Filament\Resources\CardPaymentResource;
use App\Filament\Resources\CardPaymentResource\Pages\ListCardPayments;
use App\Filament\Resources\CardPaymentResource\Pages\ViewCardPayment;
use App\Filament\Widgets\UnresolvedPayments;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentFixtures;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;
use Tests\Support\StripeEvents;
use Tests\Support\StripeWebhookSigner;

/*
 * Roadmap Phase 11 success criterion 5 (Admin only), threats T-11-67 and T-11-69: a Partner meets nothing of the
 * card-payment machinery of either client on any panel route, page, notification, Livewire snapshot or global search.
 * Each of two clients owns documents with a link in every state and card payment records of every type (matched,
 * matched and deleted, duplicate, unmatched for every reason, refund, resolved with a note), every one with its own
 * Stripe references and, for the resolved one, a note canary. The values are read back from the database, so no
 * column is missed. The only Partner-visible addition is "Zaplatit kartou" on an own payable document. The pages are
 * matched as plain text, the Livewire snapshots included (decoded), against the strings of the UI-SPEC "Never on a
 * Partner surface" list. Each refusal is paired with a positive check on the Admin side, so the sweep cannot pass
 * vacuously. Every value is fictional or assembled at runtime.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    config(['app.url' => 'https://kokpit.example.com']);
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
    Mail::getSymfonyTransport()->flush();
    // The test-only resource of the isolation harness is routed in every panel walk and needs its table.
    Canary::createTable();

    $this->clientA = CardPaymentSetup::client();
    $this->clientB = CardPaymentSetup::client();
    $this->canaryA = Canary::canary('cpa');
    $this->canaryB = Canary::canary('cpb');

    // The jobs run first (sync) for the documents with a settled link; the pending ones are issued last, under a fake queue.
    $this->a = cpLeakDocuments($this, $this->clientA);
    $this->b = cpLeakDocuments($this, $this->clientB);

    Queue::fake();
    $this->a['pending'] = CardPaymentSetup::issued($this->clientA);
    $this->b['pending'] = CardPaymentSetup::issued($this->clientB);

    // The real (synchronous) queue is back for whatever the test dispatches next.
    Queue::clearResolvedInstance('queue');
    app()->forgetInstance('queue');

    $this->recordsA = cpLeakRecords($this->a, $this->canaryA);
    $this->recordsB = cpLeakRecords($this->b, $this->canaryB);

    $this->partnerA = Canary::partnerFor($this->clientA->id);
});

/**
 * The documents of one client whose link is active, failed, deactivated by the Admin or ended by a full payment.
 *
 * @return array<string, Invoice>
 */
function cpLeakDocuments(object $test, Client $client): array
{
    $docs = ['active' => CardPaymentSetup::issued($client)];

    $test->stripe->failNextCreate('invalid_request');
    $docs['failed'] = CardPaymentSetup::issued($client);

    $docs['deactivated'] = CardPaymentSetup::issued($client);
    app(DeactivatePaymentLink::class)->handle($test->admin, $docs['deactivated']);

    $docs['cancelled'] = CardPaymentSetup::issued($client);
    app(IssueCreditNote::class)->storno($test->admin, $docs['cancelled']);

    // The documents the records hang off (a record names a document; the fixtures do not touch the link).
    $docs['booked'] = CardPaymentSetup::issued($client);
    $docs['paid'] = CardPaymentSetup::issued($client);

    return $docs;
}

/**
 * Card payment records of every type for one client, each with Stripe references that carry the client's canary.
 *
 * @param  array<string, Invoice>  $docs
 * @return array<string, CardPayment>
 */
function cpLeakRecords(array $docs, string $canary): array
{
    $refs = static fn (string $kind): array => [
        'stripe_event_id' => StripeEvents::token('evt', $canary, $kind),
        'stripe_session_id' => StripeEvents::token('cs', 'test', $canary, $kind),
        'stripe_payment_intent_id' => StripeEvents::token('pi', $canary, $kind),
        'stripe_link_id' => StripeEvents::token('plink', $canary, $kind),
    ];

    return [
        'matched' => CardPaymentFixtures::matched($docs['booked'], $refs('matched')),
        'matched_deleted' => CardPaymentFixtures::matchedThenDeleted($docs['paid'], $refs('deleted')),
        'duplicate' => CardPaymentFixtures::duplicate($docs['paid'], $refs('duplicate')),
        'amount' => CardPaymentFixtures::unmatched($docs['active'], $refs('amount')),
        'currency' => CardPaymentFixtures::unmatched($docs['active'], [...$refs('currency'), 'reason' => CardPaymentReason::Currency]),
        'cancelled' => CardPaymentFixtures::unmatched($docs['cancelled'], [...$refs('cancelled'), 'reason' => CardPaymentReason::Cancelled]),
        'undetermined' => CardPaymentFixtures::unmatched(null, $refs('undetermined')),
        'refund' => CardPaymentFixtures::refund($docs['booked'], $refs('refund')),
        'refund_loose' => CardPaymentFixtures::refund(null, $refs('refundloose')),
        'resolved' => CardPaymentFixtures::resolved($docs['active'], $refs('resolved'), 'Note '.$canary),
    ];
}

/**
 * The strings of the UI-SPEC "Never on a Partner surface" list that belong to the card-payment machinery.
 *
 * @return list<string>
 */
function cpLeakForbidden(): array
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
function cpLeakSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Every Stripe reference, resolution note, link value and row id of both clients, read back from the database, as the
 * strings no Partner may meet.
 *
 * @return list<string>
 */
function cpLeakValues(): array
{
    return cpLeakSystem(static function (): array {
        $values = [];

        foreach (CardPayment::query()->get() as $record) {
            foreach ([
                $record->id, $record->stripe_event_id, $record->stripe_session_id, $record->stripe_payment_intent_id, $record->stripe_link_id,
                $record->stripe_refund_id, $record->resolution_note,
            ] as $value) {
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                }
            }
        }

        foreach (PaymentLink::query()->get() as $link) {
            foreach ([$link->id, $link->stripe_link_id, $link->stripe_url, $link->failure_class] as $value) {
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    });
}

/**
 * The pay token and pay URL of each document.
 *
 * @param  list<Invoice>  $documents
 * @return list<string>
 */
function cpLeakAddresses(array $documents): array
{
    $values = [];

    foreach ($documents as $document) {
        $token = (string) cpLeakSystem(static fn (): mixed => InvoiceAdmin::query()->where('invoice_id', $document->id)->value('pay_token'));

        if ($token !== '') {
            array_push($values, $token, PayUrl::for($token));
        }
    }

    return $values;
}

/**
 * The text a browser or a script could read from a response: the HTML, and the decoded Livewire snapshots.
 */
function cpLeakSurface(string $html): string
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
function cpLeakAssertAbsent(string $html, string $where, array $strings): void
{
    $surface = cpLeakSurface($html);

    foreach ($strings as $text) {
        foreach (array_unique([$text, trim((string) json_encode($text), '"'), e($text)]) as $form) {
            $position = strpos($surface, $form);

            expect($position)->toBeFalse("{$where} must not hold \"{$text}\" (".($position === false ? '' : substr($surface, max(0, $position - 80), 200)).')');
        }
    }
}

/**
 * Every GET route of the admin panel except the guest-only authentication routes.
 *
 * @return list<LaravelRoute>
 */
function cpLeakRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName() ?? '';

        if (! str_starts_with($name, 'filament.admin.') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (in_array($name, ['filament.admin.auth.login', 'filament.admin.auth.logout'], true)) {
            continue;
        }

        $routes[] = $route;
    }

    usort($routes, static fn (LaravelRoute $a, LaravelRoute $b): int => strcmp((string) $a->getName(), (string) $b->getName()));

    return $routes;
}

/**
 * The URL of a walked route: the card payment resources get the card payment id, the invoice resources the document
 * id, any other parameter a fresh random id (which no route can serve, and none may leak on).
 *
 * @param  array{card: string, invoice: string}  $ids
 */
function cpLeakUrl(LaravelRoute $route, array $ids): string
{
    $parameters = [];

    foreach ($route->parameterNames() as $parameter) {
        $name = (string) $route->getName();

        $parameters[$parameter] = match (true) {
            $parameter === 'record' && str_contains($name, '.resources.card-payments.') => $ids['card'],
            $parameter === 'record' && (str_contains($name, '.resources.invoices.') || str_contains($name, '.resources.my-invoices.')) => $ids['invoice'],
            default => (string) Str::uuid(),
        };
    }

    return route((string) $route->getName(), $parameters);
}

/**
 * The messages the array transport holds, as Symfony e-mails.
 *
 * @return list<Email>
 */
function cpLeakMails(): array
{
    $messages = [];

    foreach (Mail::getSymfonyTransport()->messages() as $message) {
        assert($message instanceof SentMessage);
        $original = $message->getOriginalMessage();
        assert($original instanceof Email);
        $messages[] = $original;
    }

    return $messages;
}

describe('proves the fixtures exist: every type, reason and link state is there for the Admin', function (): void {
    it('holds a record of every type and reason, a note canary and a link in every state for both clients', function (): void {
        foreach ([[$this->a, $this->recordsA, $this->canaryA], [$this->b, $this->recordsB, $this->canaryB]] as [$docs, $records, $canary]) {
            $types = cpLeakSystem(static fn (): array => CardPayment::query()->whereIn('id', array_map(static fn (CardPayment $r): string => $r->id, $records))
                ->get()->map(static fn (CardPayment $r): string => $r->type->value.':'.($r->reason?->value ?? '-'))->unique()->sort()->values()->all());

            expect($types)->toBe([
                'duplicate:already_paid', 'matched:-', 'refund:-', 'unmatched:amount', 'unmatched:cancelled', 'unmatched:currency', 'unmatched:undetermined',
            ]);

            $note = cpLeakSystem(static fn (): ?string => CardPayment::query()->findOrFail($records['resolved']->id)->resolution_note);

            $states = cpLeakSystem(static fn (): array => array_map(
                static fn (Invoice $invoice): ?string => PaymentLink::latestFor($invoice->id)?->state->value,
                [$docs['active'], $docs['failed'], $docs['deactivated'], $docs['pending']],
            ));

            expect($states)->toBe(['active', 'failed', 'deactivated', 'pending'])
                ->and($note)->toBe('Note '.$canary);
        }

        expect(count(cpLeakValues()))->toBeGreaterThan(40);
    });

    it('lets the Admin open the record and the overview of both clients', function (): void {
        $this->actingAs($this->admin);

        foreach ([$this->recordsA, $this->recordsB] as $records) {
            $this->get(CardPaymentResource::getUrl('view', ['record' => $records['resolved']]))->assertOk();
        }

        $list = Livewire::test(ListCardPayments::class)->set('activeTab', CardPaymentTab::All->value);

        $list->assertCanSeeTableRecords([$this->recordsA['duplicate'], $this->recordsB['duplicate'], $this->recordsA['refund'], $this->recordsB['resolved']]);

        Livewire::test(ViewCardPayment::class, ['record' => $this->recordsB['resolved']->getRouteKey()])->assertSee('Note '.$this->canaryB);
    });
});

describe('a walk of every panel route', function (): void {
    it('shows Partner A nothing of the card payments of either client on any route, asked with the records of client B', function (): void {
        $this->actingAs($this->partnerA);

        $hidden = [...cpLeakForbidden(), ...cpLeakValues(), ...cpLeakAddresses(array_values($this->b))];
        $ids = ['card' => $this->recordsB['resolved']->id, 'invoice' => $this->b['active']->id];
        $walked = 0;

        foreach (cpLeakRoutes() as $route) {
            $url = cpLeakUrl($route, $ids);
            $response = $this->get($url);

            expect($response->getStatusCode())->toBeIn([200, 302, 403, 404], "{$url} answered {$response->getStatusCode()}");

            if (in_array('record', $route->parameterNames(), true) && str_contains((string) $route->getName(), '.resources.card-payments.')) {
                expect($response->getStatusCode())->toBe(403, "{$url} must be refused");
            }

            if (in_array('record', $route->parameterNames(), true) && str_contains((string) $route->getName(), '.resources.my-invoices.')) {
                expect($response->getStatusCode())->toBe(404, "{$url} must not exist for client A");
            }

            cpLeakAssertAbsent((string) $response->getContent(), $url, $hidden);
            $walked++;
        }

        expect($walked)->toBeGreaterThan(10);
    });

    it('shows Partner A nothing of the card payments on any route, asked with the own records, and the button only on a payable own document', function (): void {
        $this->actingAs($this->partnerA);

        $hidden = [...cpLeakForbidden(), ...cpLeakValues(), ...cpLeakAddresses(array_values($this->b))];
        $payable = PartnerInvoiceResource::getUrl('view', ['record' => $this->a['active']]);

        foreach (cpLeakRoutes() as $route) {
            $url = cpLeakUrl($route, ['card' => $this->recordsA['resolved']->id, 'invoice' => $this->a['active']->id]);
            $response = $this->get($url);

            expect($response->getStatusCode())->toBeIn([200, 302, 403, 404], "{$url} answered {$response->getStatusCode()}");

            if (str_contains((string) $route->getName(), '.resources.card-payments.')) {
                $response->assertForbidden();
            }

            $body = (string) $response->getContent();

            // The own pay URL is the one thing the Partner may read, and only on the own payable detail.
            $own = $url === $payable ? [] : cpLeakAddresses([$this->a['active']]);

            cpLeakAssertAbsent($body, $url, [...$hidden, ...$own]);

            if ($url === $payable) {
                expect($response->getStatusCode())->toBe(200)
                    ->and($body)->toContain('Zaplatit kartou');
            } else {
                expect($body)->not->toContain('Zaplatit kartou');
            }
        }
    });

    it('shows a Partner without a client nothing of the card payments of either client on any route', function (): void {
        $this->actingAs(Canary::partnerFor(null));

        $hidden = [...cpLeakForbidden(), ...cpLeakValues(), 'Zaplatit kartou', '/pay/', ...cpLeakAddresses([...array_values($this->a), ...array_values($this->b)])];

        foreach (cpLeakRoutes() as $route) {
            $url = cpLeakUrl($route, ['card' => $this->recordsA['resolved']->id, 'invoice' => $this->a['active']->id]);
            $response = $this->get($url);

            expect($response->getStatusCode())->toBeIn([200, 302, 403, 404], "{$url} answered {$response->getStatusCode()}");

            cpLeakAssertAbsent((string) $response->getContent(), $url, $hidden);
        }
    });

    it('serves the Admin the card payment routes that the Partner is refused, so the refusal comes from the policy', function (): void {
        $this->actingAs($this->admin);

        $this->get(CardPaymentResource::getUrl('index'))->assertOk();

        foreach ([$this->recordsA, $this->recordsB] as $records) {
            $this->get(CardPaymentResource::getUrl('view', ['record' => $records['duplicate']]))->assertOk();
        }
    });
});

describe('Livewire, search, dashboard and bell', function (): void {
    it('refuses the payments overview and the record page to a Partner, and the unresolved card is not offered', function (): void {
        $this->actingAs($this->partnerA);

        Livewire::test(ListCardPayments::class)->assertForbidden();
        Livewire::test(ViewCardPayment::class, ['record' => $this->recordsA['duplicate']->getRouteKey()])->assertForbidden();
        Livewire::test(ViewCardPayment::class, ['record' => $this->recordsB['duplicate']->getRouteKey()])->assertForbidden();

        expect(UnresolvedPayments::canView())->toBeFalse();

        $this->actingAs($this->admin);

        expect(UnresolvedPayments::canView())->toBeTrue();
    });

    it('lists only own documents in the Partner invoice table, searched or not, and finds none through a Stripe reference', function (): void {
        $this->actingAs($this->partnerA);

        $own = [$this->a['active'], $this->a['failed'], $this->a['deactivated'], $this->a['paid']];
        $foreign = [$this->b['active'], $this->b['failed'], $this->b['deactivated'], $this->b['paid']];

        $component = Livewire::test(ListPartnerInvoices::class)->assertCanSeeTableRecords($own)->assertCanNotSeeTableRecords($foreign);

        cpLeakAssertAbsent($component->html(), 'the Partner invoice table', [...cpLeakForbidden(), ...cpLeakValues(), ...cpLeakAddresses($foreign)]);

        // The Partner table has no searchable column by design; a search by a Stripe reference or a note changes nothing it shows.
        foreach ([$this->recordsA['resolved']->stripe_event_id, $this->recordsB['resolved']->stripe_session_id, 'Note '.$this->canaryA, $this->canaryB] as $term) {
            Livewire::test(ListPartnerInvoices::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords($own)
                ->assertCanNotSeeTableRecords($foreign);
        }

        // Both own detail pages render through the component as well.
        foreach ($own as $document) {
            $detail = Livewire::test(ViewPartnerInvoice::class, ['record' => $document->getRouteKey()]);

            cpLeakAssertAbsent($detail->html(), 'the Partner detail component', [...cpLeakForbidden(), ...cpLeakValues(), ...cpLeakAddresses($foreign)]);
        }
    });

    it('finds no card payment for a Partner in the global search, by reference, by note or by canary, and no resource opts in', function (): void {
        $this->actingAs($this->partnerA);

        $terms = [
            $this->recordsA['resolved']->stripe_event_id, $this->recordsA['duplicate']->stripe_payment_intent_id, $this->recordsB['refund']->stripe_refund_id,
            'Note '.$this->canaryA, 'Note '.$this->canaryB, $this->canaryA, $this->canaryB, 'plink', 'Stripe',
        ];

        foreach ($terms as $term) {
            $categories = Filament::getCurrentOrDefaultPanel()->getGlobalSearchProvider()?->getResults((string) $term)?->getCategories();

            expect($categories === null || $categories->isEmpty())->toBeTrue("global search for {$term}");
        }

        expect(CardPaymentResource::canGloballySearch())->toBeFalse();

        // The Admin finds them in the overview, filtered by client: the sweep above is not vacuous.
        $this->actingAs($this->admin);

        Livewire::test(ListCardPayments::class)
            ->set('activeTab', CardPaymentTab::All->value)
            ->filterTable('client_id', $this->clientB->id)
            ->assertCanSeeTableRecords([$this->recordsB['duplicate'], $this->recordsB['resolved']])
            ->assertCanNotSeeTableRecords([$this->recordsA['duplicate'], $this->recordsA['resolved']]);
    });

    it('keeps the dashboard of a Partner free of the card payments, which the Admin dashboard shows', function (): void {
        // The Partner asks first: the panel remembers the navigation it built for the first request of a process.
        $this->actingAs($this->partnerA);

        $html = (string) $this->get('/admin')->assertOk()->getContent();

        cpLeakAssertAbsent($html, 'the Partner dashboard', [...cpLeakForbidden(), ...cpLeakValues(), 'Nevyřešené platby', 'Zaplatit kartou', ...cpLeakAddresses(array_values($this->b))]);

        $this->actingAs($this->admin);

        expect(UnresolvedPayments::canView())->toBeTrue()
            ->and(Livewire::test(UnresolvedPayments::class)->assertSuccessful()->html())->toContain('Nevyřešené platby');
    });
});

describe('the five notices', function (): void {
    it('reaches every Admin and no Partner, in the bell, by mail and in the bell component', function (): void {
        $this->stripe = FakeStripeGateway::install();
        $this->actingAs($this->admin);
        $secondAdmin = Canary::admin();

        $partnerB = Canary::partnerFor($this->clientB->id);
        $partnerNone = Canary::partnerFor(null);
        $partners = [$this->partnerA, $partnerB, $partnerNone];

        Mail::getSymfonyTransport()->flush();

        // 1. The link of a new document cannot be created.
        $this->stripe->failNextCreate('invalid_request');
        CardPaymentSetup::issued($this->clientA);

        // 2. A payment of a document with an active link is booked; 3. a second one is a duplicate.
        $payable = CardPaymentSetup::issued($this->clientA);
        $link = cpLeakSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $payable->id)->firstOrFail());

        $matched = StripeEvents::checkoutFor($link);
        StripeWebhookSigner::post($this, $matched)->assertOk();
        StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

        // 4. A payment of another amount than the link offered is unmatched.
        $other = CardPaymentSetup::issued($this->clientA);
        $otherLink = cpLeakSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $other->id)->firstOrFail());
        StripeWebhookSigner::post($this, StripeEvents::checkoutFor($otherLink, ['amount_total' => 1_200_000]))->assertOk();

        // 5. A refund of the first payment is reported.
        StripeWebhookSigner::post($this, StripeEvents::refundCreated((string) $matched['data']['object']['payment_intent'], 500_000))->assertOk();

        $titles = [
            'Odkaz na platbu kartou se nepodařilo vytvořit', 'Přišla platba kartou', 'Duplicitní platba kartou',
            'Nespárovaná platba kartou', 'Stripe oznámil vrácení platby',
        ];

        // The Admins were told, so the refusal below is not vacuous.
        foreach ([$this->admin, $secondAdmin] as $admin) {
            $bell = DB::table('notifications')->where('notifiable_id', $admin->getKey())->get()
                ->map(static fn (object $row): string => (string) (json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR)['title'] ?? ''))->all();

            expect($bell)->toContain(...$titles);
        }

        $addresses = array_map(static fn (Email $mail): string => $mail->getTo()[0]->getAddress(), cpLeakMails());

        expect($addresses)->toContain($this->admin->email, $secondAdmin->email);

        foreach ($partners as $partner) {
            $this->actingAs($partner);

            $encoded = (string) json_encode(User::query()->whereKey($partner->getKey())->firstOrFail()->notifications()->get()->pluck('data'));
            $bellHtml = Livewire::test(DatabaseNotifications::class)->html();

            expect($partner->notifications()->count())->toBe(0)
                ->and($addresses)->not->toContain($partner->email)
                ->and(DB::table('notifications')->where('notifiable_id', $partner->getKey())->count())->toBe(0);

            cpLeakAssertAbsent($encoded.$bellHtml, 'the bell of '.$partner->getKey(), [...$titles, ...cpLeakForbidden(), ...cpLeakValues()]);
        }
    });
});

describe('the public routes', function (): void {
    it('registers exactly two routes outside the web group, without authentication: pay.show and webhooks.stripe', function (): void {
        $source = (string) file_get_contents(base_path('routes/public.php'));

        preg_match_all("/->name\('([^']+)'\)/", $source, $names);
        preg_match_all('/^Route::[a-z]+\(/m', $source, $declarations);

        expect($names[1])->toEqualCanonicalizing(['pay.show', 'webhooks.stripe'])
            ->and($declarations[0])->toHaveCount(2);

        foreach (['pay.show' => 'GET', 'webhooks.stripe' => 'POST'] as $name => $method) {
            $route = Route::getRoutes()->getByName($name);

            expect($route)->not->toBeNull();
            assert($route instanceof LaravelRoute);

            $middleware = $route->gatherMiddleware();

            expect($route->methods())->toContain($method)
                ->and($middleware)->not->toContain('web')
                ->and($middleware)->not->toContain('api')
                ->and(array_filter($middleware, static fn (mixed $m): bool => is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'StartSession') || str_contains($m, 'ValidateCsrfToken'))))->toBe([])
                ->and(array_filter($middleware, static fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'throttle:')))->not->toBe([]);
        }
    });

    it('sends a guest who asks for the payments overview or a record to the login', function (): void {
        app('auth')->forgetGuards();

        $this->get(CardPaymentResource::getUrl('index'))->assertRedirectContains('/admin/login');
        $this->get(CardPaymentResource::getUrl('view', ['record' => $this->recordsA['duplicate']]))->assertRedirectContains('/admin/login');
        $this->get('/admin/card-payments')->assertRedirectContains('/admin/login');
    });
});
