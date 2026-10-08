<?php

declare(strict_types=1);

use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Settings\SettingsMigration;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\SettingsProperty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

it('shows a Partner only the rows of the groups on the allowlist and nothing else', function (): void {
    $system = app(PartnerContext::class);
    $system->runAsSystem(static function (): void {
        foreach (['payments' => 'bank', 'ledger' => 'books'] as $group => $name) {
            SettingsProperty::query()->create(['group' => $group, 'name' => $name, 'payload' => json_encode('fictional', JSON_THROW_ON_ERROR)]);
        }
    });

    $widened = new class extends SettingsProperty
    {
        public const array PARTNER_VISIBLE_GROUPS = ['payments'];
    };

    [$clientA] = Canary::twoClients();
    $this->actingAs(Canary::partnerFor($clientA));

    /** @var Builder<SettingsProperty> $query */
    $query = $widened::query();

    expect(SettingsProperty::PARTNER_VISIBLE_GROUPS)->toBe([])
        ->and(SettingsProperty::query()->count())->toBe(0)
        ->and($query->pluck('group')->all())->toBe(['payments']);
});

it('keeps the settings cache off by a literal value and reads no environment variable', function (): void {
    $source = (string) file_get_contents(config_path('settings.php'));
    $calls = 0;
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'env' && ($tokens[$index + 1] ?? null) === '(') {
            $calls++;
        }
    }

    expect(config('settings.cache.enabled'))->toBeFalse()
        ->and(config('settings.cache.memo'))->toBeFalse()
        ->and($calls)->toBe(0);
});

it('runs a settings migration in the system context so it reads and updates stored values', function (): void {
    $flags = [];
    DB::listen(function (QueryExecuted $query) use (&$flags): void {
        if (preg_match('/^select .* from "settings"/', $query->sql) === 1) {
            $flags[] = app(PartnerContext::class)->isSystem();
        }
    });

    $migration = new class extends SettingsMigration
    {
        protected function migrate(): void
        {
            $this->migrator->update('supplier.company_name', static fn (string $name): string => $name.' Updated');
        }
    };

    expect(auth()->check())->toBeFalse();

    $migration->up();

    $name = app(PartnerContext::class)->runAsSystem(static fn (): string => app(SupplierSettings::class)->company_name);

    expect($flags)->not->toBeEmpty()
        ->and(array_unique($flags))->toBe([true])
        ->and($name)->toBe(' Updated')
        ->and(app(PartnerContext::class)->isSystem())->toBeFalse();
});

it('declares a class extending the system-context base in every file under database/settings', function (): void {
    $files = glob(database_path('settings/*.php'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(require $file)->toBeInstanceOf(SettingsMigration::class, basename($file));
    }
});

it('hands a new resolution the value saved meanwhile after the scoped instances are forgotten', function (): void {
    $this->actingAs(Canary::admin());
    saveFictionalSupplier(exampleEmail());

    $first = app(SupplierSettings::class);
    expect($first->company_name)->toBe('Example s.r.o.');

    DB::table('settings')
        ->where('group', 'supplier')
        ->where('name', 'company_name')
        ->update(['payload' => json_encode('Changed Example s.r.o.', JSON_THROW_ON_ERROR)]);

    app()->forgetScopedInstances();

    expect(app(SupplierSettings::class)->company_name)->toBe('Changed Example s.r.o.');
});

it('refuses a supplier country in lower case outside the form and keeps the stored one', function (): void {
    $this->actingAs(Canary::admin());
    saveFictionalSupplier(exampleEmail());
    app()->forgetScopedInstances();

    $settings = app(SupplierSettings::class);
    $settings->country = 'cz';

    expect(fn () => $settings->save())->toThrow(ValidationException::class);

    app()->forgetScopedInstances();

    expect(app(SupplierSettings::class)->country)->toBe('CZ');
});

it('refuses a company id of seven digits and names the field', function (): void {
    $this->actingAs(Canary::admin());
    saveFictionalSupplier(exampleEmail());
    app()->forgetScopedInstances();

    $settings = app(SupplierSettings::class);
    $settings->company_id = '1234567';

    try {
        $settings->save();
        $errors = [];
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    app()->forgetScopedInstances();

    expect(array_keys($errors))->toBe(['company_id'])
        ->and(app(SupplierSettings::class)->company_id)->toBe('12345678');
});

it('writes nothing for a settings class filled with invalid values before it ever saved', function (): void {
    $this->actingAs(Canary::admin());
    $before = SettingsProperty::query()->where('group', 'supplier')->pluck('payload', 'name')->all();

    $settings = app(SupplierSettings::class);
    $settings->company_name = '';
    $settings->vat_id = 'not a vat id';

    expect(fn () => $settings->save())->toThrow(ValidationException::class)
        ->and(SettingsProperty::query()->where('group', 'supplier')->pluck('payload', 'name')->all())->toBe($before);
});

it('ignores keys of a form state that are not declared properties', function (): void {
    $this->actingAs(Canary::admin());
    $settings = app(SupplierSettings::class);

    $settings->fillFromFormState(['company_name' => 'Example s.r.o.', 'is_admin' => true, 'unknown' => 'x']);

    expect($settings->company_name)->toBe('Example s.r.o.')
        ->and($settings->toArray())->not->toHaveKeys(['is_admin', 'unknown']);
});
