<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person at a client (CL-02, D-12). Admin-only personal data (D-06): closed to
 * Partners by DeniesPartners and the admin-only policy, so a Partner reads zero
 * contacts through the model, a relation or a relation manager.
 *
 * `client_id` and `is_primary` are not fillable. Creation code sets the client
 * through `$client->contacts()`, and the primary flag is owned by the domain
 * Actions: at most one primary per client is also enforced by the partial unique
 * index `contacts_one_primary_per_client`. Any number of contacts can be billing
 * contacts.
 *
 * The birthday is three nullable integers: day and month are set together, the year
 * is optional and needs both. The database CHECKs `contacts_birth*` refuse an
 * impossible date; the Action also refuses a birth date in the future. Like the
 * e-mail it is Admin-only personal data.
 *
 * Changes to the allowlisted attributes are written to the activity log (D-06);
 * the log itself is Admin-only.
 *
 * @property string $id
 * @property string $client_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $position
 * @property int|null $birth_day
 * @property int|null $birth_month
 * @property int|null $birth_year
 * @property bool $is_primary
 * @property bool $is_billing
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'phone',
    'position',
    'birth_day',
    'birth_month',
    'birth_year',
    'is_billing',
])]
#[LoggedAttributes([
    'client_id',
    'name',
    'email',
    'phone',
    'position',
    'birth_day',
    'birth_month',
    'birth_year',
    'is_primary',
    'is_billing',
])]
final class Contact extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Whether the day and month of the birthday are known (they are always set together).
     */
    public function hasBirthday(): bool
    {
        return $this->birth_day !== null && $this->birth_month !== null;
    }

    /**
     * The birthday as the Admin reads it: "14. 3. 1985", or "14. 3." without a known year.
     */
    public function birthdayLabel(): ?string
    {
        if (! $this->hasBirthday()) {
            return null;
        }

        $label = $this->birth_day.'. '.$this->birth_month.'.';

        return $this->birth_year === null ? $label : $label.' '.$this->birth_year;
    }

    /**
     * The age turned in the year of the given day, or null when the birth year is unknown.
     */
    public function ageOn(CarbonInterface $day): ?int
    {
        return $this->birth_year === null ? null : $day->year - $this->birth_year;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_day' => 'integer',
            'birth_month' => 'integer',
            'birth_year' => 'integer',
            'is_primary' => 'boolean',
            'is_billing' => 'boolean',
        ];
    }
}
