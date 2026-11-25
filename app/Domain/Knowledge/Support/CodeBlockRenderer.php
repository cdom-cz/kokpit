<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use Closure;
use InvalidArgumentException;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use Tempest\Highlight\Highlighter;
use Throwable;

/**
 * Renders every fenced and indented code block inside the fixed server-side chrome: a header with the language label
 * (omitted without a language) and exactly one Copy button, then the code. The Markdown author cannot produce a
 * button because raw HTML is escaped; the sanitiser allows button markup only for this fixed structure.
 *
 * The copied text is the textContent of the code element (D-14: no highlighting markup, no line numbers). The code
 * text is the node literal minus exactly one trailing newline. The literal never holds a CR: CommonMark splits the
 * input on CR, LF and CRLF and joins code lines with LF, so a CR kept inside stored code reads as one LF line break,
 * which is also what an HTML parser makes of it.
 *
 * Syntax highlighting (class-only hl-* spans) is accepted only when the round-trip invariant holds: the highlighted
 * HTML, stripped of tags and decoded, must be identical to the code text. The highlighter treats {+ +}, {- -}, {~ ~}
 * and {_ _} inside code as markers and removes them, which would make the displayed and copied text differ from
 * what the author wrote; such a block, an unknown language and an indented block render as plain escaped text.
 */
final class CodeBlockRenderer implements NodeRendererInterface
{
    /**
     * Accepted lowercase first word of the fence info string => Tempest language name, which is also the label.
     * Anything else, including an info string that tries markup, renders as plain text without a label.
     */
    public const array LANGUAGES = [
        'bash' => 'bash',
        'sh' => 'bash',
        'shell' => 'bash',
        'zsh' => 'bash',
        'php' => 'php',
        'blade' => 'blade',
        'sql' => 'sql',
        'json' => 'json',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'xml' => 'xml',
        'html' => 'html',
        'css' => 'css',
        'scss' => 'scss',
        'javascript' => 'javascript',
        'js' => 'javascript',
        'typescript' => 'typescript',
        'ts' => 'typescript',
        'diff' => 'diff',
        'dockerfile' => 'dockerfile',
        'docker' => 'dockerfile',
        'ini' => 'ini',
        'dotenv' => 'dotenv',
        'env' => 'dotenv',
        'nginx' => 'nginx',
        'apache' => 'apache',
        'http' => 'http',
        'python' => 'python',
        'py' => 'python',
        'go' => 'go',
        'rust' => 'rust',
        'rs' => 'rust',
        'toml' => 'toml',
        'twig' => 'twig',
        'markdown' => 'markdown',
        'md' => 'markdown',
        'terraform' => 'terraform',
        'tf' => 'terraform',
    ];

    /**
     * The only span classes of highlighted code that are accepted (the class-only theme, the diff line classes).
     */
    private const array TOKEN_CLASSES = [
        'hl-keyword', 'hl-operator', 'hl-type', 'hl-value', 'hl-variable', 'hl-property', 'hl-attribute',
        'hl-generic', 'hl-number', 'hl-literal', 'hl-comment', 'hl-injection',
        'hl-addition', 'hl-deletion', 'hl-diff-context',
    ];

    /**
     * @param  Closure(string, array<string, string>=): string  $translate  relative key and replacements to text
     */
    public function __construct(
        private readonly Highlighter $highlighter,
        private readonly Closure $translate,
    ) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (! $node instanceof FencedCode && ! $node instanceof IndentedCode) {
            throw new InvalidArgumentException('Incompatible node type: '.$node::class);
        }

        $code = $node->getLiteral();

        if (str_ends_with($code, "\n")) {
            $code = substr($code, 0, -1);
        }

        $label = $node instanceof FencedCode ? $this->languageLabel($node) : null;

        $head = '';

        if ($label !== null) {
            $head .= '<span class="kokpit-code__lang">'.$this->escape($label).'</span>';
        }

        $head .= '<button type="button" class="kokpit-code__copy" data-kokpit-copy=""'
            .' data-label-copied="'.$this->escape(($this->translate)('code.copied')).'"'
            .' data-label-failed="'.$this->escape(($this->translate)('code.copy_failed')).'">'
            .'<span class="kokpit-code__copy-label" aria-live="polite">'.$this->escape(($this->translate)('code.copy')).'</span>'
            .'</button>';

        $ariaLabel = $label !== null
            ? ($this->translate)('code.label', ['language' => $label])
            : ($this->translate)('code.label_plain');

        return '<div class="kokpit-code"><div class="kokpit-code__head">'.$head.'</div>'
            .'<pre tabindex="0" aria-label="'.$this->escape($ariaLabel).'"><code>'.$this->codeHtml($code, $label).'</code></pre></div>';
    }

    /**
     * The Tempest language name of the first word of the info string, or null outside the allowlist.
     */
    private function languageLabel(FencedCode $node): ?string
    {
        $word = strtolower($node->getInfoWords()[0] ?? '');

        return self::LANGUAGES[$word] ?? null;
    }

    private function codeHtml(string $code, ?string $label): string
    {
        $plain = $this->escape($code);

        if ($label === null || $code === '') {
            return $plain;
        }

        try {
            $highlighted = $this->highlighter->parse($code, $label);
        } catch (Throwable) {
            return $plain;
        }

        if (html_entity_decode(strip_tags($highlighted), ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $code) {
            return $plain;
        }

        return $this->hasOnlyTokenClasses($highlighted) ? $highlighted : $plain;
    }

    /**
     * The markers {~ ~} and {_ _} keep their text inside hidden, inline-styled spans and add classes of their own, so
     * the text round trip alone does not catch them. Only class-only token spans of the fixed set are accepted.
     */
    private function hasOnlyTokenClasses(string $highlighted): bool
    {
        if (str_contains($highlighted, 'style=')) {
            return false;
        }

        preg_match_all('/<span class="([^"]*)">/', $highlighted, $matches);

        return array_diff($matches[1], self::TOKEN_CLASSES) === []
            && substr_count($highlighted, '<span') === count($matches[1]);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
