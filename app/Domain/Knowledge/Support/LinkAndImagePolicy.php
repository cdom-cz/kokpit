<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\Text;

/**
 * The link and image policy of the knowledge renderer, applied to the parsed document before it is rendered
 * (R-18-03-3). An image becomes its alt text, so no image element, no tracking pixel and no data source is ever
 * emitted. A link survives only when its URL starts with http://, https:// or mailto:; every other link
 * (javascript:, data:, vbscript:, relative, protocol-relative, reference-style, autolink) is replaced by its link
 * text, so the output holds no inert anchor either. The sanitiser repeats the scheme check as a second layer.
 */
final class LinkAndImagePolicy
{
    private const string SAFE_URL = '#^(https?://|mailto:)#i';

    public function __invoke(DocumentParsedEvent $event): void
    {
        $images = [];
        $links = [];

        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Image) {
                $images[] = $node;
            } elseif ($node instanceof Link) {
                $links[] = $node;
            }
        }

        foreach ($images as $image) {
            $image->replaceWith(new Text($this->altText($image)));
        }

        foreach ($links as $link) {
            if (preg_match(self::SAFE_URL, $link->getUrl()) === 1) {
                continue;
            }

            foreach ($link->children() as $child) {
                $link->insertBefore($child);
            }

            $link->detach();
        }
    }

    private function altText(Image $image): string
    {
        $alt = '';

        foreach ($image->iterator() as $descendant) {
            if ($descendant instanceof Text) {
                $alt .= $descendant->getLiteral();
            }
        }

        return $alt;
    }
}
