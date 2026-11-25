<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use Closure;
use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Themes\CssTheme;

/**
 * The one place where a knowledge body becomes HTML (KB-03). CommonMark with raw HTML escaped, the link and image
 * policy applied in the document tree, and a Kokpit-owned sanitiser as the second layer. The view screen, the preview
 * tab and every later consumer call render(); nothing else may render a knowledge body, and Filament's own markdown
 * helpers (raw HTML allowed, unsafe links allowed, global sanitiser) must never touch article text.
 *
 * Labels come from the injected translator over relative keys (code.copy, code.copied, code.copy_failed, code.label,
 * code.label_plain, task.done, task.open), so the class stays unit-testable without the application; the service
 * provider binds a translator over the knowledge.render lang keys. The class calls no Laravel helper or facade.
 *
 * There is no HTML cache: rendering takes a few tens of milliseconds for realistic bodies and the Admin is the only viewer.
 */
final class KnowledgeMarkdown
{
    /**
     * Longest body that is converted. The stored body is capped at the same length; only an unsaved preview can
     * exceed it, and it is then shown as escaped plain text, which bounds memory (adversarial bracket floods).
     */
    public const int MAX_INPUT_LENGTH = 100000;

    private readonly MarkdownConverter $converter;

    private readonly HtmlSanitizer $sanitizer;

    /**
     * @param  Closure(string, array<string, string>=): string  $translate  relative key and replacements to text
     */
    public function __construct(Closure $translate)
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'max_delimiters_per_line' => 1000,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new CalloutExtension($translate));
        $environment->addEventListener(DocumentParsedEvent::class, new LinkAndImagePolicy);

        $codeBlocks = new CodeBlockRenderer(new Highlighter(new CssTheme), $translate);
        $environment->addRenderer(FencedCode::class, $codeBlocks, 10);
        $environment->addRenderer(IndentedCode::class, $codeBlocks, 10);
        $environment->addRenderer(TaskListItemMarker::class, new TaskListMarkerRenderer($translate), 10);

        $this->converter = new MarkdownConverter($environment);
        $this->sanitizer = new HtmlSanitizer($this->sanitizerConfig());
    }

    public function render(string $markdown): HtmlString
    {
        if (trim($markdown) === '') {
            return new HtmlString('');
        }

        if (mb_strlen($markdown) > self::MAX_INPUT_LENGTH) {
            return new HtmlString(
                '<p class="kokpit-prose__plain">'.htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE).'</p>',
            );
        }

        return new HtmlString($this->sanitizer->sanitize($this->converter->convert($markdown)->getContent()));
    }

    /**
     * The sanitiser of knowledge bodies (R-18-03-4). Raw HTML is already escaped before it runs, so the author
     * cannot produce a button, a data attribute or a class: those entries exist only for the fixed server markup.
     * The input limit is lifted because the default of 20 000 characters truncates a long article silently.
     */
    private function sanitizerConfig(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks(false)
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowMediaSchemes([])
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->dropAttribute('target', 'a')
            ->allowElement('input', ['type', 'checked', 'disabled', 'aria-hidden'])
            ->allowElement('button', ['type', 'class', 'data-kokpit-copy', 'data-label-copied', 'data-label-failed'])
            ->allowElement('pre', ['tabindex', 'aria-label'])
            ->allowElement('code', [])
            ->allowElement('span', ['class', 'aria-live', 'aria-hidden'])
            ->allowElement('div', ['class'])
            ->allowElement('p', ['class'])
            ->withMaxInputLength(-1);
    }
}
