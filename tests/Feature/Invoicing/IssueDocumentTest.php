<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
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

it('refuses every write to an issued row, even a touch', function (): void {
    $admin = Canary::admin();
    $issued = app(IssueDocument::class)->handle($admin, InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '1', '100')], actor: $admin));

    RawSql::expectSqlState('KP001', fn () => $issued->fresh()?->forceFill(['total_minor' => 1])->save());

    // A touch of the same instant changes nothing and sends no statement, so move the clock first.
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
