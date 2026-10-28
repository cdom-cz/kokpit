<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\UpdateClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Support\ClientDocumentEmails;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * The domain gate of the per-client e-mail switch (Phase 16, D-06, D-07; P16-01). It reads the column fresh on every
 * call and answers false, "do not send", for a row it cannot read. Every client, name and address is fictional.
 */

beforeEach(function (): void {
    $this->actingAs(Canary::admin());
});

/**
 * The data of an update that keeps everything but the switch.
 *
 * @return array<string, mixed>
 */
function clientDocEmailsUpdate(Client $client, bool $enabled): array
{
    return [
        'name' => $client->name,
        'country' => $client->country,
        'stage' => $client->stage->value,
        'currency' => $client->currency,
        'hourly_rate' => '0',
        'payment_terms_days' => $client->payment_terms_days,
        'invoice_language' => $client->invoice_language->value,
        'document_emails_enabled' => $enabled,
    ];
}

it('answers false for a client with the switch off and true for another', function (): void {
    $off = InvoiceFixtures::client(['document_emails_enabled' => false]);
    $on = InvoiceFixtures::client();

    expect(ClientDocumentEmails::enabledFor($off->id))->toBeFalse()
        ->and(ClientDocumentEmails::enabledFor($on->id))->toBeTrue();
});

it('reads the switch fresh and never from a stale model', function (): void {
    $client = InvoiceFixtures::client(['document_emails_enabled' => false]);

    app(UpdateClient::class)->handle($client, clientDocEmailsUpdate($client, true));

    // The in-memory model still carries the old value; the gate reads the database.
    expect($client->document_emails_enabled)->toBeFalse()
        ->and(ClientDocumentEmails::enabledFor($client->id))->toBeTrue();

    app(UpdateClient::class)->handle($client, clientDocEmailsUpdate($client, false));

    expect(ClientDocumentEmails::enabledFor($client->id))->toBeFalse();
});

it('still reads an archived client', function (): void {
    $on = InvoiceFixtures::client();
    $off = InvoiceFixtures::client(['document_emails_enabled' => false]);

    $on->delete();
    $off->delete();

    expect(ClientDocumentEmails::enabledFor($on->id))->toBeTrue()
        ->and(ClientDocumentEmails::enabledFor($off->id))->toBeFalse();
});

it('fails closed for an unknown client', function (): void {
    expect(ClientDocumentEmails::enabledFor((string) Str::uuid7()))->toBeFalse();
});

it('follows the client of an issued invoice and answers false for an unknown invoice', function (): void {
    $on = InvoiceFixtures::issued(InvoiceFixtures::client());
    $off = InvoiceFixtures::issued(InvoiceFixtures::client(['document_emails_enabled' => false]));

    expect(ClientDocumentEmails::enabledForInvoice($on->id))->toBeTrue()
        ->and(ClientDocumentEmails::enabledForInvoice($off->id))->toBeFalse()
        ->and(ClientDocumentEmails::enabledForInvoice((string) Str::uuid7()))->toBeFalse();
});

it('allows only a client model that carries the switch on', function (): void {
    $on = InvoiceFixtures::client();
    $off = InvoiceFixtures::client(['document_emails_enabled' => false]);

    $freshOn = Client::query()->whereKey($on->id)->firstOrFail();
    $freshOff = Client::query()->whereKey($off->id)->firstOrFail();

    expect(ClientDocumentEmails::allows(null))->toBeFalse()
        ->and(ClientDocumentEmails::allows($freshOn))->toBeTrue()
        ->and(ClientDocumentEmails::allows($freshOff))->toBeFalse()
        ->and(ClientDocumentEmails::allows($freshOn))->toBe($freshOn->document_emails_enabled);
});
