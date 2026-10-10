<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * For Admin-only records that belong to the signed-in user: closed to Partners (DeniesPartners),
 * constrained to the own rows for everybody else (OwnerScope), and stamped with the owner on create.
 *
 * A row is created for the signed-in user only. A system run that has no user must set `user_id`
 * itself; creating a row for nobody is refused instead of failing later on the NOT NULL column.
 */
trait OwnedByUser
{
    use DeniesPartners;

    public static function bootOwnedByUser(): void
    {
        static::addGlobalScope(new OwnerScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('user_id') !== null) {
                return;
            }

            $userId = Auth::id();

            if ($userId === null) {
                throw new LogicException('An owned record needs a signed-in user or an explicit user_id.');
            }

            $model->setAttribute('user_id', $userId);
        });
    }
}
