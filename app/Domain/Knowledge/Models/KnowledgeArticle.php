<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Clients\Models\Client;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use ArrayAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Tags\HasTags;

/**
 * A knowledge article of any kind (KB-01): a general article, an incident, an incident sub-page or a
 * retrospective. The row holds the title and the Markdown body of the current version; every version,
 * the current one included, is a full snapshot in `knowledge_article_versions` (D-11).
 *
 * Closed to Partners by DeniesPartners and the admin-only policy. Archiving is the soft delete
 * (KB-07); an article is never hard deleted.
 *
 * Only metadata is written to the activity log through the allowlist: the kind, the folder, the
 * client and project links, the share and sensitive flags and the version number. The title and the
 * body are never logged, because an article may hold anything an Admin pastes into it, including
 * what the secret guard only warns about.
 *
 * The article carries tags of the type `knowledge` (ORG-03). No tag name is ever written to the activity log:
 * tags are not in the allowlist, and the article row does not change when a tag is attached.
 *
 * Nothing is fillable: every content write is a `forceFill` inside the Knowledge Actions, so a
 * request can never change the body, the version number or the sharing by mass assignment.
 *
 * `MAX_TITLE_LENGTH` and `MAX_BODY_LENGTH` equal the literals of the database CHECK constraints.
 *
 * @property string $id
 * @property ArticleKind $kind
 * @property string|null $parent_id
 * @property string $title
 * @property string $body_md
 * @property int $version_no
 * @property int $redaction_no
 * @property CarbonImmutable $edited_at
 * @property bool $sensitive
 * @property string|null $folder_id
 * @property string|null $client_id
 * @property string|null $project_id
 * @property bool $client_visible
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[LoggedAttributes([
    'kind',
    'folder_id',
    'client_id',
    'project_id',
    'client_visible',
    'sensitive',
    'version_no',
])]
final class KnowledgeArticle extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity, SoftDeletes;
    use HasTags {
        detachTags as private detachTagsFromTrait;
    }

    public const int MAX_TITLE_LENGTH = 200;

    public const int MAX_BODY_LENGTH = 100000;

    protected $table = 'knowledge_articles';

    /**
     * The package detaches every tag when a model is deleted. Archiving is a soft delete and keeps the
     * tags, so a restore brings them back (D-04); only a force delete detaches them.
     *
     * @param  array<mixed>|ArrayAccess<int|string, mixed>  $tags
     */
    public function detachTags(array|ArrayAccess $tags, ?string $type = null): static
    {
        if ($this->trashed() && ! $this->isForceDeleting()) {
            return $this;
        }

        return $this->detachTagsFromTrait($tags, $type);
    }

    /**
     * @return HasMany<KnowledgeArticleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeArticleVersion::class, 'article_id')->orderBy('version_no');
    }

    /**
     * The images of this article, pending uploads excluded (a pending image has no article yet).
     *
     * @return HasMany<KnowledgeArticleImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(KnowledgeArticleImage::class, 'article_id');
    }

    /**
     * @return BelongsTo<KnowledgeFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFolder::class, 'folder_id');
    }

    /**
     * @return BelongsTo<KnowledgeArticle, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ArticleKind::class,
            'sensitive' => 'boolean',
            'client_visible' => 'boolean',
            'version_no' => 'integer',
            'redaction_no' => 'integer',
            'edited_at' => 'immutable_datetime',
        ];
    }
}
