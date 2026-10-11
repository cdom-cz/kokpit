<?php

declare(strict_types=1);

use App\Domain\Signal\Support\SignalCalendar;

/*
 * The Czech day names and the date format come from the translator and the shared format constant,
 * not from literals of the calendar class. These cases need the application (the translator), which
 * the Unit suite does not boot. Every date is fictional.
 */

it('writes the long day through the translator: weekday name and the shared date format', function (): void {
    expect(SignalCalendar::formatLong('2026-10-12'))->toBe('pondělí 12. 10. 2026')
        ->and(SignalCalendar::formatLong('2026-10-18'))->toBe('neděle 18. 10. 2026');
});

it('has a real text for every weekday name', function (): void {
    $names = [];

    foreach (range(0, 6) as $index) {
        $key = 'kokpit.signal.calendar.weekdays.'.$index;

        expect(__($key))->not->toBe($key);

        $names[] = __($key);
    }

    expect(array_unique($names))->toHaveCount(7)
        ->and($names)->toBe(['pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle']);
});
