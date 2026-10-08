<?php

declare(strict_types=1);

use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Models\SettingsProperty;
use App\Filament\Pages\SettingsPage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The one settings page (D-02): an Admin edits the supplier data in Czech and it
 * persists; nobody else gets in.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * @return array<string, string|null>
 */
function fictionalSupplierState(string $email): array
{
    return [
        'company_name' => 'Example s.r.o.',
        'street' => 'Sample Street 1',
        'city' => 'Sampletown',
        'postal_code' => '10000',
        'country' => 'CZ',
        'company_id' => '12345678',
        'vat_id' => null,
        'email' => $email,
        'phone' => null,
        'website' => null,
        'registration_note' => 'Registered in the example register.',
    ];
}

it('saves the supplier data on the page and shows it after a reload', function (): void {
    $email = exampleEmail();
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => fictionalSupplierState($email)])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $fresh = app(SupplierSettings::class);

    expect($fresh->company_name)->toBe('Example s.r.o.')
        ->and($fresh->company_id)->toBe('12345678')
        ->and($fresh->email)->toBe($email)
        ->and(SettingsProperty::query()->where('group', 'supplier')->exists())->toBeTrue();

    app()->forgetScopedInstances();

    Livewire::test(SettingsPage::class)
        ->assertSet('data.supplier.company_name', 'Example s.r.o.')
        ->assertSet('data.supplier.company_id', '12345678')
        ->assertSet('data.supplier.email', $email);
});

it('keeps empty optional fields empty and does not choke on empty required-type strings', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => [
            ...fictionalSupplierState(exampleEmail()),
            'street' => '',
            'company_id' => '',
            'phone' => '',
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    $fresh = app(SupplierSettings::class);

    expect($fresh->street)->toBe('')
        ->and($fresh->company_id)->toBeNull()
        ->and($fresh->phone)->toBeNull();
});

it('refuses invalid supplier values with a field error and writes nothing', function (): void {
    $this->actingAs(Canary::admin());

    Livewire::test(SettingsPage::class)
        ->fillForm(['supplier' => [
            ...fictionalSupplierState(exampleEmail()),
            'company_name' => '',
            'company_id' => '1234567',
        ]])
        ->call('save')
        ->assertHasFormErrors(['supplier.company_name', 'supplier.company_id']);

    app()->forgetScopedInstances();

    expect(app(SupplierSettings::class)->company_name)->toBe('');
});

it('renders the Czech navigation label and tab for the Admin', function (): void {
    $this->actingAs(Canary::admin())->get('/admin/settings')
        ->assertOk()
        ->assertSee(__('kokpit.settings.navigation_label'))
        ->assertSee(__('kokpit.settings.tabs.supplier'))
        ->assertSee(__('kokpit.settings.save'));
});

it('refuses the settings page to a Partner with a client, a Partner without a client and a user without a role', function (): void {
    $states = [
        'partner with a client' => fn () => Canary::partnerFor(Canary::twoClients()[0]),
        'partner without a client' => fn () => Canary::partnerFor(null),
        'user without a role' => fn () => Canary::userWithoutRole(null),
    ];

    foreach ($states as $state => $make) {
        $this->actingAs($make())->get('/admin/settings')->assertForbidden();
    }
});

it('runs no settings query for a Partner before refusing', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->get('/admin/settings')->assertForbidden();

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"settings"')))->toBe([]);
});
