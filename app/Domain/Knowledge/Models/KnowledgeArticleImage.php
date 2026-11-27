<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One re-encoded image of a knowledge article (IMG-02, D-02, D-04, D-06, D-08): the facts of the stored output (mime
 * type, size, dimensions, digest) and the name the file had when it was uploaded. The row is pending while `article_id`
 * is NULL (D-02) and belongs to exactly one article once a save has bound it (D-04); the database then refuses every
 * change but the orphan stamp.
 *
 * The object lives on the default (private) disk at `storagePath()`, a pure function of the id and the mime type, so no
 * user text ever enters the object key. The file name is shown only to the Admin in the Obrázky table and is never
 * logged.
 *
 * Closed to Partners by DeniesPartners and the admin-only policy. Not logged: the row holds a file name the activity
 * log must never carry. There is no `updated_at`.
 *
 * Nothing is fillable: rows are written only by the upload Action and the save, with `forceFill`.
 *
 * @property string $id
 * @property string|null $article_id
 * @property string $uploaded_by
 * @property string $file_name
 * @property string $mime_type
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 * @property string $sha256
 * @property CarbonImmutable|null $orphaned_at
 * @property CarbonImmutable|null $created_at
 */
final class KnowledgeArticleImage extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    public const UPDATED_AT = null;

    public const string STORAGE_DIRECTORY = 'knowledge/images';

    protected $table = 'knowledge_article_images';

    /**
     * The file extension of the stored object, from the mime type alone.
     *
     * @throws LogicException when the mime type is none of the four the database admits
     */
    public function extension(): string
    {
        return match ($this->mime_type) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new LogicException('A knowledge image has an unsupported mime type.'),
        };
    }

    /**
     * Where the stored bytes live on the default (private) disk: built from the id and the mime type only, so no file
     * name or other user text can reach the object key.
     */
    public function storagePath(): string
    {
        return self::STORAGE_DIRECTORY.'/'.$this->getKey().'.'.$this->extension();
    }

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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<KnowledgeArticleVersionImage, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(KnowledgeArticleVersionImage::class, 'image_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'orphaned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
