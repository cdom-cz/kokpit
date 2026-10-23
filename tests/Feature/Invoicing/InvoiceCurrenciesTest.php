<?php

declare(strict_types=1);

use App\Domain\Invoicing\Support\InvoiceCurrencies;
use App\Domain\Invoicing\Support\IssuedDocumentRules;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Shared\Auth\PartnerContext;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * The currencies an invoice can be billed in (P14-05; D-07, D-09): the union of what CNB publishes, what the supplier's
 * bank accounts hold, the client's currency, the current one and CZK, one list for every surface. Every name, rate and
 * amount is fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

describe('the union list', function (): void {
    it('joins the CNB, account and client currencies with CZK, once each and sorted', function (): void {
        InvoiceFixtures::configureSupplier();
        InvoiceFixtures::cnbRate('USD', '2026-10-13', '22.500');
        InvoiceFixtures::cnbRate('GBP', '2026-10-13', '29.100');
        InvoiceFixtures::cnbRate('USD', '2026-10-14', '22.600');
        $client = InvoiceFixtures::client();

        expect(InvoiceCurrencies::choices($client, null))->toBe(['CZK', 'EUR', 'GBP', 'USD']);
    });

    it('adds the currency of the client', function (): void {
        InvoiceFixtures::configureSupplier();
        $client = InvoiceFixtures::client(['currency' => 'CHF']);

        expect(InvoiceCurrencies::choices($client, null))->toBe(['CHF', 'CZK', 'EUR']);
    });

    it('adds the current currency of the document when no other source lists it', function (): void {
        InvoiceFixtures::configureSupplier();
        $client = InvoiceFixtures::client();

        expect(InvoiceCurrencies::choices($client, 'JPY'))->toBe(['CZK', 'EUR', 'JPY']);
    });

    it('offers CZK alone when there are no accounts, no rates and no client', function (): void {
        app(PartnerContext::class)->runAsSystem(static function (): void {
            $banks = app(BankAccountSettings::class);
            $banks->accounts = [];
            $banks->save();
        });

        expect(InvoiceCurrencies::choices(null, null))->toBe(['CZK']);
    });

    it('never offers a code the money library cannot format', function (): void {
        InvoiceFixtures::cnbRate('USD', '2026-10-13', '22.500');
        InvoiceFixtures::cnbRate('ZZZ', '2026-10-13', '1.000');

        expect(InvoiceCurrencies::choices(null, 'xxx'))->toContain('USD')->not->toContain('ZZZ')->not->toContain('XXX');
    });
});

describe('the issued editor', function (): void {
    it('offers the same list as the shared service', function (): void {
        InvoiceFixtures::configureSupplier();
        InvoiceFixtures::cnbRate('USD', '2026-10-14', '22.500');
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);

        expect(IssuedDocumentRules::currencyChoices($invoice))->toBe(InvoiceCurrencies::choices($client, $invoice->currency))
            ->toContain('USD');
    });
});
