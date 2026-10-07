<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * The medialibrary model with UUID v7 keys.
 *
 * Registered in config/media-library.php (`media_model`). The package base
 * model would cast the generated key to an integer, so never reference it.
 */
class Media extends BaseMedia
{
    use HasUuids;
}
