<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Str;

/**
 * Fictional clients and drafts for every invoicing test (public repository, fictional data only): a client with an
 * invented name and address, a company number generated at runtime and an e-mail address on example.com, and drafts
 * built through the domain Actions as the Admin, so a test starts from the same rows the application writes.
 *
 * Nothing here is a real value; amounts and names are chosen by the test that calls.
 */
final class InvoiceFixtures
{
    /**
     * A client with a complete billing address, a valid runtime company number and an invoice e-mail address on
     * example.com. The hourly rate is zero in the currency of the client unless the overrides name one.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function client(array $overrides = []): Client
    {
        $currency = is_string($overrides['currency'] ?? null) ? $overrides['currency'] : 'CZK';

        $attributes = [
            'name' => 'Example client '.Str::lower(Str::random(8)),
            'company_number' => FictionalCompanyId::valid(),
            'country' => 'CZ',
            'street' => 'Example street 1',
            'city' => 'Example city',
            'postal_code' => '110 00',
            'currency' => $currency,
            'hourly_rate' => Money::ofMinor(0, $currency),
            'payment_terms_days' => 14,
            'invoice_language' => 'cs',
            'invoice_email' => exampleEmail('invoice'),
            ...$overrides,
        ];

        return app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create($attributes));
    }

    /**
     * A draft of the client built through CreateDraft and, for any items, UpdateDraft, as the given Admin (a new
     * one when none is given). The test acts as that Admin afterwards.
     *
     * @param  list<array<string, mixed>>  $items  lines built with {@see InvoiceFixtures::item()}
     */
    public static function draft(Client $client, array $items = [], InvoiceKind $kind = InvoiceKind::Invoice, ?User $actor = null): Invoice
    {
        $admin = $actor ?? Canary::admin();

        test()->actingAs($admin);

        $draft = app(CreateDraft::class)->handle($admin, ['client_id' => $client->id, 'kind' => $kind->value]);

        return $items === [] ? $draft : app(UpdateDraft::class)->handle($admin, $draft, ['items' => $items]);
    }

    /**
     * One manual line of the editor payload.
     *
     * @return array{description: string, quantity: string, unit: string, unit_price: string}
     */
    public static function item(string $description, string $quantity, string $unitPrice, string $unit = 'h'): array
    {
        return ['description' => $description, 'quantity' => $quantity, 'unit' => $unit, 'unit_price' => $unitPrice];
    }
}
