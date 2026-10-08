<?php

declare(strict_types=1);

use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\SettingsProperty;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Tests\Support\Canary;

/*
 * Typed settings on the UUID v7 conventions (D-01): stored in the settings
 * table through the SettingsProperty model, readable by the Admin and the
 * system context, invisible to a Partner.
 */

/**
 * Fills the supplier settings with fictional values and saves them.
 */
function saveFictionalSupplier(string $email): void
{
    $settings = app(SupplierSettings::class);
    $settings->company_name = 'Example s.r.o.';
    $settings->street = 'Sample Street 1';
    $settings->city = 'Sampletown';
    $settings->postal_code = '10000';
    $settings->country = 'CZ';
    $settings->company_id = '12345678';
    $settings->vat_id = null;
    $settings->email = $email;
    $settings->phone = null;
    $settings->website = null;
    $settings->registration_note = 'Registered in the example register.';
    $settings->save();
}

it('stores supplier settings saved as Admin and returns them from a fresh resolution', function (): void {
    $email = exampleEmail();
    $this->actingAs(Canary::admin());

    saveFictionalSupplier($email);
    app()->forgetScopedInstances();

    $fresh = app(SupplierSettings::class);

    expect($fresh->company_name)->toBe('Example s.r.o.')
        ->and($fresh->company_id)->toBe('12345678')
        ->and($fresh->email)->toBe($email)
        ->and($fresh->country)->toBe('CZ')
        ->and($fresh->vat_id)->toBeNull();
});

it('keeps settings rows on a uuid v7 key with timestamptz timestamps and a jsonb payload', function (): void {
    $this->actingAs(Canary::admin());
    saveFictionalSupplier(exampleEmail());

    $row = SettingsProperty::query()->where('group', 'supplier')->where('name', 'company_name')->firstOrFail();

    expect($row->getKey())->toBeString()
        ->and(substr((string) $row->getKey(), 14, 1))->toBe('7');

    $types = DB::table('information_schema.columns')
        ->where('table_name', 'settings')
        ->whereIn('column_name', ['created_at', 'updated_at', 'payload', 'id'])
        ->pluck('data_type', 'column_name')
        ->all();
    ksort($types);

    expect($types)->toBe([
        'created_at' => 'timestamp with time zone',
        'id' => 'uuid',
        'payload' => 'jsonb',
        'updated_at' => 'timestamp with time zone',
    ]);
});

it('registers the UUID model as the settings repository model', function (): void {
    expect(config('settings.repositories.database.model'))->toBe(SettingsProperty::class)
        ->and(config('settings.settings'))->toContain(SupplierSettings::class);
});

it('hides the stored supplier settings from a Partner and fails closed', function (): void {
    $this->actingAs(Canary::admin());
    saveFictionalSupplier(exampleEmail());
    app()->forgetScopedInstances();

    [$clientA] = Canary::twoClients();
    $this->actingAs(Canary::partnerFor($clientA));

    expect(SettingsProperty::query()->count())->toBe(0)
        ->and(fn (): string => app(SupplierSettings::class)->company_name)->toThrow(MissingSettings::class);
});

it('returns the stored supplier settings inside the system context without a user', function (): void {
    $this->actingAs(Canary::admin());
    saveFictionalSupplier(exampleEmail());
    app()->forgetScopedInstances();
    auth()->logout();

    $name = app(PartnerContext::class)->runAsSystem(static fn (): string => app(SupplierSettings::class)->company_name);

    expect($name)->toBe('Example s.r.o.');
});
