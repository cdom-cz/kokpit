<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * An internal image reference whose image is not available in the render (D-04, D-18, D-19): it belongs to another
 * article, is unknown or was deleted. It holds only the alt text; MissingImageRenderer turns it into a muted fallback
 * text. It is a node of its own because a raw HtmlInline would be escaped by `html_input => 'escape'`.
 */
final class MissingImage extends AbstractInline
{
    public function __construct(private readonly string $alt)
    {
        parent::__construct();
    }

    public function alt(): string
    {
        return $this->alt;
    }
}
