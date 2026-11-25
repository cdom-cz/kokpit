<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of the knowledge base: a node of a tree with unique sibling names that holds general
 * articles (KB-01). The folder screens arrive in Phase 19; the table and its integrity trigger
 * exist from Phase 18.
 *
 * `MAX_DEPTH` is the deepest the tree may get, a root folder counting as level 1. The tree trigger
 * of the database is built with this constant and refuses a deeper tree with SQLSTATE KP003; the
 * Phase 19 MoveFolder Action reuses it for its message and its form rule.
 *
 * Closed to Partners by DeniesPartners and the admin-only policy. Not logged: a folder name carries
 * no content the history would need. `parent_id` is not fillable; trusted Actions set it with
 * `forceFill`.
 *
 * @property string $id
 * @property string|null $parent_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name'])]
final class KnowledgeFolder extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    public const int MAX_DEPTH = 6;

    protected $table = 'knowledge_folders';

    /**
     * @return BelongsTo<KnowledgeFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<KnowledgeFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<KnowledgeArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(KnowledgeArticle::class, 'folder_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
