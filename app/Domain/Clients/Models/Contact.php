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
 * Changes to the allowlisted attributes are written to the activity log (D-06);
 * the log itself is Admin-only.
 *
 * @property string $id
 * @property string $client_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $position
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
    'is_billing',
])]
#[LoggedAttributes([
    'client_id',
    'name',
    'email',
    'phone',
    'position',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_billing' => 'boolean',
        ];
    }
}
