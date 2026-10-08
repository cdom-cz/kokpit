<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Switches a deactivated Partner account back on (US-02, D-04).
 *
 * Clearing `deactivated_at` is all it takes: the panel gate reads it on every
 * request, so the login works again at once. The API tokens deleted by the
 * deactivation are not restored. Reactivating an active account changes nothing.
 */
final class ReactivatePartnerAccount
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

            if ($current->deactivated_at !== null) {
                $current->forceFill(['deactivated_at' => null])->save();
            }
        });

        $user->refresh();
    }
}
