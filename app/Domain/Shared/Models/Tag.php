<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Tags\Tag as BaseTag;

/**
 * The tags package model with UUID v7 keys.
 *
 * Registered in config/tags.php (`tag_model`). The package base model would
 * cast the generated key to an integer, so never reference it.
 */
class Tag extends BaseTag
{
    use HasUuids;
}
