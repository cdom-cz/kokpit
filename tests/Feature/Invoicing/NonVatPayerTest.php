<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\SettingsProperty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The non-VAT-payer mode, the default and the only one built (IN-08; D-08, ruling R-17-3): data, issue and PDF carry no
 * VAT at all, the database refuses a VAT amount on a non-payer document, and the payer mode blocks issuing while its
 * calculations are deferred. Every name and amount is fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Stores the VAT mode as the settings table holds it, the way a database owner would: the settings class itself
 * refuses the payer mode, so only a stored value can put the application into it. Returns the previous payload.
 */
function nonPayerStoreMode(string $mode): string
{
    $previous = app(PartnerContext::class)->runAsSystem(static function () use ($mode): string {
        $row = SettingsProperty::query()->where('group', 'invoicing')->where('name', 'vat_mode')->firstOrFail();
        $previous = (string) $row->getRawOriginal('payload');

        SettingsProperty::query()->where('group', 'invoicing')->where('name', 'vat_mode')->update(['payload' => json_encode($mode, JSON_THROW_ON_ERROR)]);

        return $previous;
    });

    app()->forgetInstance(InvoicingSettings::class);
    app()->forgetScopedInstances();

    return $previous;
}

describe('the default non-payer mode', function (): void {
    it('issues invoices and proformas without any VAT', function (): void {
        foreach ([InvoiceKind::Invoice, InvoiceKind::Proforma] as $kind) {
            $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [
                InvoiceFixtures::item('Consulting', '3', '1250.00'),
                InvoiceFixtures::item('Licence', '1', '499.90', 'ks'),
            ], $kind);

            $stored = app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($issued->id));

            expect($stored->vat_mode->value)->toBe('non_payer')
                ->and($stored->vat_minor)->toBe(0)
                ->and($stored->subtotal_minor)->toBe(424990)
                ->and($stored->total_minor)->toBe($stored->subtotal_minor)
                ->and($stored->taxable_supply_date)->toBeNull();

            $items = app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $issued->id)->get()->all());

            expect($items)->toHaveCount(2);

            foreach ($items as $item) {
                expect($item->vat_rate)->toBeNull()
                    ->and($item->vat_minor)->toBe(0);
            }
        }
    });

    it('prints the non-payer note and no VAT column or taxable-supply date on the PDF', function (): void {
        foreach (['cs' => ['Dodavatel není plátce DPH.', 'DPH %', 'Datum zdanitelného plnění'], 'en' => ['The supplier is not a VAT payer.', 'VAT %', 'Taxable supply date']] as $language => [$note, $column, $supply]) {
            $client = InvoiceFixtures::client(['invoice_language' => $language]);
            $issued = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Consulting', '2', '500.00')]);

            $html = app(InvoicePdf::class)->html($issued);

            expect($html)->toContain($note)
                ->and($html)->not->toContain($column)
                ->and($html)->not->toContain($supply);
        }
    });

    it('is refused by the database when a VAT amount is stored on a non-payer document', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '1000.00')]);
        $item = app(PartnerContext::class)->runAsSystem(static fn (): InvoiceItem => InvoiceItem::query()->where('invoice_id', $draft->id)->firstOrFail());

        RawSql::expectSqlState('23514', static fn () => DB::update('UPDATE invoices SET vat_minor = 100, total_minor = subtotal_minor + 100 WHERE id = ?', [$draft->id]));
        RawSql::expectSqlState('23514', static fn () => DB::update('UPDATE invoice_items SET vat_minor = 10 WHERE id = ?', [$item->id]));

        $stored = app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($draft->id));

        expect($stored->vat_minor)->toBe(0)
            ->and($stored->total_minor)->toBe(100000);
    });
});

describe('the payer mode while its calculations are deferred', function (): void {
    it('blocks issuing with the Czech message and consumes nothing, and issuing works again in non-payer mode', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '1000.00')]);
        $sequences = DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all();

        $previous = nonPayerStoreMode('payer');

        $keys = array_column(app(IssueChecks::class)->blockers($draft, '2026-10-14'), 'message', 'key');

        expect($keys)->toHaveKey('vat_payer_unsupported')
            ->and($keys['vat_payer_unsupported'])->toBe('Režim plátce DPH zatím není podporován. Přepněte režim DPH v Nastavení.');

        try {
            app(IssueDocument::class)->handle($this->admin, $draft);
            $this->fail('The issue had to be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('vat_payer_unsupported')
                ->and($exception->errors()['vat_payer_unsupported'][0])->toBe('Režim plátce DPH zatím není podporován. Přepněte režim DPH v Nastavení.');
        }

        expect(app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($draft->id))->state->value)->toBe('draft')
            ->and(DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($sequences);

        app(PartnerContext::class)->runAsSystem(static fn (): int => SettingsProperty::query()->where('group', 'invoicing')->where('name', 'vat_mode')->update(['payload' => $previous]));
        app()->forgetInstance(InvoicingSettings::class);
        app()->forgetScopedInstances();

        $issued = app(IssueDocument::class)->handle($this->admin, $draft);

        expect($issued->state->value)->toBe('issued')
            ->and($issued->vat_mode->value)->toBe('non_payer');
    });
});
