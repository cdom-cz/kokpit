<?php

declare(strict_types=1);

use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Money\Money;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * Data-layer protection of the settings groups beyond the supplier data: what
 * the settings page cannot enforce on its own, because code outside the form
 * can write settings too.
 */

/**
 * @return array<string, mixed>
 */
function storedDefaults(): array
{
    return SettingsProperty::query()->where('group', 'defaults')->pluck('payload', 'name')->all();
}

it('starts with CZK as default currency and a zero CZK rate after migrate', function (): void {
    $this->actingAs(Canary::admin());

    $defaults = app(DefaultsSettings::class);

    expect($defaults->default_currency)->toBe('CZK')
        ->and($defaults->default_hourly_rate->equals(Money::zero('CZK')))->toBeTrue();
});

it('stores the default rate as integer minor units plus currency and reads it back', function (): void {
    $this->actingAs(Canary::admin());

    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = 'EUR';
    $defaults->default_hourly_rate = Money::ofMinor(8550, 'EUR');
    $defaults->save();
    app()->forgetScopedInstances();

    $fresh = app(DefaultsSettings::class);

    expect($fresh->default_currency)->toBe('EUR')
        ->and($fresh->default_hourly_rate->equals(Money::ofMinor(8550, 'EUR')))->toBeTrue()
        ->and(json_decode((string) SettingsProperty::query()->where('group', 'defaults')->where('name', 'default_hourly_rate')->value('payload'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['minor' => 8550, 'currency' => 'EUR']);
});

it('refuses a default rate in another currency than the default currency and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedDefaults();

    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = 'CZK';
    $defaults->default_hourly_rate = Money::ofMinor(8550, 'EUR');

    expect(fn () => $defaults->save())->toThrow(ValidationException::class);
    expect(storedDefaults())->toBe($before);
});

it('refuses a negative default rate and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedDefaults();

    $defaults = app(DefaultsSettings::class);
    $defaults->default_hourly_rate = Money::ofMinor(-100, 'CZK');

    expect(fn () => $defaults->save())->toThrow(ValidationException::class);
    expect(storedDefaults())->toBe($before);
});

it('refuses an unknown default currency and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $before = storedDefaults();

    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = 'XYZ';
    $defaults->default_hourly_rate = Money::zero('CZK');

    expect(fn () => $defaults->save())->toThrow(ValidationException::class);
    expect(storedDefaults())->toBe($before);
});

it('names the rate field when the class refuses a mismatching currency', function (): void {
    $this->actingAs(Canary::admin());

    $defaults = app(DefaultsSettings::class);
    $defaults->default_currency = 'CZK';
    $defaults->default_hourly_rate = Money::ofMinor(100, 'EUR');

    try {
        $defaults->save();
        $this->fail('The mismatching currency was saved.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['default_hourly_rate']);
    }
});

it('turns a rate text that Money refuses into a field error instead of an exception of another kind', function (): void {
    $this->actingAs(Canary::admin());

    $defaults = app(DefaultsSettings::class);

    try {
        $defaults->fillFromFormState(['default_currency' => 'CZK', 'default_hourly_rate' => '12,345']);
        $this->fail('The excess decimals were accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['default_hourly_rate']);
    }
});
