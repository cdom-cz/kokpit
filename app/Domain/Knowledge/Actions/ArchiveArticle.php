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
 * Archives a knowledge article: a soft delete, never a removal (KB-07, D-13).
 *
 * The actor needs the `delete` ability on the article (the Admin only). Inside the transaction the article is re-read
 * through the scoped query with a row lock, so an article that is already archived is a not-found error and a second
 * archive writes nothing. The step is one explicit activity row, event `article_archived`, with the actor as causer
 * and no properties: neither the title nor the body is ever logged. The prefixed event name keeps this row distinct
 * from any `deleted` row the activity package may add on its own.
 *
 * Archiving makes no version and touches neither the version number nor the edit time (only SaveArticle writes
 * those), so a restored article returns to its old place in the list.
 */
final class ArchiveArticle
{
    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException an article that is already archived or unknown
     */
    public function handle(User $actor, KnowledgeArticle $article): KnowledgeArticle
    {
        Gate::forUser($actor)->authorize('delete', $article);

        DB::transaction(function () use ($actor, $article): void {
            // The scoped query: an archived article is a ModelNotFoundException.
            $locked = KnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();

            $locked->delete();

            activity()
                ->useLog($locked->getMorphClass())
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('article_archived')
                ->log('article_archived');
        });

        // The caller's instance shows the stored value, whatever it showed before.
        $article->refresh();

        return $article;
    }
}
