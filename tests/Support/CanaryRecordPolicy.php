<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\KokpitPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * The explicit Partner grants of the canary model: the list, and a record only
 * when it belongs to the Partner's own client. Everything else is the denial of
 * the base policy.
 */
final class CanaryRecordPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof CanaryRecord && $record->client_id === $user->client_id;
    }
}
