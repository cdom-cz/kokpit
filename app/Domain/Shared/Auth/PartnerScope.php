<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The fail-closed global scope of every PartnerIsolated model (D-02).
 *
 * The Admin and an explicit system run see every row. A Partner with a client
 * is constrained by the model itself. Every other state, a guest, a Partner
 * without a client, a user without a role, an unknown role and a model that
 * does not implement the interface, sees nothing.
 *
 * @implements Scope<Model>
 */
final class PartnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Resolved on every call on purpose: the context is request scoped and
        // caching it here would pin the first request's state forever.
        $context = app(PartnerContext::class);

        if ($context->isSystem() || $context->isAdmin()) {
            return;
        }

        $clientId = $context->partnerClientId();

        if ($clientId !== null && $model instanceof PartnerIsolated) {
            $model->constrainForPartner($builder, $clientId);

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
