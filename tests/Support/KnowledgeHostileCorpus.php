<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Hostile Markdown bodies for the knowledge renderer tests. Every payload token is assembled from fragments at
 * runtime, so no single line of this file looks like a payload (repository hygiene). All text is fictional.
 */
final class KnowledgeHostileCorpus
{
    /**
     * @return array<string, string> fictional label => Markdown body
     */
    public static function cases(): array
    {
        $lt = '<';
        $js = 'java'.'script:';

        return [
            'raw script tag' => 'Before '.$lt.'scr'.'ipt>window.exampleMarker()'.$lt.'/scr'.'ipt> after',
            'image tag with an error handler' => $lt.'img src="x" on'.'error="exampleMarker()">',
            'inline event handlers' => $lt.'p on'.'click="exampleMarker()" on'.'mouseover="exampleMarker()">Example text'.$lt.'/p>',
            'iframe' => $lt.'ifr'.'ame src="https://example.com/frame">'.$lt.'/ifr'.'ame>',
            'style tag' => $lt.'sty'.'le>p { display: none }'.$lt.'/sty'.'le> Example text',
            'style attribute' => $lt.'div sty'.'le="position: fixed; top: 0">Example text'.$lt.'/div>',
            'link with the script scheme in mixed case' => '[Example link](Ja'.'vaScr'.'ipt:exampleMarker())',
            'link with the data scheme' => '[Example link](da'.'ta:text/html;base64,ZXhhbXBsZQ==)',
            'link with the vbscript scheme' => '[Example link](vb'.'script:exampleMarker())',
            'relative link' => '[Example link](/admin/example/page)',
            'protocol-relative link' => '[Example link](//example.com/page)',
            'reference-style script link' => "[Example link][ref]\n\n[ref]: ".$js.'exampleMarker()',
            'autolink with the script scheme' => '<'.$js.'exampleMarker()>',
            'image with an https source' => '![Example alt](https://example.com/tracker.png)',
            'image with a data source' => '![Example alt](da'.'ta:image/png;base64,ZXhhbXBsZQ==)',
            'fence info string carrying markup' => '```php"><scr'.'ipt>exampleMarker()</scr'."ipt>\nexample\n```",
            'html entities in text' => 'Example &lt;b&gt; and &amp; and &#60;i&#62; text',
        ];
    }

    /**
     * Unbalanced opening brackets, the worst case of the research spike.
     */
    public static function bracketFlood(int $length = 100000): string
    {
        return str_repeat('[', $length);
    }

    /**
     * Alternating emphasis delimiters that never close.
     */
    public static function emphasisFlood(int $length = 100000): string
    {
        return substr(str_repeat('*_', intdiv($length, 2) + 1), 0, $length);
    }

    /**
     * A run of backticks without a matching closer.
     */
    public static function backtickFlood(int $length = 100000): string
    {
        return str_repeat('`', $length);
    }

    /**
     * One long paragraph of fictional words, longer than the default sanitiser input limit.
     */
    public static function longParagraph(int $length = 30000): string
    {
        $text = str_repeat('Example text. ', intdiv($length, 14) + 1);

        // Ends in a letter, so a trailing space cannot shorten the rendered text.
        return substr($text, 0, $length - 1).'x';
    }
}
