<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Tags\TagType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Tags\Tag as BaseTag;

/**
 * The tags package model with UUID v7 keys.
 *
 * Registered in config/tags.php (`tag_model`). The package base model would
 * cast the generated key to an integer, so never reference it.
 *
 * A Partner sees exactly the project-type tags that are attached to a project
 * the Partner can see (D-07). A client-type tag, an untyped tag, a tag attached
 * only to a hidden, archived or other-client project and a tag attached to
 * anything but a project never match. The policy stays AdminOnlyPolicy, so a
 * Partner has no tag screen and reads tags only through the project resource.
 */
class Tag extends BaseTag implements PartnerIsolated
{
    use HasUuids, IsolatesPartners;

    /**
     * Project-type tags attached to a project the Partner can see. The project
     * ids come from Project::query(), so the Project Partner scope (own client,
     * visible, not archived, client not archived) is the only place that decides
     * which project is visible.
     *
     * The taggables subquery has its own alias because a relation query
     * (`$project->tags`) already joins `taggables`.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $query
            ->where($this->qualifyColumn('type'), TagType::Project->value)
            ->whereExists(static fn ($sub) => $sub->selectRaw('1')
                ->from('taggables', 'partner_tg')
                ->whereColumn('partner_tg.tag_id', 'tags.id')
                ->where('partner_tg.taggable_type', (new Project)->getMorphClass())
                ->whereIn('partner_tg.taggable_id', Project::query()->select('projects.id')));
    }
}
