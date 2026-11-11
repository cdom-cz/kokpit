<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Clients\Queries\ContactBirthdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The validated query string of GET /api/v1/contacts/birthdays.
 *
 * The only parameter is the day to ask for, `date` as `YYYY-MM-DD`. It defaults to today in Prague. The format check
 * refuses an overflowed date such as 2026-02-30 and an array, and the range keeps the year inside what a contact
 * birth year can be. The value only selects a month and a day for bound query parameters. The route middleware has
 * already authorized the token.
 */
final class ListBirthdaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'string', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
        ];
    }

    /**
     * The day asked for as the start of that day in Prague, or today in Prague when no date was sent.
     */
    public function day(ContactBirthdays $birthdays): CarbonImmutable
    {
        $date = $this->validated('date');

        return is_string($date)
            ? CarbonImmutable::parse($date, ContactBirthdays::ZONE)->startOfDay()
            : $birthdays->today();
    }
}
