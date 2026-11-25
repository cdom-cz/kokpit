<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use Closure;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;

/**
 * GitHub-style alert callouts (D-12, KB-04). After parsing, every block quote that is a direct child of the document
 * and whose first line is exactly one of the five markers [!NOTE], [!TIP], [!IMPORTANT], [!WARNING], [!CAUTION] (any
 * letter case) becomes a Callout holding the rest of the quote. Anything else stays an ordinary block quote: an
 * unknown type, text after the marker on the same line, and a quote nested inside another quote or callout (only the
 * document's direct children are looked at, so nesting is never converted).
 */
final class CalloutExtension implements ExtensionInterface
{
    private const string MARKER = '/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]$/i';

    /**
     * @param  Closure(string, array<string, string>=): string  $translate  relative key and replacements to text
     */
    public function __construct(private readonly Closure $translate) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, $this->convert(...));
        $environment->addRenderer(Callout::class, new CalloutRenderer($this->translate));
    }

    private function convert(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->children() as $quote) {
            if (! $quote instanceof BlockQuote) {
                continue;
            }

            $paragraph = $quote->firstChild();
            $marker = $paragraph?->firstChild();

            if (! $paragraph instanceof Paragraph || ! $marker instanceof Text) {
                continue;
            }

            if (preg_match(self::MARKER, $marker->getLiteral(), $matches) !== 1) {
                continue;
            }

            $lineBreak = $marker->next();

            if ($lineBreak !== null && ! $lineBreak instanceof Newline) {
                continue;
            }

            $callout = new Callout(strtoupper($matches[1]));

            $marker->detach();
            $lineBreak?->detach();

            if ($paragraph->firstChild() === null) {
                $paragraph->detach();
            }

            foreach ($quote->children() as $child) {
                $callout->appendChild($child);
            }

            $quote->replaceWith($callout);
        }
    }
}
