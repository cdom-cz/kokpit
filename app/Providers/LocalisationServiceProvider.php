<?php

declare(strict_types=1);

namespace App\Providers;

use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

/**
 * Czech display conventions, set once for the whole application (FND-11).
 *
 * Storage stays UTC (config app.timezone); only the display layer converts to
 * Europe/Prague. Every Filament table, schema and date picker inherits the
 * Czech date, time, number and currency defaults from here, so no screen has to
 * repeat them and none can drift.
 */
class LocalisationServiceProvider extends ServiceProvider
{
    public const string DATE_FORMAT = 'j. n. Y';

    public const string DATE_TIME_FORMAT = 'j. n. Y H:i';

    public const string DATE_TIME_SECONDS_FORMAT = 'j. n. Y H:i:s';

    public const string TIME_FORMAT = 'H:i';

    public const string TIME_SECONDS_FORMAT = 'H:i:s';

    public function boot(): void
    {
        FilamentTimezone::set('Europe/Prague');

        Table::configureUsing(fn (Table $table): Table => $table
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            ->defaultTimeDisplayFormat(self::TIME_FORMAT)
            ->defaultNumberLocale('cs')
            ->defaultCurrency('CZK'));

        Schema::configureUsing(fn (Schema $schema): Schema => $schema
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            ->defaultTimeDisplayFormat(self::TIME_FORMAT)
            ->defaultNumberLocale('cs')
            ->defaultCurrency('CZK'));

        // DatePicker and TimePicker extend DateTimePicker and pick the date, date-time or time
        // default by what they show (with or without seconds), so one configuration covers all.
        DateTimePicker::configureUsing(fn (DateTimePicker $picker): DateTimePicker => $picker
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            ->defaultDateTimeWithSecondsDisplayFormat(self::DATE_TIME_SECONDS_FORMAT)
            ->defaultTimeDisplayFormat(self::TIME_FORMAT)
            ->defaultTimeWithSecondsDisplayFormat(self::TIME_SECONDS_FORMAT));

        Number::useLocale('cs');
        Number::useCurrency('CZK');
    }
}
