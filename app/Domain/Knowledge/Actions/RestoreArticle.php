<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Restores an archived knowledge article (KB-07, D-13).
 *
 * The actor needs the `restore` ability on the article (the Admin only). Inside the transaction the article is
 * re-read among the archived ones with a row lock, so an article that is active is a not-found error and a second
 * restore writes nothing. The step is one explicit activity row, event `article_restored`, with the actor as causer
 * and no properties: neither the title nor the body is ever logged.
 *
 * Restoring makes no version and touches neither the version number nor the edit time, so the article returns to
 * its old place in the list.
 */
final class RestoreArticle
{
    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException an article that is active or unknown
     */
    public function handle(User $actor, KnowledgeArticle $article): KnowledgeArticle
    {
        Gate::forUser($actor)->authorize('restore', $article);

        DB::transaction(function () use ($actor, $article): void {
            // Only an archived article is found: an active one is a ModelNotFoundException.
            $locked = KnowledgeArticle::query()->onlyTrashed()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();

            $locked->restore();

            activity()
                ->useLog($locked->getMorphClass())
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('article_restored')
                ->log('article_restored');
        });

        // The caller's instance shows the stored value, whatever it showed before.
        $article->refresh();

        return $article;
    }
}
