<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use Closure;
use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders a Callout as a div holding the translated type label and then the rendered content (R-18-06-2). The label
 * is looked up by the node type through the translator, so author text can never become part of it; the type is one of
 * five fixed values, which keeps the class name safe. The sanitiser of KnowledgeMarkdown allows div[class] and
 * p[class] already.
 */
final class CalloutRenderer implements NodeRendererInterface
{
    /**
     * @param  Closure(string, array<string, string>=): string  $translate  relative key and replacements to text
     */
    public function __construct(private readonly Closure $translate) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (! $node instanceof Callout) {
            throw new InvalidArgumentException('Incompatible node type: '.$node::class);
        }

        $type = strtolower($node->type);
        $label = htmlspecialchars(($this->translate)('callouts.'.$type), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $content = $childRenderer->renderNodes($node->children());

        return '<div class="kokpit-callout kokpit-callout--'.$type.'">'."\n"
            .'<p class="kokpit-callout__label">'.$label.'</p>'."\n"
            .($content === '' ? '' : $content."\n")
            .'</div>';
    }
}
