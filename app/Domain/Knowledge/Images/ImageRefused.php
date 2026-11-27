<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

use RuntimeException;

/**
 * Thrown by the image pipeline when it refuses an upload. It carries the reason and nothing else: no byte, name or
 * size of the file ever enters the message, so it can be logged and shown as it is.
 */
final class ImageRefused extends RuntimeException
{
    public function __construct(public readonly ImageRefusal $refusal)
    {
        parent::__construct('The image was refused.');
    }
}
