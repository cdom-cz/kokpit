<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\FictionalIban;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * Issuing a document (IN-01, IN-03, IN-07, IN-08; D-10, D-11, D-12, D-13, D-21, D-23): one transaction that
 * validates, numbers, snapshots and freezes a draft. Every name, number and amount is fictional; the numbers in
 * the assertions are composed from fragments.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function issueSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A small invented PNG upload; the colour is drawn from the seed so two seeds are two different images.
 */
function issueImage(int $seed): UploadedFile
{
    $image = imagecreatetruecolor(60, 20);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $seed % 256, ($seed * 7) % 256, ($seed * 13) % 256));

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    return DocumentFixtures::upload('brand-'.$seed.'.png', $bytes);
}

/**
 * Points the stored supplier settings at an image path of a kind.
 */
function issueSupplierImage(string $kind, ?string $path): void
{
    issueSystem(static function () use ($kind, $path): void {
        $supplier = app(SupplierSettings::class);
        $supplier->{$kind.'_path'} = $path;
        $supplier->save();
    });

    app()->forgetScopedInstances();
}

/**
 * The raw stored row of an invoice, for before and after comparisons.
 *
 * @return array<string, mixed>
 */
function issueRow(string $id): array
{
    return (array) DB::table('invoices')->where('id', $id)->first();
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

it('issues a CZK draft with the next number, frozen snapshots, a variable symbol and dates', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client(['payment_terms_days' => 14]);
    $logo = app(DocumentImage::class)->store(issueImage(40), 'logo');
    issueSupplierImage('logo', $logo);
    $logoSha = hash('sha256', (string) Storage::disk('local')->get($logo));

    $draft = InvoiceFixtures::draft($client, [
        InvoiceFixtures::item('Consulting', '2', '500.00'),
        InvoiceFixtures::item('Licence', '1', '250.50', 'ks'),
    ], actor: $admin);

    $issued = app(IssueDocument::class)->handle($admin, $draft);
    $number = '2026'.'0001';

    expect($issued->state->value)->toBe('issued')
        ->and($issued->number)->toBe($number)
        ->and($issued->variable_symbol)->toBe($number)
        ->and($issued->issue_date?->format('Y-m-d'))->toBe('2026-10-14')
        ->and($issued->due_date?->format('Y-m-d'))->toBe('2026-10-28')
        ->and($issued->taxable_supply_date)->toBeNull()
        ->and($issued->vat_mode->value)->toBe('non_payer')
        ->and([$issued->subtotal_minor, $issued->vat_minor, $issued->total_minor])->toBe([125050, 0, 125050])
        ->and($issued->issued_at)->not->toBeNull()
        ->and($issued->supplier_snapshot)->toMatchArray([
            'name' => 'Example Supplier s.r.o.',
            'street' => 'Sample Street 10',
            'city' => 'Sampletown',
            'postal_code' => '100 00',
            'country' => 'CZ',
            'vat_payer' => false,
        ])
        ->and($issued->customer_snapshot)->toMatchArray([
            'name' => $client->name,
            'street' => 'Example street 1',
            'city' => 'Example city',
            'postal_code' => '110 00',
            'country' => 'CZ',
            'company_id' => $client->company_number,
        ])
        ->and($issued->bank_snapshot)->toMatchArray(['currency' => 'CZK', 'label' => 'Example CZK account'])
        ->and($issued->appearance_snapshot)->toEqual([
            'accent' => '#ff6f00',
            'footer' => null,
            'logo' => ['path' => $logo, 'sha256' => $logoSha],
            'signature' => null,
        ]);
});

it('keeps the logo of the snapshot when the logo in the settings is replaced afterwards', function (): void {
    $admin = Canary::admin();
    $images = app(DocumentImage::class);
    $first = $images->store(issueImage(40), 'logo');
    issueSupplierImage('logo', $first);

    $issued = app(IssueDocument::class)->handle($admin, InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '1', '100')], actor: $admin));

    $second = $images->store(issueImage(90), 'logo');
    issueSupplierImage('logo', $second);

    expect($second)->not->toBe($first)
        ->and($issued->fresh()?->appearance_snapshot['logo']['path'] ?? null)->toBe($first)
        ->and($images->dataUri($first, $issued->appearance_snapshot['logo']['sha256']))->toStartWith('data:image/png;base64,');
});

it('numbers invoices and proformas from their own series', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $items = [InvoiceFixtures::item('Work', '1', '100')];
    $issue = static fn (InvoiceKind $kind): Invoice => app(IssueDocument::class)->handle($admin, InvoiceFixtures::draft($client, $items, $kind, $admin));

    $first = $issue(InvoiceKind::Invoice);
    $second = $issue(InvoiceKind::Invoice);
    $proforma = $issue(InvoiceKind::Proforma);

    expect($first->number)->toBe('2026'.'0001')
        ->and($second->number)->toBe('2026'.'0002')
        ->and($proforma->number)->toBe('2026'.'9'.'0001')
        ->and($proforma->variable_symbol)->toBe('2026'.'9'.'0001');
});

it('consumes no number for a draft and previews the expected one', function (): void {
    $admin = Canary::admin();
    $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '1', '100')], actor: $admin);

    expect(app(IssueDocument::class)->expectedNumber($draft))->toBe('2026'.'0001')
        ->and(DB::table('number_sequences')->count())->toBe(0);
});

it('leaves an issued invoice open, and refuses every write to it, even a touch, once it is locked', function (): void {
    $admin = Canary::admin();
    $issued = app(IssueDocument::class)->handle($admin, InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '1', '100')], actor: $admin));

    expect($issued->lock_state)->toBe(InvoiceLockState::Open);

    // Open: a correction and a touch are accepted (IN-11, D-12).
    RawSql::expectAllowed(fn () => $issued->fresh()?->forceFill(['note' => 'Corrected'])->save());

    // A touch of the same instant changes nothing and sends no statement, so move the clock first.
    $this->travelTo(now()->addMinute());
    RawSql::expectAllowed(fn () => $issued->fresh()?->touch());

    InvoiceFixtures::lock($issued);

    RawSql::expectSqlState('KP001', fn () => $issued->fresh()?->forceFill(['total_minor' => 1])->save());

    $this->travelTo(now()->addMinute());
    RawSql::expectSqlState('KP001', fn () => $issued->fresh()?->touch());

    expect(issueSystem(static fn (): int => Invoice::query()->whereKey($issued->id)->where('total_minor', 10000)->count()))->toBe(1);
});

it('builds a cancelled invoice with the fixture and leaves the issued row untouched', function (): void {
    $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '3', '100')]);
    $before = issueRow($issued->id);

    $storno = InvoiceFixtures::stornoed($issued);

    expect($storno->kind->value)->toBe('credit_note')
        ->and($storno->state->value)->toBe('issued')
        ->and($storno->cancels_invoice)->toBeTrue()
        ->and($storno->credited_invoice_id)->toBe($issued->id)
        ->and($storno->total_minor)->toBe(-30000)
        ->and($issued->fresh()?->isCancelled())->toBeTrue()
        ->and(issueRow($issued->id))->toBe($before)
        ->and(issueSystem(static fn (): int => InvoiceAdmin::query()->where('invoice_id', $storno->id)->count()))->toBe(1);
});

it('refuses a Partner and numbers nothing', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Work', '1', '100')], actor: $admin);
    $partner = Canary::partnerFor($client->id);
    $this->actingAs($partner);

    expect(fn () => app(IssueDocument::class)->handle($partner, $draft))->toThrow(AuthorizationException::class)
        ->and(DB::table('number_sequences')->count())->toBe(0)
        ->and(issueSystem(static fn (): string => Invoice::query()->whereKey($draft->id)->firstOrFail()->state->value))->toBe('draft');
});

/**
 * The counter rows, ordered, for before and after comparisons.
 *
 * @return list<array{string, int}>
 */
function issueCounters(): array
{
    return DB::table('number_sequences')->orderBy('scope_key')->get()
        ->map(static fn (object $row): array => [(string) $row->scope_key, (int) $row->next_value])
        ->all();
}

/**
 * The errors the call is refused with.
 *
 * @param  Closure(): mixed  $call
 * @return array<string, list<string>>
 */
function issueRefused(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    test()->fail('The call was accepted although it must be refused with a field error.');
}

/**
 * Saves the bank accounts of the supplier: one Europe 2 account per currency given.
 *
 * @param  list<string>  $currencies
 */
function issueBankAccounts(array $currencies): void
{
    issueSystem(static function () use ($currencies): void {
        $banks = app(BankAccountSettings::class);
        $banks->accounts = array_map(
            static fn (string $currency): BankAccount => new BankAccount(BankAccountFormat::Europe2, 'Example '.$currency.' account', $currency, iban: FictionalIban::czech('56')),
            $currencies,
        );
        $banks->save();
    });

    app()->forgetScopedInstances();
}

/**
 * A credit note draft of the issued invoice with one line, built as system (the Action of plan 10-23 does not exist
 * yet): a positive line is a wrong credit note, a negative one a right one.
 */
function issueCreditDraft(Invoice $credited, int $lineMinor): Invoice
{
    return issueSystem(static fn (): Invoice => DB::transaction(static function () use ($credited, $lineMinor): Invoice {
        $credit = new Invoice;
        $credit->forceFill([
            'client_id' => $credited->client_id,
            'kind' => InvoiceKind::CreditNote,
            'currency' => $credited->currency,
            'language' => $credited->language,
            'vat_mode' => $credited->vat_mode,
            'credited_invoice_id' => $credited->id,
            'subtotal_minor' => $lineMinor,
            'total_minor' => $lineMinor,
        ])->save();

        (new InvoiceAdmin)->forceFill(['invoice_id' => $credit->id])->save();

        (new InvoiceItem)->forceFill([
            'invoice_id' => $credit->id,
            'position' => 1,
            'description' => 'Credited work',
            'quantity' => $lineMinor < 0 ? '-1.0000' : '1.0000',
            'unit' => 'ks',
            'unit_price_minor' => abs($lineMinor),
            'line_total_minor' => $lineMinor,
        ])->save();

        return $credit->refresh();
    }));
}

/**
 * The blocker cases: a draft that is wrong in exactly one way, the keys it is refused with and the exact Czech
 * message of the key under test.
 *
 * @return array{Invoice, string}
 */
function issueBlockerCase(string $case): array
{
    $admin = Canary::admin();
    $item = [InvoiceFixtures::item('Work', '2', '100.00')];

    return match ($case) {
        'no_items' => [InvoiceFixtures::draft(InvoiceFixtures::client(), [], actor: $admin), 'Doklad nemá žádnou položku.'],
        'total_not_positive' => [
            InvoiceFixtures::draft(InvoiceFixtures::client(), [[...InvoiceFixtures::item('Free work', '1', '0'), 'confirm_zero_price' => true]], actor: $admin),
            'Celková částka musí být větší než nula.',
        ],
        'supplier_missing' => (static function () use ($admin, $item): array {
            issueSystem(static function (): void {
                $supplier = app(SupplierSettings::class);
                $supplier->street = '';
                $supplier->save();
            });
            app()->forgetScopedInstances();

            return [InvoiceFixtures::draft(InvoiceFixtures::client(), $item, actor: $admin), 'Doplňte údaje dodavatele v Nastavení.'];
        })(),
        'bank_missing' => (static function () use ($admin, $item): array {
            InvoiceFixtures::cnbRate('EUR', '2026-10-13', '25.1250000000');
            issueBankAccounts(['CZK']);

            return [
                InvoiceFixtures::draft(InvoiceFixtures::client(['currency' => 'EUR']), $item, actor: $admin),
                'Doklad s úhradou převodem nemá vybraný bankovní účet. Vyberte ho na dokladu, nebo ho přidejte v Nastavení.',
            ];
        })(),
        'cnb_missing' => [
            InvoiceFixtures::draft(InvoiceFixtures::client(['currency' => 'EUR']), $item, actor: $admin),
            'Pro měnu EUR není ke dni 14. 10. 2026 k dispozici kurz ČNB. Stáhněte kurzy v přehledu Kurzy ČNB.',
        ],
        'client_address_missing' => [
            InvoiceFixtures::draft(InvoiceFixtures::client(['street' => null]), $item, actor: $admin),
            'Klient nemá vyplněnou fakturační adresu.',
        ],
        'issue_date_future' => (static function () use ($admin, $item): array {
            $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), $item, actor: $admin);

            return [app(UpdateDraft::class)->handle($admin, $draft, ['issue_date' => '2026-10-15']), 'Datum vystavení nesmí být později než dnes.'];
        })(),
        'due_before_issue' => (static function () use ($admin, $item): array {
            $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), $item, actor: $admin);

            return [app(UpdateDraft::class)->handle($admin, $draft, ['due_date' => '2026-10-10']), 'Datum splatnosti nesmí být dříve než datum vystavení.'];
        })(),
        'pattern_invalid' => (static function () use ($admin, $item): array {
            DB::table('settings')->where('group', 'numbering')->where('name', 'proforma_pattern')
                ->update(['payload' => json_encode('{YYYY}A{NNNN}', JSON_THROW_ON_ERROR)]);
            app()->forgetScopedInstances();

            return [
                InvoiceFixtures::draft(InvoiceFixtures::client(), $item, InvoiceKind::Proforma, $admin),
                'Číselná řada pro zálohová faktura není platná. Opravte ji v Nastavení.',
            ];
        })(),
        default => throw new InvalidArgumentException('Unknown case '.$case),
    };
}

it('refuses each blocker alone with its exact Czech message and consumes nothing', function (string $case): void {
    $admin = Canary::admin();
    [$draft, $message] = issueBlockerCase($case);
    $key = $case;
    $expectedKeys = $case === 'no_items' ? ['no_items', 'total_not_positive'] : [$key];
    $counters = issueCounters();

    $blockers = app(IssueChecks::class)->blockers($draft->fresh(), '2026-10-14');
    $errors = issueRefused(fn () => app(IssueDocument::class)->handle($admin, $draft));

    expect(array_column($blockers, 'key'))->toBe($expectedKeys)
        ->and(array_column($blockers, 'message', 'key')[$key])->toBe($message)
        ->and(array_keys($errors))->toBe($expectedKeys)
        ->and($errors[$key][0])->toBe($message)
        ->and(issueSystem(static fn (): string => Invoice::query()->whereKey($draft->id)->firstOrFail()->state->value))->toBe('draft')
        ->and(issueCounters())->toBe($counters);
})->with([
    'no_items', 'total_not_positive', 'supplier_missing', 'bank_missing', 'cnb_missing',
    'client_address_missing', 'issue_date_future', 'due_before_issue', 'pattern_invalid',
]);

it('issues a credit note from its own series without a variable symbol, a due date or a bank account', function (): void {
    $admin = Canary::admin();
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
    issueBankAccounts([]);

    $credit = issueCreditDraft($invoice, -5000);
    $issued = app(IssueDocument::class)->handle($admin, $credit);

    expect($invoice->number)->toBe('2026'.'0001')
        ->and($issued->number)->toBe('2026'.'0001')
        ->and($issued->variable_symbol)->toBeNull()
        ->and($issued->due_date)->toBeNull()
        ->and($issued->bank_snapshot)->toBeNull()
        ->and($issued->total_minor)->toBe(-5000)
        ->and($issued->customer_snapshot)->not->toBeNull();
});

it('refuses a credit note with a positive total', function (): void {
    $admin = Canary::admin();
    $credit = issueCreditDraft(InvoiceFixtures::issued(InvoiceFixtures::client()), 5000);
    $counters = issueCounters();

    $errors = issueRefused(fn () => app(IssueDocument::class)->handle($admin, $credit));

    expect(array_keys($errors))->toBe(['total_not_positive'])
        ->and(issueCounters())->toBe($counters);
});

it('lets the first invoice be back-dated by two days', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client(['payment_terms_days' => 14]);
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Work', '1', '100')], actor: $admin);
    $draft = app(UpdateDraft::class)->handle($admin, $draft, ['issue_date' => '2026-10-12']);

    $issued = app(IssueDocument::class)->handle($admin, $draft);

    expect($issued->issue_date?->format('Y-m-d'))->toBe('2026-10-12')
        ->and($issued->due_date?->format('Y-m-d'))->toBe('2026-10-26')
        ->and($issued->number)->toBe('2026'.'0001');
});

it('refuses a date before the latest issued document of the series and keeps the counter', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    InvoiceFixtures::issued($client);
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Work', '1', '100')], actor: $admin);
    $draft = app(UpdateDraft::class)->handle($admin, $draft, ['issue_date' => '2026-10-13']);
    $counters = issueCounters();

    $errors = issueRefused(fn () => app(IssueDocument::class)->handle($admin, $draft));

    expect(array_keys($errors))->toBe(['issue_date_before_latest'])
        ->and($errors['issue_date_before_latest'][0])->toBe('Datum vystavení nesmí být dříve než 14. 10. 2026, kdy byl vystaven poslední doklad této řady.')
        ->and(issueCounters())->toBe($counters)
        ->and(issueSystem(static fn (): ?string => Invoice::query()->whereKey($draft->id)->firstOrFail()->number))->toBeNull();
});

it('checks the chronology again under the sequence lock and gives the number back', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Work', '1', '100')], actor: $admin);
    $draft = app(UpdateDraft::class)->handle($admin, $draft, ['issue_date' => '2026-10-12']);
    $counters = issueCounters();

    // Another issuer of the series commits a later document after the blockers ran and before the number is taken.
    $allocator = new class extends SequenceAllocator
    {
        public ?Closure $before = null;

        public function next(string $scopeKey): int
        {
            if ($this->before instanceof Closure) {
                $before = $this->before;
                $this->before = null;
                $before();
            }

            return parent::next($scopeKey);
        }
    };
    $allocator->before = static fn () => InvoiceFixtures::issued(InvoiceFixtures::client());
    app()->instance(SequenceAllocator::class, $allocator);

    $errors = issueRefused(fn () => app(IssueDocument::class)->handle($admin, $draft));

    expect($allocator->before)->toBeNull()
        ->and(array_keys($errors))->toBe(['issue_date_before_latest'])
        ->and(issueCounters())->toBe($counters)
        ->and(issueSystem(static fn (): ?string => Invoice::query()->whereKey($draft->id)->firstOrFail()->number))->toBeNull();
});

it('stores the CNB rate, unit, list date and CZK total of a foreign-currency document', function (string $rate, int $unit): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'Europe/Prague'));
    InvoiceFixtures::cnbRate('EUR', '2026-10-15', '24.0000000000', $unit);
    InvoiceFixtures::cnbRate('EUR', '2026-10-16', $rate, $unit);
    $admin = Canary::admin();
    $client = InvoiceFixtures::client(['currency' => 'EUR']);
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Work', '2', '100.00')], actor: $admin);

    $issued = app(IssueDocument::class)->handle($admin, $draft);
    $stored = issueSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $issued->id)->firstOrFail());

    expect($issued->currency)->toBe('EUR')
        ->and($issued->total_minor)->toBe(20000)
        ->and($issued->issue_date?->format('Y-m-d'))->toBe('2026-10-18')
        ->and($issued->bank_snapshot)->toMatchArray(['currency' => 'EUR'])
        ->and((string) $stored->exchange_rate)->toBe($rate)
        ->and($stored->exchange_unit_amount)->toBe($unit)
        ->and($stored->exchange_rate_date?->format('Y-m-d'))->toBe('2026-10-16')
        ->and($stored->czk_total_minor)->toBe($issued->total()->convert($rate, 'CZK', $unit)->minor)
        ->and($stored->czk_total_minor)->toBe(502500);
})->with([
    'one unit' => ['25.1250000000', 1],
    'one hundred units' => ['2512.5000000000', 100],
]);

it('stores no CNB figure and no CZK total for a CZK document', function (): void {
    $admin = Canary::admin();
    $issued = app(IssueDocument::class)->handle($admin, InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '1', '100')], actor: $admin));
    $stored = issueSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $issued->id)->firstOrFail());

    expect([$stored->exchange_rate, $stored->exchange_unit_amount, $stored->exchange_rate_date, $stored->czk_total_minor])->toBe([null, null, null, null]);
});

it('fails the issue and numbers nothing when the stored logo cannot be read', function (): void {
    $admin = Canary::admin();
    $logo = app(DocumentImage::class)->store(issueImage(40), 'logo');
    issueSupplierImage('logo', $logo);
    $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '1', '100')], actor: $admin);
    Storage::disk('local')->delete($logo);
    $counters = issueCounters();

    expect(fn () => app(IssueDocument::class)->handle($admin, $draft))->toThrow(RuntimeException::class)
        ->and(issueCounters())->toBe($counters)
        ->and(issueSystem(static fn (): string => Invoice::query()->whereKey($draft->id)->firstOrFail()->state->value))->toBe('draft');
});
