<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * ICU 76 and 78 use U+00A0 for grouping and before the currency symbol, some builds use U+202F;
 * both are folded to a plain space before comparing.
 */
function plainSpaces(string $text): string
{
    return str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
}

function pragueDisplay(string $utcInstant, string $format = 'j. n. Y H:i'): string
{
    return CarbonImmutable::parse($utcInstant, 'UTC')
        ->setTimezone(FilamentTimezone::get())
        ->format($format);
}

it('formats Czech currency with a decimal comma, grouping and the koruna sign', function (): void {
    expect(plainSpaces(Number::currency(1234.5)))->toBe('1 234,50 Kč')
        ->and(plainSpaces(Number::currency(0)))->toBe('0,00 Kč');
});

it('keeps the sign of a negative amount', function (): void {
    $text = plainSpaces(Number::currency(-1234.5));

    expect($text)->toContain('1 234,50')
        ->and(preg_match('/^[-\x{2212}]/u', $text))->toBe(1);
});

it('formats Money from stored minor units without float rounding', function (): void {
    $locale = app()->getLocale();

    expect(plainSpaces(Money::ofMinor(123450, 'CZK')->format($locale)))->toBe('1 234,50 Kč')
        ->and(plainSpaces(Money::ofMinor(1, 'CZK')->format($locale)))->toBe('0,01 Kč')
        ->and(plainSpaces(Money::ofMinor(0, 'CZK')->format($locale)))->toBe('0,00 Kč');
});

it('shows the Prague date for an instant on the UTC day boundary', function (): void {
    expect(pragueDisplay('2026-01-05 23:30:00'))->toBe('6. 1. 2026 00:30')
        ->and(pragueDisplay('2026-01-05 22:59:00'))->toBe('5. 1. 2026 23:59');
});

it('moves the Prague clock across the spring DST switch', function (): void {
    expect(pragueDisplay('2026-03-29 00:59:59', 'H:i:s'))->toBe('01:59:59')
        ->and(pragueDisplay('2026-03-29 01:00:00', 'H:i:s'))->toBe('03:00:00');
});

it('keeps two distinct instants across the autumn DST switch although the local hour repeats', function (): void {
    $before = CarbonImmutable::parse('2026-10-25 00:59:59', 'UTC');
    $after = CarbonImmutable::parse('2026-10-25 01:00:00', 'UTC');

    expect(pragueDisplay('2026-10-25 00:59:59', 'H:i:s'))->toBe('02:59:59')
        ->and(pragueDisplay('2026-10-25 01:00:00', 'H:i:s'))->toBe('02:00:00')
        ->and($before->equalTo($after))->toBeFalse()
        ->and($before->lessThan($after))->toBeTrue()
        // the offset changes from +02:00 to +01:00, which is what tells the repeated 02:xx hours apart
        ->and($before->setTimezone(FilamentTimezone::get())->format('P'))->toBe('+02:00')
        ->and($after->setTimezone(FilamentTimezone::get())->format('P'))->toBe('+01:00');
});

it('gives every Filament schema the Czech display defaults', function (): void {
    $schema = Schema::make();

    expect($schema->getDefaultDateDisplayFormat())->toBe('j. n. Y')
        ->and($schema->getDefaultDateTimeDisplayFormat())->toBe('j. n. Y H:i')
        ->and($schema->getDefaultTimeDisplayFormat())->toBe('H:i')
        ->and($schema->getDefaultNumberLocale())->toBe('cs')
        ->and($schema->getDefaultCurrency())->toBe('CZK');
});

it('gives every Filament table the Czech display defaults', function (): void {
    $table = Table::make(Mockery::mock(HasTable::class));

    expect($table->getDefaultDateDisplayFormat())->toBe('j. n. Y')
        ->and($table->getDefaultDateTimeDisplayFormat())->toBe('j. n. Y H:i')
        ->and($table->getDefaultTimeDisplayFormat())->toBe('H:i')
        ->and($table->getDefaultNumberLocale())->toBe('cs')
        ->and($table->getDefaultCurrency())->toBe('CZK');
});

it('shows Czech formats in date, date-time and time pickers', function (): void {
    expect(DatePicker::make('day')->getDisplayFormat())->toBe('j. n. Y')
        ->and(DateTimePicker::make('moment')->seconds(false)->getDisplayFormat())->toBe('j. n. Y H:i')
        ->and(DateTimePicker::make('moment')->getDisplayFormat())->toBe('j. n. Y H:i:s')
        ->and(TimePicker::make('clock')->seconds(false)->getDisplayFormat())->toBe('H:i')
        ->and(TimePicker::make('clock')->getDisplayFormat())->toBe('H:i:s');
});
