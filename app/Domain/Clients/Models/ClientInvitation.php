<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Clients\Enums\InvitationState;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
     * The invitation a link points at, or null when the link is not acceptable.
     *
     * This is the one sanctioned read of the guest page: a guest has no user, so
     * every scope is fail-closed and the lookup runs as an explicit system run. A
     * malformed id never reaches PostgreSQL (a uuid comparison would throw), and
     * an unknown row, a wrong token, an expired, revoked or accepted invitation
     * all answer null, so the caller cannot tell the cases apart. At `expires_at`
     * or later the invitation counts as expired.
     */
    public static function findAcceptable(string $id, string $plainToken): ?self
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $invitation = app(PartnerContext::class)->runAsSystem(
            static fn (): ?self => self::query()->whereKey($id)->first(),
        );

        return $invitation?->isAcceptableWith($plainToken) === true ? $invitation : null;
    }

    /**
     * Whether this invitation can be accepted with the given plain token right
     * now: the token matches the stored hash and the invitation is still pending
     * (not accepted, not revoked, before `expires_at`). The one check shared by
     * the guest lookup and the accept Action, which repeats it on the locked row.
     */
    public function isAcceptableWith(string $plainToken): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $plainToken))
            && $this->state() === InvitationState::Pending;
    }

    /**
     * Where the invitation stands now, derived from the timestamps and the
     * current time and never stored. Accepted and revoked outrank expired, so
     * they keep their state after the expiry date has passed; at `expires_at`
     * or later a pending invitation counts as expired.
     */
    public function state(): InvitationState
    {
        return match (true) {
            $this->accepted_at !== null => InvitationState::Accepted,
            $this->revoked_at !== null => InvitationState::Revoked,
            ! $this->expires_at->isFuture() => InvitationState::Expired,
            default => InvitationState::Pending,
        };
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
