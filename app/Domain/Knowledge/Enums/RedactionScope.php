<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Enums;

/**
 * How much of a stored version a redaction removed (D-12): single lines of the body, or the whole body.
 */
enum RedactionScope: string
{
    case Lines = 'lines';
    case Whole = 'whole';
}
