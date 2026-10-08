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

/**
 * The stored patterns, read straight from the settings table.
 *
 * @return array<string, string>
 */
function storedPatterns(): array
{
    return DB::table('settings')->where('group', 'numbering')->orderBy('name')->get()
        ->mapWithKeys(fn (object $row): array => [(string) $row->name => (string) json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR)])
        ->all();
}

it('shows the Czech reason on the invoice pattern field and stores nothing for an invalid pattern', function (): void {
    $before = storedPatterns();
    $counters = sequenceSnapshot();

    $page = Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => numberingPageSupplier(),
            'numbering' => ['invoice_pattern' => 'INV{YYYY}{NNNN}'],
        ])
        ->call('save')
        ->assertHasFormErrors(['numbering.invoice_pattern']);

    expect($page->errors()->get('data.numbering.invoice_pattern'))->toContain(__('kokpit.settings.numbering.errors.invoice_digits'))
        ->and(storedPatterns())->toBe($before)
        ->and(sequenceSnapshot())->toBe($counters);
});

it('refuses an invalid pattern of each document kind with its own reason', function (string $field, string $pattern, string $reason): void {
    $before = storedPatterns();

    $page = Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => numberingPageSupplier(),
            'numbering' => [$field => $pattern],
        ])
        ->call('save')
        ->assertHasFormErrors(["numbering.{$field}"]);

    expect($page->errors()->get("data.numbering.{$field}"))->toContain(__("kokpit.settings.numbering.errors.{$reason}"))
        ->and(storedPatterns())->toBe($before);
})->with([
    'proforma without a counter' => ['proforma_pattern', 'PF-{YYYY}', 'counter_count'],
    'credit note with an unknown token' => ['credit_note_pattern', 'CN{XX}{NNNN}', 'unknown_token'],
    'invoice month without a year' => ['invoice_pattern', '{MM}{NNNN}', 'month_without_year'],
]);

it('warns in Czech that numbering restarts at 1 when the reset period changes and says nothing otherwise', function (): void {
    $warning = __('kokpit.settings.numbering.reset_warning');

    Livewire::test(SettingsPage::class)
        ->assertDontSeeText($warning)
        ->set('data.numbering.invoice_pattern', '{YY}{NNNNNN}')
        ->assertDontSeeText($warning)
        ->set('data.numbering.invoice_pattern', '{YYYY}{MM}{NNNN}')
        ->assertSeeText($warning)
        ->set('data.numbering.invoice_pattern', '{NNNNNNNN}')
        ->assertSeeText($warning)
        ->set('data.numbering.invoice_pattern', '{YYYY}{NNNN}')
        ->assertDontSeeText($warning);
});

it('warns for the proforma and the credit note pattern too, each against its own stored pattern', function (string $field): void {
    $warning = __('kokpit.settings.numbering.reset_warning');

    Livewire::test(SettingsPage::class)
        ->assertDontSeeText($warning)
        ->set("data.numbering.{$field}", 'X-{MM}-{YYYY}')
        ->assertDontSeeText($warning)
        ->set("data.numbering.{$field}", '{YYYY}{MM}-{NNNN}')
        ->assertSeeText($warning);
})->with(['proforma_pattern', 'credit_note_pattern']);

it('shows an own preview under the proforma and the credit note pattern, read from their own counters', function (): void {
    takeNumbers('invoice:'.pragueYear(), 3);
    takeNumbers('proforma:'.pragueYear(), 1);
    $before = sequenceSnapshot();

    Livewire::test(SettingsPage::class)
        ->assertSeeText(previewLine(pragueYear().'0004'))
        ->assertSeeText(previewLine(pragueYear().'0002'))
        ->assertSeeText(previewLine(pragueYear().'0001'));

    expect(sequenceSnapshot())->toBe($before);
});

it('keeps the task pattern disabled and fixed to KEY-N with an explanation', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertFormFieldDisabled('numbering.task_pattern')
        ->assertSet('data.numbering.task_pattern', '{KEY}-{N}')
        ->assertSeeText(__('kokpit.settings.numbering.task_explanation'));
});

it('refuses a crafted payload that sets another task pattern and leaves the stored one', function (): void {
    $before = storedPatterns();

    $page = Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => numberingPageSupplier()])
        ->set('data.numbering.task_pattern', '{YYYY}-{N}')
        ->call('save')
        ->assertHasFormErrors(['numbering.task_pattern']);

    expect($page->errors()->get('data.numbering.task_pattern'))->toContain(__('kokpit.settings.numbering.errors.task_fixed'))
        ->and(storedPatterns())->toBe($before)
        ->and(storedPatterns()['task_pattern'])->toBe('{KEY}-{N}');
});

it('names the allowed tokens in the help text', function (): void {
    $page = Livewire::test(SettingsPage::class);

    foreach (['{YYYY}', '{YY}', '{MM}', '{N}'] as $token) {
        $page->assertSeeText($token);
    }

    $page->assertSeeText(__('kokpit.settings.numbering.title'));
});

it('saves all three document patterns together and leaves the counters alone', function (): void {
    takeNumbers('invoice:'.pragueYear(), 1);
    $counters = sequenceSnapshot();

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'supplier' => numberingPageSupplier(),
            'numbering' => [
                'invoice_pattern' => '{YYYY}{NNNNN}',
                'proforma_pattern' => 'ZF-{YY}{MM}-{NNN}',
                'credit_note_pattern' => 'D{YYYY}/{NNNN}',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(storedPatterns())->toBe([
        'credit_note_pattern' => 'D{YYYY}/{NNNN}',
        'invoice_pattern' => '{YYYY}{NNNNN}',
        'proforma_pattern' => 'ZF-{YY}{MM}-{NNN}',
        'task_pattern' => '{KEY}-{N}',
    ])->and(sequenceSnapshot())->toBe($counters);
});
