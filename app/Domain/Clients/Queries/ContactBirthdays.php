<?php

declare(strict_types=1);

namespace App\Domain\Clients\Queries;

use App\Domain\Clients\Models\Contact;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The contacts whose birthday falls on a Prague calendar day (CL-02, RE-04).
 *
 * Two consumers read the list: the "Narozeniny dnes" card of the Admin dashboard and the API endpoint
 * `GET /api/v1/contacts/birthdays`. Both go through this one reader, so the card and the API cannot disagree about
 * who has a birthday.
 *
 * "Today" is the Europe/Prague day, like every other dashboard card. A 29 February birthday is listed on 28 February
 * in a non-leap year, so nobody is skipped three years out of four. Contacts of an archived (soft-deleted) client are
 * left out, the same as in the client lookups.
 *
 * Contacts are personal data of the Admin (D-06). The model already gives a Partner zero rows, but the reader also
 * refuses everyone but the Admin and a system run, so a missing scope could not leak a birthday.
 */
final class ContactBirthdays
{
    public const string ZONE = 'Europe/Prague';

    /**
     * The start of the current day in Prague.
     */
    public function today(): CarbonImmutable
    {
        return TimerClock::now()->setTimezone(self::ZONE)->startOfDay();
    }

    /**
     * The contacts with a birthday on the given day, by name, with their client loaded.
     *
     * @return Builder<Contact>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function query(CarbonInterface $day): Builder
    {
        $this->authorise();

        $month = $day->month;
        $dayOfMonth = $day->day;
        $leapDayFallsHere = $month === 2 && $dayOfMonth === 28 && ! $day->isLeapYear();

        return Contact::query()
            ->where(static function (Builder $match) use ($month, $dayOfMonth, $leapDayFallsHere): void {
                $match->where(static function (Builder $exact) use ($month, $dayOfMonth): void {
                    $exact->where('contacts.birth_month', $month)->where('contacts.birth_day', $dayOfMonth);
                });

                if ($leapDayFallsHere) {
                    $match->orWhere(static function (Builder $leap): void {
                        $leap->where('contacts.birth_month', 2)->where('contacts.birth_day', 29);
                    });
                }
            })
            // The soft delete scope of the client leaves out the contacts of an archived client.
            ->whereHas('client')
            ->with('client')
            ->orderBy('contacts.name')
            ->orderBy('contacts.id');
    }

    /**
     * @return Collection<int, Contact>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function on(CarbonInterface $day): Collection
    {
        return $this->query($day)->get();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The birthdays of contacts are available to the Admin only.');
        }
    }
}
