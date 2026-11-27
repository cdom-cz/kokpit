<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\Text;

/**
 * The link and image policy of the knowledge renderer, applied to the parsed document before it is rendered
 * (R-18-03-3). An image survives only as the internal reference `kokpit-attachment:<uuid>` that the per-call image
 * context lists (D-13): it gets the authorised route path as its source plus its stored size. An internal reference
 * the context does not list (another article's image, an unknown or deleted one) becomes a MissingImage node, shown
 * as its alt text (D-04, D-18, D-19). Every other image becomes its alt text, so no external image element, no
 * tracking pixel and no data source is ever emitted. A link survives only when its URL starts with http://,
 * https:// or mailto:; every other link (javascript:, data:, vbscript:, relative, protocol-relative,
 * reference-style, autolink) is replaced by its link text, so the output holds no inert anchor either. The
 * sanitiser repeats the scheme check as a second layer.
 */
final class LinkAndImagePolicy
{
    private const string SAFE_URL = '#^(https?://|mailto:)#i';

    private ImageContext $images;

    public function __construct()
    {
        $this->images = ImageContext::none();
    }

    /**
     * Sets the images that may be shown by the next conversion. The renderer resets it to none after every call.
     */
    public function useImages(ImageContext $images): void
    {
        $this->images = $images;
    }

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
            $id = ImageReferences::idFromUrl($image->getUrl());

            if ($id === null) {
                $image->replaceWith(new Text($this->altText($image)));

                continue;
            }

            $entry = $this->images->find($id);

            if ($entry === null) {
                $image->replaceWith(new MissingImage($this->altText($image)));

                continue;
            }

            $image->setUrl($entry['src']);
            $image->data->set('attributes', [
                'width' => (string) $entry['width'],
                'height' => (string) $entry['height'],
                'loading' => 'lazy',
                'decoding' => 'async',
            ]);
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
