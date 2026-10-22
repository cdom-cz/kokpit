<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
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

    /**
     * A complete fictional supplier and a CZK and a EUR bank account, saved in the settings, so a draft passes the
     * supplier and bank blockers of issuing. The company number and the IBANs are composed at runtime.
     */
    public static function configureSupplier(): void
    {
        app(PartnerContext::class)->runAsSystem(static function (): void {
            $supplier = app(SupplierSettings::class);
            $supplier->company_name = 'Example Supplier s.r.o.';
            $supplier->street = 'Sample Street 10';
            $supplier->city = 'Sampletown';
            $supplier->postal_code = '100 00';
            $supplier->country = 'CZ';
            $supplier->company_id = FictionalCompanyId::valid();
            $supplier->vat_id = null;
            $supplier->email = exampleEmail('billing');
            $supplier->phone = null;
            $supplier->website = 'https://www.example.com';
            $supplier->registration_note = 'Registered in the example register.';
            $supplier->save();

            $banks = app(BankAccountSettings::class);
            $banks->accounts = [
                new BankAccount(BankAccountFormat::Europe2, 'Example CZK account', 'CZK', iban: FictionalIban::czech('12')),
                new BankAccount(BankAccountFormat::Europe2, 'Example EUR account', 'EUR', iban: FictionalIban::czech('34')),
            ];
            $banks->save();
        });

        app()->forgetScopedInstances();
    }

    /**
     * One stored CNB list row, as the download writes it.
     */
    public static function cnbRate(string $currency, string $day, string $rate, int $unitAmount = 1): void
    {
        app(PartnerContext::class)->runAsSystem(static function () use ($currency, $day, $rate, $unitAmount): void {
            ExchangeRate::query()->insert([
                'rate_date' => $day,
                'currency' => $currency,
                'unit_amount' => $unitAmount,
                'rate' => $rate,
                'fetched_at' => $day.' 14:45:00+00',
                'created_at' => $day.' 14:45:00+00',
                'updated_at' => $day.' 14:45:00+00',
            ]);
        });
    }

    /**
     * An issued document of the client, built through the real issue pipeline as the Admin. The supplier is
     * configured when it is not yet; with no items one line of 1 000 in the document currency is used.
     *
     * @param  list<array<string, mixed>>  $items  lines built with {@see InvoiceFixtures::item()}
     */
    public static function issued(Client $client, array $items = [], InvoiceKind $kind = InvoiceKind::Invoice): Invoice
    {
        if (trim(app(PartnerContext::class)->runAsSystem(static fn (): string => app(SupplierSettings::class)->company_name)) === '') {
            self::configureSupplier();
        }

        $admin = Canary::admin();
        $items = $items === [] ? [self::item('Example work', '1', '1000.00')] : $items;

        return app(IssueDocument::class)->handle($admin, self::draft($client, $items, $kind, $admin));
    }

    /**
     * Locks an issued invoice or proforma the way the sent e-mail does (IN-11): a model save of `lock_state` as
     * system, nothing else on the row. Returns the refreshed document.
     */
    public static function lock(Invoice $issued): Invoice
    {
        return app(PartnerContext::class)->runAsSystem(static function () use ($issued): Invoice {
            $row = Invoice::query()->whereKey($issued->id)->firstOrFail();
            $row->forceFill(['lock_state' => InvoiceLockState::Locked])->save();

            return $row->refresh();
        });
    }

    /**
     * An issued document that is locked: issued through the real pipeline, then locked like a sent one.
     *
     * @param  list<array<string, mixed>>  $items  lines built with {@see InvoiceFixtures::item()}
     */
    public static function lockedIssued(Client $client, array $items = [], InvoiceKind $kind = InvoiceKind::Invoice): Invoice
    {
        return self::lock(self::issued($client, $items, $kind));
    }

    /**
     * The issued full-storno credit note of an issued invoice, built as system with the only writes the schema
     * allows (plan 10-04 R-04-5): a draft with its lines, each negating a line of the invoice, its Admin-only row,
     * then the flip to issued. Nothing is written on the invoice itself, which is cancelled afterwards because an
     * issued credit note with `cancels_invoice` names it. Plan 10-23 builds the same document with its Action.
     */
    public static function stornoed(Invoice $issued): Invoice
    {
        return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => DB::transaction(static function () use ($issued): Invoice {
            $source = Invoice::query()->whereKey($issued->id)->firstOrFail();
            $sourceAdmin = InvoiceAdmin::query()->where('invoice_id', $source->id)->first();

            $credit = new Invoice;
            $credit->forceFill([
                'client_id' => $source->client_id,
                'kind' => InvoiceKind::CreditNote,
                'currency' => $source->currency,
                'language' => $source->language,
                'vat_mode' => $source->vat_mode,
                'credited_invoice_id' => $source->id,
                'cancels_invoice' => true,
                'subtotal_minor' => -$source->subtotal_minor,
                'vat_minor' => -$source->vat_minor,
                'total_minor' => -$source->total_minor,
            ])->save();

            foreach (InvoiceItem::query()->where('invoice_id', $source->id)->orderBy('position')->get() as $line) {
                $negated = new InvoiceItem;
                $negated->forceFill([
                    'invoice_id' => $credit->id,
                    'position' => $line->position,
                    'description' => $line->description,
                    'quantity' => '-'.ltrim($line->quantity, '-'),
                    'unit' => $line->unit,
                    'unit_price_minor' => $line->unit_price_minor,
                    'line_total_minor' => -$line->line_total_minor,
                    'price_converted' => $line->price_converted,
                    'vat_rate' => $line->vat_rate,
                    'vat_minor' => -$line->vat_minor,
                    'credited_item_id' => $line->id,
                ])->save();
            }

            $admin = new InvoiceAdmin;
            $admin->forceFill([
                'invoice_id' => $credit->id,
                'exchange_rate' => $sourceAdmin?->exchange_rate,
                'exchange_unit_amount' => $sourceAdmin?->exchange_unit_amount,
                'exchange_rate_date' => $sourceAdmin?->exchange_rate_date,
                'czk_total_minor' => $sourceAdmin?->czk_total_minor === null ? null : -$sourceAdmin->czk_total_minor,
            ])->save();

            $credit->forceFill([
                'state' => InvoiceState::Issued,
                'lock_state' => InvoiceLockState::Locked,
                'number' => app(DocumentNumbering::class)->next(DocumentKind::CreditNote, CarbonImmutable::now(FilamentTimezone::get())),
                'issue_date' => CarbonImmutable::now(FilamentTimezone::get())->format('Y-m-d'),
                'supplier_snapshot' => $source->supplier_snapshot,
                'customer_snapshot' => $source->customer_snapshot,
                'appearance_snapshot' => $source->appearance_snapshot,
                'issued_at' => now(),
            ])->save();

            return $credit->refresh();
        }));
    }
}
