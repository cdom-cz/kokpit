<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Support;

/**
 * The single display rule for a duration (UI-SPEC Duration display).
 *
 * Every surface formats exact seconds through here: the list and the sums as
 * H:MM, the running readout and the view page as H:MM:SS. Minutes are truncated,
 * never rounded up, so a row never claims more time than was tracked; a sum is
 * formed from seconds first and formatted once, so the rows 0:00 and 0:00 may
 * add up to 0:01. Hours are unbounded (38:05, not a day wrap). A negative value
 * keeps its minus sign. Integer arithmetic only, no floats.
 */
final class DurationFormat
{
    private const int MINUTE = 60;

    private const int HOUR = 3600;

    /**
     * Hours and minutes, for example `0:00`, `1:25`, `38:05`.
     */
    public static function hoursMinutes(int $seconds): string
    {
        $abs = abs($seconds);
        $hours = intdiv($abs, self::HOUR);
        $minutes = intdiv($abs % self::HOUR, self::MINUTE);

        return self::sign($hours * self::HOUR + $minutes * self::MINUTE, $seconds).sprintf('%d:%02d', $hours, $minutes);
    }

    /**
     * Hours, minutes and seconds, for example `0:07:42`, `123:45:07`.
     */
    public static function hoursMinutesSeconds(int $seconds): string
    {
        $abs = abs($seconds);

        return self::sign($abs, $seconds).sprintf(
            '%d:%02d:%02d',
            intdiv($abs, self::HOUR),
            intdiv($abs % self::HOUR, self::MINUTE),
            $abs % self::MINUTE,
        );
    }

    /**
     * A minus sign only when the shown value is not zero: a negative value that
     * truncates to 0:00 is shown without a sign.
     *
     * @param  int  $shown  the magnitude that is displayed, in seconds
     */
    private static function sign(int $shown, int $original): string
    {
        return $original < 0 && $shown > 0 ? '-' : '';
    }
}
