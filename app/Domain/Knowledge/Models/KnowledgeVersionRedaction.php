<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record that one stored version of a knowledge article was redacted (VER-05, D-12): which version, who did it,
 * whether single lines or the whole body were removed, and how long the reason the Admin typed was.
 *
 * The reason text is never stored (owner decision OD-1 of 2026-11-25): it may hold what the Admin pasted, so only its
 * length is kept, and the table has no column that could hold the text. The database refuses every update, delete and
 * truncate of these rows; there is no `updated_at`.
 *
 * Closed to Partners by DeniesPartners and the admin-only policy. Not logged: the redaction writes its own activity row.
 *
 * Nothing is fillable: rows are written only by the redaction Action, with `forceFill`.
 *
 * @property string $id
 * @property string $version_id
 * @property string $actor_id
 * @property RedactionScope $scope
 * @property int $reason_length
 * @property CarbonImmutable|null $created_at
 */
final class KnowledgeVersionRedaction extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    public const UPDATED_AT = null;

    protected $table = 'knowledge_version_redactions';

    /**
     * @return BelongsTo<KnowledgeArticleVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticleVersion::class, 'version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RedactionScope::class,
            'reason_length' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
