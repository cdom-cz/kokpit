<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation of a person to become the Partner account of a client (US-02,
 * D-01 to D-03). Admin-only (DeniesPartners and the admin-only policy): a
 * Partner reads zero invitations.
 *
 * The invitation exists before any account does; the users row is created only
 * when the invited person sets a password through the link. Only the SHA-256
 * hash of the token is stored, and it is hidden from serialisation. The model is
 * deliberately not activity-logged: its own timestamps record the lifecycle and
 * the activity log must never see the token hash.
 *
 * `client_id`, the token hash, the expiry and the lifecycle columns are not
 * fillable; the Actions set them with forceFill.
 *
 * @property string $id
 * @property string $client_id
 * @property string $name
 * @property string $email
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property string|null $invited_by
 * @property CarbonInterface $last_sent_at
 * @property int $send_count
 * @property CarbonInterface|null $accepted_at
 * @property string|null $accepted_user_id
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'email'])]
#[Hidden(['token_hash'])]
final class ClientInvitation extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }
}
