<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Validation and normalisation of the contact fields shared by CreateContact and
 * UpdateContact (CL-02, D-12). It knows the text fields, the birthday and the
 * billing flag and nothing else: the primary flag and the client are never read
 * from input.
 *
 * The birthday is day, month and an optional year. Day and month come together,
 * the year needs both, the date must exist on the calendar (29 February without a
 * year is fine) and a full birth date may not lie after today in Prague. The
 * database CHECKs `contacts_birth*` back this up for writes that bypass the Action.
 *
 * @phpstan-type ContactData array{
 *     name?: mixed,
 *     email?: mixed,
 *     phone?: mixed,
 *     position?: mixed,
 *     birth_day?: mixed,
 *     birth_month?: mixed,
 *     birth_year?: mixed,
 *     is_billing?: mixed,
 * }
 * @phpstan-type ContactAttributes array{name: string, email: string|null, phone: string|null, position: string|null, birth_day: int|null, birth_month: int|null, birth_year: int|null, is_billing: bool}
 */
final class ContactInput
{
    private const string ZONE = 'Europe/Prague';

    /** A leap year, so that 29 February without a year passes the calendar check. */
    private const int LEAP_YEAR = 2000;

    private const int MIN_YEAR = 1900;

    /**
     * Validates and normalises the input: text trimmed, empty optional text
     * becomes null. Every problem is reported at once, keyed by the data key.
     *
     * @param  array<string, mixed>  $data  read defensively because a crafted payload may miss keys
     * @return ContactAttributes
     *
     * @throws ValidationException
     */
    public static function attributes(array $data): array
    {
        $errors = [];

        $name = self::text($data['name'] ?? null);

        if ($name === null || mb_strlen($name) > 255) {
            $errors['name'] = __('kokpit.contacts.errors.name_invalid');
        }

        $email = self::text($data['email'] ?? null);

        if ($email !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = __('kokpit.contacts.errors.email_invalid');
        }

        $phone = self::text($data['phone'] ?? null);

        if ($phone !== null && mb_strlen($phone) > 50) {
            $errors['phone'] = __('kokpit.contacts.errors.phone_invalid');
        }

        $position = self::text($data['position'] ?? null);

        if ($position !== null && mb_strlen($position) > 255) {
            $errors['position'] = __('kokpit.contacts.errors.position_invalid');
        }

        $day = self::number($data['birth_day'] ?? null);
        $month = self::number($data['birth_month'] ?? null);
        $year = self::number($data['birth_year'] ?? null);
        $today = TimerClock::now()->setTimezone(self::ZONE);

        foreach (self::birthdayErrors($data, $day, $month, $year, $today) as $key => $message) {
            $errors[$key] = $message;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => (string) $name,
            'email' => $email,
            'phone' => $phone,
            'position' => $position,
            'birth_day' => $day,
            'birth_month' => $month,
            'birth_year' => $year,
            'is_billing' => (bool) ($data['is_billing'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function birthdayErrors(array $data, ?int $day, ?int $month, ?int $year, CarbonImmutable $today): array
    {
        $errors = [];

        if (self::given($data['birth_day'] ?? null) && ($day === null || $day < 1 || $day > 31)) {
            $errors['birth_day'] = __('kokpit.contacts.errors.birth_day_invalid');
        }

        if (self::given($data['birth_month'] ?? null) && ($month === null || $month < 1 || $month > 12)) {
            $errors['birth_month'] = __('kokpit.contacts.errors.birth_month_invalid');
        }

        if (self::given($data['birth_year'] ?? null) && ($year === null || $year < self::MIN_YEAR || $year > $today->year)) {
            $errors['birth_year'] = __('kokpit.contacts.errors.birth_year_invalid');
        }

        if ($errors !== []) {
            return $errors;
        }

        if ($day !== null && $month === null) {
            return ['birth_month' => __('kokpit.contacts.errors.birthday_incomplete')];
        }

        if ($month !== null && $day === null) {
            return ['birth_day' => __('kokpit.contacts.errors.birthday_incomplete')];
        }

        if ($year !== null && $day === null) {
            return ['birth_year' => __('kokpit.contacts.errors.birth_year_without_date')];
        }

        if ($day === null || $month === null) {
            return [];
        }

        if (! checkdate($month, $day, $year ?? self::LEAP_YEAR)) {
            return ['birth_day' => __('kokpit.contacts.errors.birth_day_invalid')];
        }

        if ($year !== null && CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::ZONE)->isAfter($today)) {
            return ['birth_year' => __('kokpit.contacts.errors.birth_year_invalid')];
        }

        return [];
    }

    /**
     * Whether the field carries any input: null and a blank string mean "not given".
     */
    private static function given(mixed $value): bool
    {
        return ! ($value === null || (is_string($value) && trim($value) === ''));
    }

    /**
     * An int, or a string of one to four digits, as an int; null for an empty value
     * and for anything else (array, float, sign, letters), which the caller reports.
     */
    private static function number(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{1,4}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
