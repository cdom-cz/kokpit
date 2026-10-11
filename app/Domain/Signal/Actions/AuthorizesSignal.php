<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The shared guards of the planner Actions.
 *
 * The planner is Admin-only (the Gate, through AdminOnlyPolicy) and personal: the actor must be the
 * signed-in user, because the owner scope reads Auth::id() and a record is stamped with the actor.
 * A record is found only through the owner scope, so another user's id is "not found", and an id
 * that is no UUID never reaches the uuid column as a database error.
 */
trait AuthorizesSignal
{
    /**
     * @throws AuthorizationException
     */
    private function authorizeActor(User $actor): void
    {
        Gate::forUser($actor)->authorize('create', SignalTask::class);

        if (Auth::id() !== $actor->getKey()) {
            throw new AuthorizationException;
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     *
     * @throws ModelNotFoundException
     */
    private function findOwned(string $model, string $id): Model
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel($model, [$id]);
        }

        return $model::query()->findOrFail($id);
    }

    /**
     * @throws SignalRuleViolation
     */
    private function cleanTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw SignalRuleViolation::because('title_required');
        }

        if (mb_strlen($title) > 255) {
            throw SignalRuleViolation::because('title_too_long');
        }

        return $title;
    }
}
