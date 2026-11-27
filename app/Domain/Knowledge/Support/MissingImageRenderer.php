<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use Closure;
use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders a MissingImage as the muted fallback of the UI contract: the escaped alt text in a span, or the escaped
 * `image.missing` text when the alt text is empty. It never raises an error for a missing image (D-18, D-19).
 */
final class MissingImageRenderer implements NodeRendererInterface
{
    /**
     * @param  Closure(string, array<string, string>=): string  $translate  relative key and replacements to text
     */
    public function __construct(private readonly Closure $translate) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (! $node instanceof MissingImage) {
            throw new InvalidArgumentException('Incompatible node type: '.$node::class);
        }

        $text = trim($node->alt()) === '' ? ($this->translate)('image.missing') : $node->alt();

        return '<span class="kokpit-image-missing">'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
    }
}
