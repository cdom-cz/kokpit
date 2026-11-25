<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable version of a knowledge article (VER-01, D-11): a full snapshot of the title and the
 * Markdown body at one content-changing save, with its author. The database refuses every update,
 * delete and truncate of these rows; there is no `updated_at`.
 *
 * Closed to Partners by DeniesPartners and the admin-only policy. Not logged: the rows are the
 * history themselves, and they hold the content the activity log must never carry.
 *
 * Nothing is fillable: rows are written only by `SaveArticle`, with `forceFill`.
 *
 * @property string $id
 * @property string $article_id
 * @property int $version_no
 * @property string $title
 * @property string $body_md
 * @property string $author_id
 * @property CarbonImmutable|null $created_at
 */
final class KnowledgeArticleVersion extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    public const UPDATED_AT = null;

    protected $table = 'knowledge_article_versions';

    /**
     * @return BelongsTo<KnowledgeArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'article_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
