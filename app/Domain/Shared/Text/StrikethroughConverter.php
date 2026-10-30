<?php

declare(strict_types=1);

namespace App\Domain\Shared\Text;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/**
 * The HTML-to-Markdown library has no strikethrough converter, and the description editor offers
 * strike, so it is written as GFM `~~`. Whitespace around the text stays outside the markers,
 * because `~~ text ~~` is not strikethrough in GFM.
 */
final class StrikethroughConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $value = $element->getValue();
        $text = trim($value);

        if ($text === '') {
            return $value;
        }

        $start = strlen($value) - strlen(ltrim($value));
        $end = strlen($value) - strlen(rtrim($value));

        return substr($value, 0, $start).'~~'.$text.'~~'.($end > 0 ? substr($value, -$end) : '');
    }

    /**
     * @return list<string>
     */
    public function getSupportedTags(): array
    {
        return ['del', 's', 'strike'];
    }
}
