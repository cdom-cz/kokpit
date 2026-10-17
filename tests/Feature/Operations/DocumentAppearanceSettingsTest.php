<?php

declare(strict_types=1);

use App\Domain\Invoicing\Pdf\DocumentPalette;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Shared\Models\SettingsProperty;
use App\Filament\Pages\SettingsPage;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Document appearance settings (D-24): the accent colour, the footer and the
 * two images are typed settings validated at the data layer and edited on the
 * one settings page.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * @return array<string, string|null>
 */
function appearanceSupplierState(): array
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
        'registration_note' => null,
    ];
}

/**
 * @param  array<string, mixed>  $invoicing
 * @return array<string, array<string, mixed>>
 */
function appearanceState(array $invoicing): array
{
    return [
        'supplier' => appearanceSupplierState(),
        'invoicing' => ['vat_mode' => 'non_payer', 'payment_due_days' => 14, ...$invoicing],
    ];
}

/**
 * @return array<string, string>
 */
function storedInvoicing(): array
{
    return SettingsProperty::query()->where('group', 'invoicing')->pluck('payload', 'name')->all();
}

it('starts with the default accent colour and no footer after migrate', function (): void {
    $this->actingAs(Canary::admin());

    $invoicing = app(InvoicingSettings::class);

    expect($invoicing->document_accent_color)->toBe('#ff6f00')
        ->and($invoicing->document_accent_color)->toBe(DocumentPalette::DEFAULT_ACCENT)
        ->and($invoicing->document_footer)->toBeNull();
});

it('saves the accent colour on the page and shows it after a reload', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->assertSet('data.invoicing.document_accent_color', '#ff6f00')
        ->fillForm(appearanceState(['document_accent_color' => '#1d4ed8']))
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('kokpit.settings.saved'));

    app()->forgetScopedInstances();

    expect(app(InvoicingSettings::class)->document_accent_color)->toBe('#1d4ed8');

    app()->forgetScopedInstances();

    Livewire::test(SettingsPage::class)
        ->assertSet('data.invoicing.document_accent_color', '#1d4ed8');
});

it('stores an accent typed in upper case in lower case', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState([]))
        ->set('data.invoicing.document_accent_color', '#1D4ED8')
        ->assertSet('data.invoicing.document_accent_color', '#1d4ed8')
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(app(InvoicingSettings::class)->document_accent_color)->toBe('#1d4ed8');
});

it('normalises the accent from form state to lower case before the data layer checks it', function (): void {
    $this->actingAs(Canary::admin());

    $invoicing = app(InvoicingSettings::class)->fillFromFormState(['document_accent_color' => ' #1D4ED8 ']);

    expect($invoicing->document_accent_color)->toBe('#1d4ed8');
});

it('refuses an accent that is not #rrggbb with the Czech reason under the field and changes nothing', function (string $typed): void {
    $this->actingAs(Canary::admin());
    $before = storedInvoicing();

    $page = Livewire::test(SettingsPage::class)
        ->fillForm(appearanceState(['document_accent_color' => $typed]))
        ->call('save')
        ->assertHasFormErrors(['invoicing.document_accent_color']);

    expect($page->errors()->first('data.invoicing.document_accent_color'))->toBe('Zadejte barvu ve tvaru #rrggbb.')
        ->and(storedInvoicing())->toBe($before);
})->with(['#12345', 'red', '', '#12345g', '#1234567', 'ff6f00', '#ff6f00; background:url(x)']);

it('refuses a bad accent at the data layer when the class is saved outside the form', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedInvoicing();

    $invoicing = app(InvoicingSettings::class);
    $invoicing->document_accent_color = '#FF6F00';

    expect(fn () => $invoicing->save())->toThrow(ValidationException::class)
        ->and(storedInvoicing())->toBe($before);
});

it('derives the on-accent text of the default accent as the ink colour', function (): void {
    $palette = DocumentPalette::fromAccent('#ff6f00');

    expect($palette->onAccent)->toBe($palette->ink);
});

it('refuses the settings page to a Partner, including the appearance fields', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]))
        ->get('/admin/settings')
        ->assertForbidden();
});
