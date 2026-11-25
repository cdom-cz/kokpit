<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Guard;

/**
 * How a secret finding is handled on save: a blocking finding refuses the save with no way around it,
 * an ambiguous one may be saved anyway after the author acknowledged it (D-01).
 */
enum Severity: string
{
    case Blocking = 'blocking';
    case Ambiguous = 'ambiguous';
}
