<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Switches a Partner account off (US-02, D-04): the only way to take away access,
 * accounts are never deleted.
 *
 * `deactivated_at` is read by `User::canAccessPanel()` on every request, so the
 * login fails with the generic message and an existing session gets a 403 on its
 * next request. The API tokens are deleted in the same transaction. The row is
 * locked and re-read, so a stale instance cannot overwrite the first timestamp:
 * deactivating twice keeps the first `deactivated_at`.
 */
final class DeactivatePartnerAccount
{
    /**
     * @throws DomainException when the user is not a Partner
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $current = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $current->hasRole(RoleName::Partner->value)) {
                throw new DomainException(__('kokpit.partner_accounts.errors.not_a_partner'));
            }

            if ($current->deactivated_at === null) {
                $current->forceFill(['deactivated_at' => now()])->save();
            }

            $current->tokens()->delete();
        });

        $user->refresh();
    }
}
