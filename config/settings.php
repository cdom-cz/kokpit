<?php

declare(strict_types=1);

use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Models\SettingsProperty;
use Spatie\LaravelSettings\SettingsCasts\DateTimeInterfaceCast;
use Spatie\LaravelSettings\SettingsCasts\DateTimeZoneCast;
use Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository;

/*
 * Typed application settings (D-01). Nothing in this file reads the
 * environment: where settings are stored and whether they are cached is a code
 * decision, not a deployment switch.
 */
return [

    /*
     * Every settings class is registered here explicitly. Auto-discovery is off,
     * so deploys need no discovery cache. Later plans append their classes.
     */
    'settings' => [
        SupplierSettings::class,
        BankAccountSettings::class,
        InvoicingSettings::class,
        DefaultsSettings::class,
        NumberingSettings::class,
        PaymentSettings::class,
    ],

    'setting_class_path' => app_path('Domain/Settings/Settings'),

    /*
     * Settings migrations. Each one extends App\Domain\Settings\SettingsMigration,
     * which runs it in the system context.
     */
    'migrations_paths' => [
        database_path('settings'),
    ],

    'default_repository' => 'database',

    /*
     * Only the database repository exists. Rows are read through the
     * SettingsProperty model, so the fail-closed Partner scope applies.
     */
    'repositories' => [
        'database' => [
            'type' => DatabaseSettingsRepository::class,
            'model' => SettingsProperty::class,
            'table' => null,
            'connection' => null,
        ],
    ],

    'encoder' => null,
    'decoder' => null,

    /*
     * The package cache is off and must stay off: a cached read returns values
     * without touching the SettingsProperty model, so the Partner scope would no
     * longer decide who sees what. No environment switch exists on purpose.
     */
    'cache' => [
        'enabled' => false,
        'store' => null,
        'prefix' => null,
        'ttl' => null,
        'memo' => false,
    ],

    'global_casts' => [
        DateTimeInterface::class => DateTimeInterfaceCast::class,
        DateTimeZone::class => DateTimeZoneCast::class,
    ],

    'auto_discover_settings' => [],

    'discovered_settings_cache_path' => base_path('bootstrap/cache'),
];
