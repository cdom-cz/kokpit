<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base for every own model of Kokpit.
 *
 * Conventions every subclass follows:
 * - the table has a uuid primary key with the `uuidv7()` column default in its
 *   migration (HasUuids generates the version 7 value in PHP, the default is
 *   the safety net for raw SQL);
 * - timestamps are `timestampsTz()`, never plain `timestamps()`;
 * - the model has one alias in `App\Domain\Shared\Database\MorphMap`;
 * - a partner-isolation declaration is added by the isolation plan (02-10).
 */
abstract class KokpitModel extends Model
{
    use HasUuids;
}
