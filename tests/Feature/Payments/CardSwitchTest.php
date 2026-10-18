<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

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

it('refuses every change of the switch and the token once the document is issued', function (): void {
    CardPaymentSetup::enable();
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());

    RawSql::expectSqlState('KP001', static fn (): int => DB::update('UPDATE invoice_admin SET card_payment_enabled = false WHERE invoice_id = ?', [$issued->id]));
    RawSql::expectSqlState('KP001', static fn (): int => DB::update('UPDATE invoice_admin SET pay_token = ? WHERE invoice_id = ?', [PayToken::generate(), $issued->id]));
    RawSql::expectSqlState('KP001', static fn (): int => DB::update('UPDATE invoice_admin SET pay_token = NULL WHERE invoice_id = ?', [$issued->id]));
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
