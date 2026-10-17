<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

/*
 * The draft lifecycle (IN-02, IN-08, IN-10; D-01, D-03, D-05, D-08, D-11): creating an empty draft, saving manual
 * lines with server-side totals, validating them as field errors, deleting a draft and the internal note. Every
 * name, number and amount is fictional.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function draftSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A fictional client with the given billing defaults.
 *
 * @param  array<string, mixed>  $attributes
 */
function draftClient(array $attributes = []): Client
{
    $currency = is_string($attributes['currency'] ?? null) ? $attributes['currency'] : 'CZK';

    return draftSystem(static fn (): Client => Client::factory()->create([
        'hourly_rate' => Money::ofMinor(0, $currency),
        ...$attributes,
    ]));
}

/**
 * Creates an empty invoice draft of the client as the signed-in Admin.
 *
 * @param  array<string, mixed>  $extra
 */
function draftCreate(User $admin, Client $client, array $extra = []): Invoice
{
    test()->actingAs($admin);

    return app(CreateDraft::class)->handle($admin, ['client_id' => $client->id, 'kind' => 'invoice', ...$extra]);
}

/**
 * Saves the draft as the signed-in Admin.
 *
 * @param  array<string, mixed>  $data
 */
function draftSave(User $admin, Invoice $draft, array $data): Invoice
{
    test()->actingAs($admin);

    return app(UpdateDraft::class)->handle($admin, $draft, $data);
}

/**
 * One line of the editor payload.
 *
 * @return array<string, mixed>
 */
function draftLine(string $description, string $quantity, string $unitPrice, string $unit = 'h'): array
{
    return ['description' => $description, 'quantity' => $quantity, 'unit' => $unit, 'unit_price' => $unitPrice];
}

/**
 * The stored lines of the draft in order, as plain rows.
 *
 * @return list<InvoiceItem>
 */
function draftLines(Invoice $draft): array
{
    return draftSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $draft->id)->orderBy('position')->get()->all());
}

it('creates an empty draft with the defaults of the client and consumes no number', function (): void {
    $admin = Canary::admin();
    $client = draftClient(['currency' => 'EUR', 'payment_terms_days' => 14, 'invoice_language' => 'en']);
    $sequences = DB::table('number_sequences')->count();

    $draft = draftCreate($admin, $client);

    expect($draft->state->value)->toBe('draft')
        ->and($draft->kind->value)->toBe('invoice')
        ->and($draft->client_id)->toBe($client->id)
        ->and($draft->currency)->toBe('EUR')
        ->and($draft->language->value)->toBe('en')
        ->and($draft->vat_mode->value)->toBe('non_payer')
        ->and($draft->number)->toBeNull()
        ->and($draft->issue_date)->toBeNull()
        ->and($draft->due_date)->toBeNull()
        ->and([$draft->subtotal_minor, $draft->vat_minor, $draft->total_minor])->toBe([0, 0, 0])
        ->and(draftSystem(static fn (): int => InvoiceAdmin::query()->where('invoice_id', $draft->id)->count()))->toBe(1)
        ->and(DB::table('number_sequences')->count())->toBe($sequences);
});

it('saves manual lines with the totals computed on the server and ignores a forged total', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, draftClient(['currency' => 'EUR', 'payment_terms_days' => 14, 'invoice_language' => 'en']));
    $sequences = DB::table('number_sequences')->count();

    $saved = draftSave($admin, $draft, [
        'items' => [
            [...draftLine('Konzultace', '2,5', '80,00'), 'line_total_minor' => 1, 'line_total' => '1.00'],
            draftLine('Licence', '1', '120,50', 'ks'),
        ],
        'subtotal_minor' => 5,
        'total_minor' => 5,
        'vat_minor' => 5,
    ]);

    $lines = draftLines($saved);

    expect($lines)->toHaveCount(2)
        ->and([$lines[0]->position, $lines[0]->description, $lines[0]->unit, $lines[0]->quantity, $lines[0]->unit_price_minor, $lines[0]->line_total_minor])
        ->toBe([1, 'Konzultace', 'h', '2.5000', 8000, 20000])
        ->and([$lines[1]->position, $lines[1]->description, $lines[1]->unit, $lines[1]->quantity, $lines[1]->unit_price_minor, $lines[1]->line_total_minor])
        ->toBe([2, 'Licence', 'ks', '1.0000', 12050, 12050])
        ->and([$lines[0]->price_converted, $lines[0]->vat_rate, $lines[0]->vat_minor])->toBe([false, null, 0])
        ->and([$saved->subtotal_minor, $saved->vat_minor, $saved->total_minor])->toBe([32050, 0, 32050])
        ->and($saved->number)->toBeNull()
        ->and(DB::table('number_sequences')->count())->toBe($sequences);
});

it('refuses a Partner both Actions', function (): void {
    $admin = Canary::admin();
    $client = draftClient();
    $draft = draftCreate($admin, $client);
    $partner = Canary::partnerFor($client->id);

    $this->actingAs($partner);

    expect(fn () => app(CreateDraft::class)->handle($partner, ['client_id' => $client->id, 'kind' => 'invoice']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateDraft::class)->handle($partner, $draft, ['note' => 'Hidden']))
        ->toThrow(AuthorizationException::class);
});
