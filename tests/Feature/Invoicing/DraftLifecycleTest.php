<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\DeleteDraft;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Actions\UpdateInternalNote;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

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
    $client = InvoiceFixtures::client(['currency' => 'EUR', 'payment_terms_days' => 14, 'invoice_language' => 'en']);
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
    $draft = draftCreate($admin, InvoiceFixtures::client(['currency' => 'EUR', 'payment_terms_days' => 14, 'invoice_language' => 'en']));
    $sequences = DB::table('number_sequences')->count();

    $saved = draftSave($admin, $draft, [
        'items' => [
            [...InvoiceFixtures::item('Konzultace', '2,5', '80,00'), 'line_total_minor' => 1, 'line_total' => '1.00'],
            InvoiceFixtures::item('Licence', '1', '120,50', 'ks'),
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
    $client = InvoiceFixtures::client();
    $draft = draftCreate($admin, $client);
    $partner = Canary::partnerFor($client->id);

    $this->actingAs($partner);

    expect(fn () => app(CreateDraft::class)->handle($partner, ['client_id' => $client->id, 'kind' => 'invoice']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateDraft::class)->handle($partner, $draft, ['note' => 'Hidden']))
        ->toThrow(AuthorizationException::class);
});

/**
 * The field errors of a call the Action must refuse with a ValidationException.
 *
 * @param  Closure(): mixed  $call
 * @return array<string, list<string>>
 */
function draftRefused(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    test()->fail('The call was accepted although it must be refused with a field error.');
}

/**
 * Issues the draft the way the pipeline of a later plan will: every field an issued document prints, written as
 * system. The draft needs a positive total first.
 */
function draftIssue(Invoice $draft): Invoice
{
    return draftSystem(static function () use ($draft): Invoice {
        $draft->refresh()->forceFill([
            'state' => 'issued',
            'number' => implode('', ['D', (string) random_int(100000, 999999), (string) random_int(1000, 9999)]),
            'variable_symbol' => (string) random_int(1000000, 9999999),
            'issue_date' => '2026-10-17',
            'taxable_supply_date' => '2026-10-17',
            'due_date' => '2026-10-31',
            'supplier_snapshot' => ['name' => 'Example supplier'],
            'customer_snapshot' => ['name' => 'Example customer'],
            'bank_snapshot' => ['account' => 'Example account'],
            'appearance_snapshot' => ['accent' => '#000000'],
            'issued_at' => now(),
        ])->save();

        return $draft->refresh();
    });
}

/**
 * The source rows of the draft's lines.
 *
 * @return list<InvoiceItemSource>
 */
function draftSources(Invoice $draft): array
{
    return draftSystem(static fn (): array => InvoiceItemSource::query()->where('invoice_id', $draft->id)->get()->all());
}

it('refuses bad lines as field errors in Czech and stores nothing', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client(['currency' => 'CZK']));

    $errors = draftRefused(fn () => draftSave($admin, $draft, ['items' => [
        InvoiceFixtures::item('', '1', '10'),
        InvoiceFixtures::item(str_repeat('a', 501), '1', '10'),
        InvoiceFixtures::item('Zero quantity', '0', '10'),
        InvoiceFixtures::item('Long quantity', '1,23456', '10'),
        InvoiceFixtures::item('Long unit', '1', '10', str_repeat('u', 17)),
        InvoiceFixtures::item('Three decimals', '1', '10,123'),
        InvoiceFixtures::item('Negative price', '1', '-5'),
        InvoiceFixtures::item('Not a number', 'abc', 'x'),
    ]]));

    expect($errors)->toBe([
        'items.0.description' => ['Zadejte popis položky (nejvýše 500 znaků).'],
        'items.1.description' => ['Zadejte popis položky (nejvýše 500 znaků).'],
        'items.2.quantity' => ['Zadejte množství větší než nula s nejvýše čtyřmi desetinnými místy.'],
        'items.3.quantity' => ['Zadejte množství větší než nula s nejvýše čtyřmi desetinnými místy.'],
        'items.4.unit' => ['Jednotka může mít nejvýše 16 znaků.'],
        'items.5.unit_price' => ['Zadejte jednotkovou cenu v měně dokladu.'],
        'items.6.unit_price' => ['Zadejte jednotkovou cenu v měně dokladu.'],
        'items.7.quantity' => ['Zadejte množství větší než nula s nejvýše čtyřmi desetinnými místy.'],
        'items.7.unit_price' => ['Zadejte jednotkovou cenu v měně dokladu.'],
    ])
        ->and(draftLines($draft))->toBe([])
        ->and($draft->refresh()->total_minor)->toBe(0);
});

it('saves a line without a unit as NULL on an invoice and on a proforma', function (InvoiceKind $kind): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client(['currency' => 'CZK']), ['kind' => $kind->value]);

    $saved = draftSave($admin, $draft, ['items' => [
        InvoiceFixtures::item('Example blank unit', '1', '10', ''),
        InvoiceFixtures::item('Example whitespace unit', '1', '20', '   '),
        [...InvoiceFixtures::item('Example null unit', '1', '30'), 'unit' => null],
        ['description' => 'Example missing unit', 'quantity' => '1', 'unit_price' => '40'],
        InvoiceFixtures::item('Example trimmed unit', '1', '50', ' h '),
    ]]);

    $lines = draftLines($saved);

    expect($lines)->toHaveCount(5)
        ->and(array_map(static fn (InvoiceItem $line): ?string => $line->unit, $lines))->toBe([null, null, null, null, 'h'])
        ->and($saved->total_minor)->toBe(15000);
})->with([
    'invoice' => [InvoiceKind::Invoice],
    'proforma' => [InvoiceKind::Proforma],
]);

it('needs the explicit confirmation for a zero unit price', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client());

    $errors = draftRefused(fn () => draftSave($admin, $draft, ['items' => [InvoiceFixtures::item('Free of charge', '1', '0')]]));

    expect($errors)->toBe(['items.0.unit_price' => ['Jednotkovou cenu nula potvrďte u položky.']]);

    $saved = draftSave($admin, $draft, ['items' => [[...InvoiceFixtures::item('Free of charge', '1', '0'), 'confirm_zero_price' => true]]]);

    expect(draftLines($saved))->toHaveCount(1)
        ->and(draftLines($saved)[0]->unit_price_minor)->toBe(0)
        ->and($saved->total_minor)->toBe(0);
});

it('caps a draft at the configured number of lines', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client());
    $line = static fn (int $n): array => InvoiceFixtures::item('Line '.$n, '1', '1', 'ks');

    $errors = draftRefused(fn () => draftSave($admin, $draft, ['items' => array_map($line, range(1, 201))]));

    expect($errors)->toBe(['items' => ['Doklad může mít nejvýše 200 položek.']])
        ->and(draftLines($draft))->toBe([]);

    $saved = draftSave($admin, $draft, ['items' => array_map($line, range(1, 200))]);

    expect(draftLines($saved))->toHaveCount(200)
        ->and($saved->total_minor)->toBe(20000);
});

it('refuses the id of a line of another draft and writes nothing', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $mine = draftCreate($admin, $client);
    $other = draftCreate($admin, $client);
    $foreign = draftLines(draftSave($admin, $other, ['items' => [InvoiceFixtures::item('Foreign', '1', '100')]]))[0];
    draftSave($admin, $mine, ['items' => [InvoiceFixtures::item('Mine', '1', '50')]]);

    $errors = draftRefused(fn () => draftSave($admin, $mine, ['items' => [
        [...InvoiceFixtures::item('Hijacked', '1', '1'), 'id' => $foreign->id],
    ]]));

    $mineLines = draftLines($mine);
    $otherLines = draftLines($other);

    expect($errors)->toBe(['items.0.id' => ['Položka nepatří k tomuto dokladu.']])
        ->and(array_map(static fn (InvoiceItem $item): string => $item->description, $mineLines))->toBe(['Mine'])
        ->and(array_map(static fn (InvoiceItem $item): string => $item->description, $otherLines))->toBe(['Foreign'])
        ->and($otherLines[0]->id)->toBe($foreign->id);
});

it('updates a line by id, keeps its row and source, deletes a missing line with its source and follows the order sent', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client());
    $saved = draftSave($admin, $draft, ['items' => [
        InvoiceFixtures::item('First', '1', '10'),
        InvoiceFixtures::item('Second', '1', '20'),
        InvoiceFixtures::item('Third', '1', '30'),
    ]]);
    [$first, $second, $third] = draftLines($saved);

    expect(draftSources($saved))->toHaveCount(3);

    $updated = draftSave($admin, $saved, ['items' => [
        [...InvoiceFixtures::item('Third renamed', '2', '30'), 'id' => $third->id],
        [...InvoiceFixtures::item('First', '1', '10'), 'id' => $first->id],
        InvoiceFixtures::item('Brand new', '1', '5'),
    ]]);

    $lines = draftLines($updated);
    $sourceItemIds = array_map(static fn (InvoiceItemSource $source): string => $source->invoice_item_id, draftSources($updated));
    sort($sourceItemIds);
    $expectedIds = [$third->id, $first->id, $lines[2]->id];
    sort($expectedIds);

    expect(array_map(static fn (InvoiceItem $item): array => [$item->position, $item->description], $lines))
        ->toBe([[1, 'Third renamed'], [2, 'First'], [3, 'Brand new']])
        ->and([$lines[0]->id, $lines[1]->id])->toBe([$third->id, $first->id])
        ->and($lines[0]->line_total_minor)->toBe(6000)
        ->and(draftSystem(static fn (): int => InvoiceItem::query()->whereKey($second->id)->count()))->toBe(0)
        ->and($sourceItemIds)->toBe($expectedIds)
        ->and($updated->subtotal_minor)->toBe(6000 + 1000 + 500)
        ->and($updated->total_minor)->toBe(7500);
});

it('marks the price of a line generated from time as manual only when the unit price changes, and keeps its origin', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client());
    $saved = draftSave($admin, $draft, ['items' => [InvoiceFixtures::item('Billed time', '1', '100')]]);
    $line = draftLines($saved)[0];

    // A line made from tracked time: written as system, the way the plan that adds time will write it.
    draftSystem(static function () use ($line): void {
        $source = InvoiceItemSource::query()->where('invoice_item_id', $line->id)->firstOrFail();
        $source->forceFill([
            'origin' => 'time',
            'source_seconds' => 3600,
            'source_rate_minor' => 10000,
            'source_rate_currency' => 'CZK',
            'price_manual' => false,
        ])->save();
    });

    $sourceOf = static fn (): InvoiceItemSource => draftSources($saved)[0];

    // The quantity and the text change; the unit price does not.
    draftSave($admin, $saved, ['items' => [[...InvoiceFixtures::item('Billed time, renamed', '2', '100'), 'id' => $line->id]]]);

    expect($sourceOf()->price_manual)->toBeFalse()
        ->and($sourceOf()->origin->value)->toBe('time');

    // The unit price changes.
    $after = draftSave($admin, $saved, ['items' => [[...InvoiceFixtures::item('Billed time, renamed', '2', '90'), 'id' => $line->id]]]);
    $stored = draftLines($after)[0];

    expect($sourceOf()->price_manual)->toBeTrue()
        ->and($sourceOf()->origin->value)->toBe('time')
        ->and($sourceOf()->source_seconds)->toBe(3600)
        ->and($stored->id)->toBe($line->id)
        ->and($stored->line_total_minor)->toBe(18000);
});

it('refuses a due date before the issue date', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client());

    $errors = draftRefused(fn () => draftSave($admin, $draft, ['issue_date' => '2026-10-20', 'due_date' => '2026-10-19']));

    expect($errors)->toBe(['due_date' => ['Datum splatnosti nesmí být dříve než datum vystavení.']]);

    $saved = draftSave($admin, $draft, ['issue_date' => '2026-10-20', 'due_date' => '2026-10-20']);

    expect($saved->issue_date?->toDateString())->toBe('2026-10-20')
        ->and($saved->due_date?->toDateString())->toBe('2026-10-20');

    $cleared = draftSave($admin, $saved, ['issue_date' => null, 'due_date' => null]);

    expect($cleared->issue_date)->toBeNull()
        ->and($cleared->due_date)->toBeNull();
});

it('refuses to update an issued document', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client());
    draftSave($admin, $draft, ['items' => [InvoiceFixtures::item('Work', '1', '100')]]);
    $issued = draftIssue($draft);

    expect(fn () => draftSave($admin, $issued, ['note' => 'Too late']))
        ->toThrow(DomainException::class, 'Doklad už je vystavený a nelze ho upravit.');
});

/**
 * Two fictional entries of the client held by the draft, reserved the way the plan that adds time will do it.
 *
 * @return list<TimeEntry>
 */
function draftReserveEntries(User $admin, Client $client, Invoice $draft): array
{
    return draftSystem(static function () use ($admin, $client, $draft): array {
        $entries = [];

        foreach ([['2026-10-12 08:00:00', '2026-10-12 09:00:00'], ['2026-10-12 10:00:00', '2026-10-12 11:30:00']] as [$start, $end]) {
            $entries[] = TimeEntry::factory()->create([
                'user_id' => $admin->id,
                'client_id' => $client->id,
                'started_at' => CarbonImmutable::parse($start, 'UTC'),
                'ended_at' => CarbonImmutable::parse($end, 'UTC'),
                'billable' => true,
            ]);
        }

        DB::transaction(static function () use ($entries, $draft): void {
            $rows = TimeEntry::query()->whereKey(array_map(static fn (TimeEntry $entry): string => $entry->id, $entries))->orderBy('id')->lockForUpdate()->get();

            app(EntryBilling::class)->reserve($rows, $draft->id);
        });

        return $entries;
    });
}

it('deletes a draft, releases its reserved entries and consumes no number', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $draft = draftCreate($admin, $client);
    $saved = draftSave($admin, $draft, ['items' => [InvoiceFixtures::item('One', '1', '10'), InvoiceFixtures::item('Two', '1', '20')]]);
    $entries = draftReserveEntries($admin, $client, $saved);
    $sequences = DB::table('number_sequences')->count();

    expect(draftSystem(static fn (): int => TimeEntry::query()->where('invoice_id', $saved->id)->count()))->toBe(2);

    app(DeleteDraft::class)->handle($admin, $saved);

    $counts = draftSystem(static fn (): array => [
        Invoice::query()->whereKey($saved->id)->count(),
        InvoiceItem::query()->where('invoice_id', $saved->id)->count(),
        InvoiceItemSource::query()->where('invoice_id', $saved->id)->count(),
        InvoiceAdmin::query()->where('invoice_id', $saved->id)->count(),
    ]);
    $released = draftSystem(static fn (): array => TimeEntry::query()->whereKey(array_map(static fn (TimeEntry $entry): string => $entry->id, $entries))->get()->map(
        static fn (TimeEntry $entry): array => [$entry->billing_state->value, $entry->invoice_id, $entry->billed_at],
    )->all());

    expect($counts)->toBe([0, 0, 0, 0])
        ->and($released)->toBe([['unbilled', null, null], ['unbilled', null, null]])
        ->and(DB::table('number_sequences')->count())->toBe($sequences);
});

it('never deletes an issued document, in the Action or in the database', function (): void {
    $admin = Canary::admin();
    $draft = draftCreate($admin, InvoiceFixtures::client());
    draftSave($admin, $draft, ['items' => [InvoiceFixtures::item('Work', '1', '100')]]);
    $issued = draftIssue($draft);

    $this->actingAs($admin);

    expect(fn () => app(DeleteDraft::class)->handle($admin, $issued))
        ->toThrow(DomainException::class, 'Doklad už je vystavený a nelze ho upravit.')
        ->and(fn () => DB::transaction(static fn (): int => DB::delete('delete from invoices where id = ?', [$issued->id])))
        ->toThrow(QueryException::class)
        ->and(draftSystem(static fn (): int => Invoice::query()->whereKey($issued->id)->count()))->toBe(1);
});

/**
 * The Admin-only row of the document, read as system.
 */
function draftAdminRow(Invoice $invoice): InvoiceAdmin
{
    return draftSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->firstOrFail());
}

it('stores the internal note of a draft and of an issued document with a new marker and never logs the text', function (): void {
    $admin = Canary::admin();
    $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '1', '100')], actor: $admin);
    $draftCanary = Canary::canary('draftnote');
    $issuedCanary = Canary::canary('issuednote');

    app(UpdateInternalNote::class)->handle($admin, $draft, $draftCanary);

    $onDraft = draftAdminRow($draft);

    expect($onDraft->internal_note)->toBe($draftCanary)
        ->and($onDraft->internal_note_updated_at)->not->toBeNull();

    $issued = draftIssue($draft);
    $this->travel(1)->hours();
    $this->actingAs($admin);
    app(UpdateInternalNote::class)->handle($admin, $issued, $issuedCanary);

    $onIssued = draftAdminRow($draft);
    $rows = Activity::query()->where('subject_type', 'invoice_admin')->where('subject_id', $onIssued->id)->get();
    $json = (string) json_encode($rows->map(static fn (Activity $row): mixed => $row->attribute_changes)->all());

    expect($onIssued->internal_note)->toBe($issuedCanary)
        ->and($onIssued->internal_note_updated_at?->gt($onDraft->internal_note_updated_at))->toBeTrue()
        ->and($rows->count())->toBeGreaterThanOrEqual(2)
        ->and($json)->toContain('internal_note_updated_at')
        ->and($json)->not->toContain($draftCanary)
        ->and($json)->not->toContain($issuedCanary)
        ->and($json)->not->toContain('"internal_note"');
});

it('leaves the marker alone when the internal note does not change and clears it with a blank note', function (): void {
    $admin = Canary::admin();
    $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), actor: $admin);

    app(UpdateInternalNote::class)->handle($admin, $draft, '  Remember the discount  ');
    $first = draftAdminRow($draft);

    $this->travel(1)->hours();
    app(UpdateInternalNote::class)->handle($admin, $draft, 'Remember the discount');

    expect(draftAdminRow($draft)->internal_note_updated_at?->equalTo($first->internal_note_updated_at))->toBeTrue();

    app(UpdateInternalNote::class)->handle($admin, $draft, '   ');

    expect(draftAdminRow($draft)->internal_note)->toBeNull();
});

it('saves the internal note together with the draft', function (): void {
    $admin = Canary::admin();
    $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), actor: $admin);

    draftSave($admin, $draft, ['internal_note' => 'Private reminder', 'note' => 'Printed text', 'items' => [InvoiceFixtures::item('Work', '1', '10')]]);

    expect(draftAdminRow($draft)->internal_note)->toBe('Private reminder')
        ->and($draft->refresh()->note)->toBe('Printed text');

    $errors = draftRefused(fn () => draftSave($admin, $draft, ['internal_note' => str_repeat('a', 2001), 'items' => [InvoiceFixtures::item('Other', '1', '99')]]));

    expect($errors)->toBe(['internal_note' => ['Interní poznámka může mít nejvýše 2 000 znaků.']])
        ->and(array_map(static fn (InvoiceItem $item): string => $item->description, draftLines($draft)))->toBe(['Work']);
});

it('refuses an internal note over 2000 characters and refuses a Partner', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $draft = InvoiceFixtures::draft($client, actor: $admin);

    $errors = draftRefused(fn () => app(UpdateInternalNote::class)->handle($admin, $draft, str_repeat('a', 2001)));

    app(UpdateInternalNote::class)->handle($admin, $draft, str_repeat('b', 2000));

    expect($errors)->toBe(['internal_note' => ['Interní poznámka může mít nejvýše 2 000 znaků.']])
        ->and(mb_strlen((string) draftAdminRow($draft)->internal_note))->toBe(2000);

    $partner = Canary::partnerFor($client->id);
    $this->actingAs($partner);

    expect(fn () => app(UpdateInternalNote::class)->handle($partner, $draft, 'Partner note'))
        ->toThrow(AuthorizationException::class);
});

it('builds fictional clients and drafts for later tests', function (): void {
    $client = InvoiceFixtures::client(['currency' => 'EUR']);
    $draft = InvoiceFixtures::draft($client, [
        InvoiceFixtures::item('Design', '3', '40'),
        InvoiceFixtures::item('Hosting', '1', '15,50', 'ks'),
    ]);
    $proforma = InvoiceFixtures::draft($client, kind: InvoiceKind::Proforma);

    expect($client->invoice_email)->toEndWith('@example.com')
        ->and($client->company_number)->toMatch('/^\d{8}$/D')
        ->and($client->street)->not->toBeNull()
        ->and($client->currency)->toBe('EUR')
        ->and($draft->kind->value)->toBe('invoice')
        ->and($draft->currency)->toBe('EUR')
        ->and($draft->total_minor)->toBe(13550)
        ->and($proforma->kind->value)->toBe('proforma')
        ->and($proforma->total_minor)->toBe(0);
});
