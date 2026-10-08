<?php

declare(strict_types=1);

use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Shared\Sequences\SequenceAllocator;
use App\Filament\Pages\SettingsPage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The numbering section of the invoicing tab (D-05): the Admin edits the
 * patterns with a live preview, and the page never consumes a number.
 *
 * Numbers are assembled from fragments so no line carries a long digit run.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

/**
 * Everything in the counter table, ordered, for before/after comparisons.
 *
 * @return list<array{string, int}>
 */
function sequenceSnapshot(): array
{
    return DB::table('number_sequences')->orderBy('scope_key')->get()
        ->map(fn (object $row): array => [(string) $row->scope_key, (int) $row->next_value])
        ->all();
}

/**
 * The current year in the numbering time zone.
 */
function pragueYear(): string
{
    return now('Europe/Prague')->format('Y');
}

/**
 * Takes numbers from a counter the way an issuing transaction does.
 */
function takeNumbers(string $scopeKey, int $count): void
{
    $allocator = new SequenceAllocator;

    DB::transaction(function () use ($allocator, $scopeKey, $count): void {
        for ($i = 0; $i < $count; $i++) {
            $allocator->next($scopeKey);
        }
    });
}

/**
 * The preview line as the page words it.
 */
function previewLine(string $number): string
{
    return __('kokpit.settings.numbering.preview', ['number' => $number]);
}

/**
 * A complete supplier block, so a save of the whole page passes.
 *
 * @return array<string, string|null>
 */
function numberingPageSupplier(): array
{
    return [
        'company_name' => 'Example s.r.o.',
        'street' => 'Sample Street 1',
        'city' => 'Sampletown',
        'postal_code' => '10000',
        'country' => 'CZ',
        'company_id' => '12345678',
        'vat_id' => null,
        'email' => exampleEmail(),
        'phone' => null,
        'website' => null,
        'registration_note' => 'Registered in the example register.',
    ];
}

it('shows the next invoice number of the stored pattern on the invoicing tab without touching the counters', function (): void {
    takeNumbers('invoice:'.pragueYear(), 2);
    $before = sequenceSnapshot();

    Livewire::test(SettingsPage::class)
        ->assertSeeText(previewLine(pragueYear().'0003'));

    expect(sequenceSnapshot())->toBe($before);
});

it('updates the preview while the pattern is edited and still leaves the counters alone', function (): void {
    takeNumbers('invoice:'.pragueYear(), 2);
    $before = sequenceSnapshot();

    Livewire::test(SettingsPage::class)
        ->set('data.numbering.invoice_pattern', '{YYYY}{NNNNN}')
        ->assertSeeText(previewLine(pragueYear().'00003'))
        ->set('data.numbering.invoice_pattern', '{YY}{NNNNNN}')
        ->assertSeeText(previewLine(now('Europe/Prague')->format('y').'000003'));

    expect(sequenceSnapshot())->toBe($before);
});

it('stores the saved pattern and the next allocation follows it', function (): void {
    takeNumbers('invoice:'.pragueYear(), 2);

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => numberingPageSupplier(),
            'numbering' => ['invoice_pattern' => '{YYYY}{NNNNN}'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    $number = DB::transaction(fn (): string => app(DocumentNumbering::class)->next(DocumentKind::Invoice, now()));

    expect(app(NumberingSettings::class)->invoice_pattern)->toBe('{YYYY}{NNNNN}')
        ->and($number)->toBe(pragueYear().'00003');
});
