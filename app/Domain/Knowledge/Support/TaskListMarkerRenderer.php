<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use Closure;
use InvalidArgumentException;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders the marker of a task list item (UI-SPEC): a disabled checkbox that is hidden from assistive technology,
 * followed by a visually hidden done or open prefix, so a screen reader announces the state as text instead of an
 * unlabelled control.
 */
final class TaskListMarkerRenderer implements NodeRendererInterface
{
    /**
     * @param  Closure(string, array<string, string>=): string  $translate  relative key and replacements to text
     */
    public function __construct(private readonly Closure $translate) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (! $node instanceof TaskListItemMarker) {
            throw new InvalidArgumentException('Incompatible node type: '.$node::class);
        }

        $prefix = ($this->translate)($node->isChecked() ? 'task.done' : 'task.open');

        return '<input type="checkbox" disabled aria-hidden="true"'.($node->isChecked() ? ' checked' : '').'>'
            .'<span class="kokpit-sr-only">'.htmlspecialchars($prefix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' </span>';
    }
}
