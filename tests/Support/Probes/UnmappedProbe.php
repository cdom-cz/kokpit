<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Shared\Models\KokpitModel;

/**
 * A model that is deliberately absent from the morph map. With the map
 * enforced, asking it for its morph class has to throw. The table never exists.
 */
final class UnmappedProbe extends KokpitModel
{
    protected $table = 'unmapped_probes';
}
