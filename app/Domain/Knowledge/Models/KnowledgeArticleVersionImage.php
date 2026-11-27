<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record that one stored version of a knowledge article references one image of the same article (IMG-03, D-16,
 * D-17). The composite foreign keys `(version_id, article_id)` and `(image_id, article_id)` make a reference across two
 * articles impossible in the database, and RESTRICT keeps an image alive while any reference names it.
 *
 * Closed to Partners by DeniesPartners and the admin-only policy. Not logged: it is an index row with no content. There
 * is no `updated_at`.
 *
 * Nothing is fillable: rows are written only by the reviewed writers (the save and the cleanup), with `forceFill`.
 *
 * @property string $id
 * @property string $version_id
 * @property string $image_id
 * @property string $article_id
 * @property CarbonImmutable|null $created_at
 */
final class KnowledgeArticleVersionImage extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    public const UPDATED_AT = null;

    protected $table = 'knowledge_article_version_images';

    /**
     * @return BelongsTo<KnowledgeArticleVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticleVersion::class, 'version_id');
    }

    /**
     * @return BelongsTo<KnowledgeArticleImage, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticleImage::class, 'image_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
