<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * The fail-closed global scope of every record that belongs to one user (`user_id`).
 *
 * Unlike the PartnerScope, the Admin is constrained as well: every Admin sees only the own rows, so
 * a second Admin account never reads or changes the planner of the first. Only an explicit system
 * run (console, seeder, job) sees every row. A guest sees nothing.
 *
 * @implements Scope<Model>
 */
final class OwnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Resolved on every call on purpose: the context is request scoped.
        if (app(PartnerContext::class)->isSystem()) {
            return;
        }

        $userId = Auth::id();

        if ($userId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('user_id'), $userId);
    }
}
