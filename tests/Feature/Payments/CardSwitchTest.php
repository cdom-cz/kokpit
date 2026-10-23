<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Pages\SettingsPage;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;
use Tests\Support\StripeTestConfig;

/*
 * The per-document card switch and the stable pay token (PA-03, PA-02; D-01, D-02, D-05, D-06). Every name and amount
 * is fictional; the pay token is random per run and never written down in a test.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config(['app.url' => 'https://kokpit.example.com']);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * The Admin-only row of a document as the raw database holds it.
 */
function adminRowOf(Invoice $invoice): stdClass
{
    return DB::table('invoice_admin')->where('invoice_id', $invoice->id)->first() ?? throw new LogicException('No Admin row.');
}

it('gives a draft of a client with online payment the card switch on and keeps it frozen with a stable pay URL at issue', function (): void {
    CardPaymentSetup::enable();
    $client = CardPaymentSetup::client();

    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    expect(adminRowOf($draft)->card_payment_enabled)->toBeTrue()
        ->and(adminRowOf($draft)->pay_token)->toBeNull();

    $issued = CardPaymentSetup::issued($client);
    $row = adminRowOf($issued);

    expect($row->card_payment_enabled)->toBeTrue()
        ->and($row->pay_token)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and(PayToken::isWellFormed($row->pay_token))->toBeTrue();

    $url = app(PartnerContext::class)->runAsSystem(static fn (): ?string => PayUrl::forInvoice(Invoice::query()->whereKey($issued->id)->firstOrFail()));

    expect($url)->toBe('https://kokpit.example.com/pay/'.$row->pay_token)
        ->and(strlen((string) $url))->toBeLessThanOrEqual(80);
});

it('builds the pay URL from the configured address and never from the host of the request', function (): void {
    $token = PayToken::generate();

    app()->instance('request', Request::create('https://attacker.example.net/anything', 'GET', [], [], [], ['HTTP_HOST' => 'attacker.example.net']));

    expect(PayUrl::for($token))->toBe('https://kokpit.example.com/pay/'.$token);

    config(['app.url' => 'https://kokpit.example.com/']);

    expect(PayUrl::for($token))->toBe('https://kokpit.example.com/pay/'.$token);
});

it('generates tokens of 32 letters and digits that differ and recognises a malformed one', function (): void {
    $tokens = array_map(static fn (): string => PayToken::generate(), range(1, 20));

    expect(array_unique($tokens))->toHaveCount(20);

    foreach ($tokens as $token) {
        expect($token)->toMatch('/^[A-Za-z0-9]{32}$/');
    }

    expect(PayToken::isWellFormed(str_repeat('a', 31)))->toBeFalse()
        ->and(PayToken::isWellFormed(str_repeat('a', 33)))->toBeFalse()
        ->and(PayToken::isWellFormed(str_repeat('a', 31).'-'))->toBeFalse()
        ->and(PayToken::isWellFormed(str_repeat('a', 32)))->toBeTrue();
});

it('lets the switch move on an open issued document, never changes a set token, and freezes both once locked', function (): void {
    CardPaymentSetup::enable();
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    $token = adminRowOf($issued)->pay_token;

    // Open (OA-1): the switch moves in both directions and the token stays what it was printed as.
    RawSql::expectAllowed(static fn (): int => DB::update('UPDATE invoice_admin SET card_payment_enabled = false WHERE invoice_id = ?', [$issued->id]));
    RawSql::expectAllowed(static fn (): int => DB::update('UPDATE invoice_admin SET card_payment_enabled = true WHERE invoice_id = ?', [$issued->id]));
    RawSql::expectSqlState('KP001', static fn (): int => DB::update('UPDATE invoice_admin SET pay_token = ? WHERE invoice_id = ?', [PayToken::generate(), $issued->id]));
    RawSql::expectSqlState('KP001', static fn (): int => DB::update('UPDATE invoice_admin SET pay_token = NULL WHERE invoice_id = ?', [$issued->id]));

    // Locked: the switch and the token are frozen with the document.
    InvoiceFixtures::lock($issued);

    RawSql::expectSqlState('KP001', static fn (): int => DB::update('UPDATE invoice_admin SET card_payment_enabled = false WHERE invoice_id = ?', [$issued->id]));
    RawSql::expectSqlState('KP001', static fn (): int => DB::update('UPDATE invoice_admin SET pay_token = ? WHERE invoice_id = ?', [PayToken::generate(), $issued->id]));
    RawSql::expectSqlState('KP001', static fn (): int => DB::update('UPDATE invoice_admin SET pay_token = NULL WHERE invoice_id = ?', [$issued->id]));

    expect(adminRowOf($issued)->pay_token)->toBe($token);
});

it('never writes the pay token or its owner row to the activity log', function (): void {
    CardPaymentSetup::enable();
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    $token = (string) adminRowOf($issued)->pay_token;

    $rows = app(PartnerContext::class)->runAsSystem(static fn (): array => Activity::query()
        ->where('subject_type', 'invoice_admin')
        ->get()
        ->map(static fn (Activity $activity): string => json_encode($activity->getAttributes(), JSON_THROW_ON_ERROR))
        ->all());

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $json) {
        expect($json)->not->toContain($token)->not->toContain('pay_token');
    }
});

it('leaves a document issued with the global setting off without a switch and without a token', function (): void {
    CardPaymentSetup::enable();
    $client = CardPaymentSetup::client();
    CardPaymentSetup::onlinePayments(false);

    $issued = CardPaymentSetup::issued($client);

    expect(adminRowOf($issued)->card_payment_enabled)->toBeFalse()
        ->and(adminRowOf($issued)->pay_token)->toBeNull();
});

it('gives a draft created for a client without online payment the switch off', function (): void {
    CardPaymentSetup::enable();
    $client = InvoiceFixtures::client(['online_payment_enabled' => false]);

    $admin = Canary::admin();
    $this->actingAs($admin);

    $draft = app(CreateDraft::class)->handle($admin, ['client_id' => $client->id, 'kind' => InvoiceKind::Proforma->value]);

    expect(adminRowOf($draft)->card_payment_enabled)->toBeFalse();
});

/**
 * The card switch of a freshly created draft after the Admin's override, issued; returns the Admin row.
 */
function issueWithOverride(bool $clientOnline, ?bool $override): stdClass
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    $client = InvoiceFixtures::client(['online_payment_enabled' => $clientOnline]);
    $draft = app(CreateDraft::class)->handle($admin, ['client_id' => $client->id, 'kind' => InvoiceKind::Invoice->value]);
    $data = ['items' => [InvoiceFixtures::item('Example work', '1', '2400.00')]];

    if ($override !== null) {
        $data['card_payment_enabled'] = $override;
    }

    $draft = app(UpdateDraft::class)->handle($admin, $draft, $data);

    return adminRowOf(app(IssueDocument::class)->handle($admin, $draft));
}

/**
 * A credit-note draft of the client, built as system with the only writes the schema allows (as the storno fixture does).
 */
function creditNoteDraftOf(Client $client): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($client): Invoice {
        $source = InvoiceFixtures::issued($client);
        $credit = new Invoice;
        $credit->forceFill([
            'client_id' => $client->id,
            'kind' => InvoiceKind::CreditNote,
            'currency' => $source->currency,
            'language' => $source->language,
            'vat_mode' => $source->vat_mode,
            'credited_invoice_id' => $source->id,
            'cancels_invoice' => false,
            'subtotal_minor' => -100,
            'vat_minor' => 0,
            'total_minor' => -100,
        ])->save();

        return $credit;
    });
}

it('takes the Admin override of the draft over the client value, in both directions (D-06, A8)', function (): void {
    CardPaymentSetup::enable();

    $on = issueWithOverride(clientOnline: false, override: true);
    expect($on->card_payment_enabled)->toBeTrue()
        ->and($on->pay_token)->toMatch('/^[A-Za-z0-9]{32}$/');

    $off = issueWithOverride(clientOnline: true, override: false);
    expect($off->card_payment_enabled)->toBeFalse()
        ->and($off->pay_token)->toBeNull();

    $kept = issueWithOverride(clientOnline: true, override: null);
    expect($kept->card_payment_enabled)->toBeTrue();
});

it('freezes false and no token when Stripe is not configured or only one secret is set (U-4)', function (): void {
    CardPaymentSetup::enable();
    $client = CardPaymentSetup::client();

    StripeTestConfig::clear();
    $none = adminRowOf(CardPaymentSetup::issued($client));

    StripeTestConfig::configure();
    config(['services.stripe.webhook_secret' => null]);
    $keyOnly = adminRowOf(CardPaymentSetup::issued($client));

    StripeTestConfig::configure();
    config(['services.stripe.secret' => null]);
    $hookOnly = adminRowOf(CardPaymentSetup::issued($client));

    foreach ([$none, $keyOnly, $hookOnly] as $row) {
        expect($row->card_payment_enabled)->toBeFalse()->and($row->pay_token)->toBeNull();
    }
});

it('treats a proforma like an invoice', function (): void {
    CardPaymentSetup::enable();

    $proforma = CardPaymentSetup::issued(CardPaymentSetup::client(), InvoiceKind::Proforma);

    expect(adminRowOf($proforma)->card_payment_enabled)->toBeTrue()
        ->and(adminRowOf($proforma)->pay_token)->toMatch('/^[A-Za-z0-9]{32}$/');
});

it('gives a storno credit note and the invoice converted from a card proforma no switch and no token (D-05)', function (): void {
    CardPaymentSetup::enable();
    $admin = Canary::admin();
    $client = CardPaymentSetup::client();

    $invoice = CardPaymentSetup::issued($client);
    expect(adminRowOf($invoice)->pay_token)->not->toBeNull();

    $credit = app(IssueCreditNote::class)->storno($admin, $invoice);
    expect($credit->kind)->toBe(InvoiceKind::CreditNote)
        ->and(adminRowOf($credit)->card_payment_enabled)->toBeFalse()
        ->and(adminRowOf($credit)->pay_token)->toBeNull();

    $proforma = CardPaymentSetup::issued($client, InvoiceKind::Proforma, '3900.00');
    expect(adminRowOf($proforma)->pay_token)->not->toBeNull();

    app(RecordPayment::class)->handle($admin, $proforma, ['amount' => '3900.00', 'paid_on' => '2026-10-14']);
    $converted = app(ConvertProforma::class)->handle($admin, $proforma);

    expect($converted->kind)->toBe(InvoiceKind::Invoice)
        ->and(adminRowOf($converted)->card_payment_enabled)->toBeFalse()
        ->and(adminRowOf($converted)->pay_token)->toBeNull();
});

it('refuses the switch on a credit-note draft and on a value that is not a boolean', function (): void {
    CardPaymentSetup::enable();
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = CardPaymentSetup::client();
    $draft = app(CreateDraft::class)->handle($admin, ['client_id' => $client->id, 'kind' => InvoiceKind::Invoice->value]);

    foreach (['1', 1, 'true', null, []] as $value) {
        try {
            app(UpdateDraft::class)->handle($admin, $draft, ['card_payment_enabled' => $value]);
            $this->fail('A non-boolean switch was accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('card_payment_enabled')
                ->and($e->errors()['card_payment_enabled'][0])->toBe(__('invoices.errors.card_payment_invalid'));
        }
    }

    $credit = creditNoteDraftOf($client);

    expect(fn () => app(UpdateDraft::class)->handle($admin, $credit, ['card_payment_enabled' => true]))
        ->toThrow(ValidationException::class);
    expect(DB::table('invoice_admin')->where('invoice_id', $credit->id)->exists())->toBeFalse();
});

it('answers the existing not-draft refusal for the switch on an issued document', function (): void {
    CardPaymentSetup::enable();
    $admin = Canary::admin();
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());

    expect(fn () => app(UpdateDraft::class)->handle($admin, $issued, ['card_payment_enabled' => false]))
        ->toThrow(DomainException::class, __('invoices.errors.not_draft'));
});

it('creates the Admin row for a draft that has none when the switch is saved', function (): void {
    CardPaymentSetup::enable();
    $admin = Canary::admin();
    $this->actingAs($admin);
    $draft = InvoiceFixtures::draft(CardPaymentSetup::client());
    DB::table('invoice_admin')->where('invoice_id', $draft->id)->delete();

    app(UpdateDraft::class)->handle($admin, $draft, ['card_payment_enabled' => true]);

    expect(adminRowOf($draft)->card_payment_enabled)->toBeTrue();
});

it('refuses a duplicate pay token with a unique violation', function (): void {
    CardPaymentSetup::enable();
    $client = CardPaymentSetup::client();
    $first = adminRowOf(CardPaymentSetup::issued($client));
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

    RawSql::expectSqlState('23505', static fn (): int => DB::update(
        'UPDATE invoice_admin SET card_payment_enabled = true, pay_token = ? WHERE invoice_id = ?',
        [$first->pay_token, $draft->id],
    ));
});

it('refuses a malformed token and accepts a well-formed token with the switch off', function (): void {
    CardPaymentSetup::enable();
    $draft = InvoiceFixtures::draft(CardPaymentSetup::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

    RawSql::expectSqlState('23514', static fn (): int => DB::update('UPDATE invoice_admin SET card_payment_enabled = true, pay_token = ? WHERE invoice_id = ?', [str_repeat('a', 31), $draft->id]));
    RawSql::expectSqlState('23514', static fn (): int => DB::update('UPDATE invoice_admin SET card_payment_enabled = true, pay_token = ? WHERE invoice_id = ?', [str_repeat('a', 31).'-', $draft->id]));

    // A token outlives a switch-off (a printed pay URL keeps answering), so the check is about the format only.
    RawSql::expectAllowed(static fn (): int => DB::update('UPDATE invoice_admin SET card_payment_enabled = false, pay_token = ? WHERE invoice_id = ?', [PayToken::generate(), $draft->id]));
});

/**
 * The editor of a draft, as the Admin.
 */
function cardEditor(Invoice $draft): Testable
{
    return Livewire::test(EditInvoice::class, ['record' => $draft->getRouteKey()]);
}

function storedCardSwitch(Invoice $draft): bool
{
    return (bool) adminRowOf($draft)->card_payment_enabled;
}

describe('the draft editor', function (): void {
    it('shows the toggle on for a client with online payment, with the standing helper, and stores an override', function (): void {
        CardPaymentSetup::enable();
        $draft = InvoiceFixtures::draft(CardPaymentSetup::client(), [InvoiceFixtures::item('Example work', '1', '500.00')]);

        $editor = cardEditor($draft)
            ->assertSee('Platba kartou')
            ->assertSee('Na dokladu a v e-mailu se zobrazí tlačítko Zaplatit kartou. Po vystavení už nejde změnit.')
            ->assertSet('data.card_payment_enabled', true)
            ->assertFormFieldIsEnabled('card_payment_enabled');

        $editor->set('data.card_payment_enabled', false)->call('save')->assertHasNoFormErrors();

        expect(storedCardSwitch($draft))->toBeFalse();

        $editor->set('data.card_payment_enabled', true)->call('save')->assertHasNoFormErrors();

        expect(storedCardSwitch($draft))->toBeTrue();
    });

    it('disables the toggle with its reason when online payments are off in the settings and keeps the stored value', function (): void {
        CardPaymentSetup::enable();
        $draft = InvoiceFixtures::draft(CardPaymentSetup::client(), [InvoiceFixtures::item('Example work', '1', '500.00')]);
        CardPaymentSetup::onlinePayments(false);

        cardEditor($draft)
            ->assertSee('Online platby jsou vypnuté v Nastavení.')
            ->assertDontSee('Po vystavení už nejde změnit.')
            ->assertSet('data.card_payment_enabled', false)
            ->assertFormFieldIsDisabled('card_payment_enabled')
            ->call('save')
            ->assertHasNoFormErrors();

        expect(storedCardSwitch($draft))->toBeTrue();
    });

    it('disables the toggle with its reason when Stripe is not configured', function (): void {
        CardPaymentSetup::enable();
        $draft = InvoiceFixtures::draft(CardPaymentSetup::client(), [InvoiceFixtures::item('Example work', '1', '500.00')]);
        StripeTestConfig::clear();

        cardEditor($draft)
            ->assertSee('Stripe není nastavený. Doplňte klíče v prostředí aplikace.')
            ->assertSet('data.card_payment_enabled', false)
            ->assertFormFieldIsDisabled('card_payment_enabled');
    });

    it('has no toggle on a credit-note draft', function (): void {
        CardPaymentSetup::enable();

        cardEditor(creditNoteDraftOf(CardPaymentSetup::client()))
            ->assertFormFieldIsHidden('card_payment_enabled')
            ->assertDontSee('Na dokladu a v e-mailu se zobrazí tlačítko Zaplatit kartou.');
    });

    it('lists the card payment in the issue confirmation, yes when the frozen value will be on and no otherwise', function (): void {
        CardPaymentSetup::enable();
        $client = CardPaymentSetup::client();
        $on = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '500.00')]);

        cardEditor($on)
            ->mountAction('issue')
            ->assertMountedActionModalSee(['Platba kartou', 'Ano, odkaz se vytvoří po vystavení']);

        $off = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '500.00')]);

        cardEditor($off)
            ->set('data.card_payment_enabled', false)
            ->mountAction('issue')
            ->assertMountedActionModalSee('Platba kartou')
            ->assertMountedActionModalDontSee('Ano, odkaz se vytvoří po vystavení');

        StripeTestConfig::clear();
        $unavailable = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '500.00')]);

        cardEditor($unavailable)
            ->mountAction('issue')
            ->assertMountedActionModalDontSee('Ano, odkaz se vytvoří po vystavení');
    });
});

describe('the settings and the client form', function (): void {
    it('shows the hint of the settings toggle and the warning callout only while online payments are on and Stripe is not configured', function (): void {
        $this->actingAs(Canary::admin());
        $callout = 'Stripe není nastavený (v prostředí chybí klíče). Odkazy na platbu se nevytvoří.';

        CardPaymentSetup::onlinePayments(true);
        StripeTestConfig::clear();

        $page = Livewire::test(SettingsPage::class)
            ->assertSee('Při vystavení faktury nebo zálohové faktury se vytvoří odkaz na platbu kartou přes Stripe. Týká se jen dokladů vystavených později.')
            ->assertSee($callout);

        $page->set('data.payments.online_payments_enabled', false)->assertDontSee($callout);
        $page->set('data.payments.online_payments_enabled', true)->assertSee($callout);

        StripeTestConfig::configure();

        Livewire::test(SettingsPage::class)->assertDontSee($callout);
    });

    it('renders no Stripe key and no field for one on the settings page (D-16)', function (): void {
        $this->actingAs(Canary::admin());
        CardPaymentSetup::enable();

        $page = Livewire::test(SettingsPage::class);
        $html = $page->html();

        expect($html)->not->toContain((string) config('services.stripe.secret'))
            ->and($html)->not->toContain((string) config('services.stripe.webhook_secret'))
            ->and($html)->not->toContain('stripe_secret')
            ->and($html)->not->toContain('webhook_secret');
    });

    it('shows the new hint on the client form toggle', function (): void {
        $this->actingAs(Canary::admin());

        Livewire::test(CreateClient::class)
            ->assertSee('Zapnuto: u dokladů tohoto klienta se nabídne platba kartou. Nový koncept převezme tuto hodnotu a u dokladu ji lze přepsat.');
    });
});

it('blocks the issue of an online draft once card payment is unavailable, and leaves it a draft that can still be saved (P14-09)', function (string $cause): void {
    CardPaymentSetup::enable();
    $admin = Canary::admin();
    $client = CardPaymentSetup::client();
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '2400.00')]);
    $draft = InvoiceFixtures::online($draft);

    match ($cause) {
        'settings_off' => CardPaymentSetup::onlinePayments(false),
        'stripe_missing' => StripeTestConfig::clear(),
        default => app(PartnerContext::class)->runAsSystem(static fn (): int => $client->newQuery()->whereKey($client->id)->update(['online_payment_enabled' => false])),
    };

    $saved = app(UpdateDraft::class)->handle($admin, $draft, ['note' => 'Example note']);

    expect($saved->note)->toBe('Example note')
        ->and($saved->payment_method?->value)->toBe('online');

    try {
        app(IssueDocument::class)->handle($admin, $saved);
        $this->fail('An online draft was issued although card payment is unavailable.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('online_unavailable')
            ->and($e->errors()['online_unavailable'][0])->toBe(__('invoices.errors.online_unavailable'));
    }

    expect($saved->fresh()->isDraft())->toBeTrue();
})->with(['settings_off', 'stripe_missing', 'client_off']);
