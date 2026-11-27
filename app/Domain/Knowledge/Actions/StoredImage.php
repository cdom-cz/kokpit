<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

/**
 * What {@see StoreArticleImage} hands back: the pending image and the Markdown reference the editor inserts at the
 * cursor (D-01, D-03).
 */
final readonly class StoredImage
{
    public function __construct(
        public string $id,
        public string $altText,
        public int $width,
        public int $height,
        public string $reference,
    ) {}
}
