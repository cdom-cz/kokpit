<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A highlighted callout block (D-12): the AST node an alert block quote such as "> [!NOTE]" is converted into by
 * CalloutExtension. The type is one of NOTE, TIP, IMPORTANT, WARNING, CAUTION in upper case; the label and the markup
 * come from CalloutRenderer, never from the author's text.
 */
final class Callout extends AbstractBlock
{
    public function __construct(public readonly string $type)
    {
        parent::__construct();
    }
}
