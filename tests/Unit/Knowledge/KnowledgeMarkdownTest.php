<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\ImageContext;
use App\Domain\Knowledge\Support\InternalImageSrcSanitizer;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use Dom\HTMLDocument;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Tests\Support\KnowledgeHostileCorpus;

/*
 * This file pins the one knowledge renderer (KB-03): a hostile body comes out as escaped text or dropped links and
 * never as live markup, tables and task lists render, a long body is not truncated, and an adversarial or oversized
 * body stays within the memory and size limits. Unit tests run without the application, so the renderer gets an
 * identity translator. Every text is fictional; the hostile parts come from tests/Support/KnowledgeHostileCorpus,
 * assembled from fragments at runtime.
 */

/**
 * The renderer with an identity translator: a key comes back as itself, replacements are appended after a bar.
 */
function knowledgeMarkdownRenderer(): KnowledgeMarkdown
{
    return new KnowledgeMarkdown(
        static fn (string $key, array $replace = []): string => $key.($replace === [] ? '' : '|'.implode('|', $replace)),
    );
}

function knowledgeMarkdownHtml(string $markdown): string
{
    return (string) knowledgeMarkdownRenderer()->render($markdown);
}

function knowledgeMarkdownDom(string $html): HTMLDocument
{
    return HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);
}

it('renders no live markup for any hostile body', function (string $markdown): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml($markdown));

    expect($dom->querySelectorAll('script, iframe, style, img, object, embed, form, link, meta'))->toHaveCount(0);

    foreach ($dom->querySelectorAll('*') as $element) {
        foreach ($element->attributes as $attribute) {
            expect($attribute->name)
                ->not->toStartWith('on')
                ->not->toBe('style')
                ->not->toBe('target');
        }
    }

    foreach ($dom->querySelectorAll('a') as $anchor) {
        expect($anchor->hasAttribute('href'))->toBeTrue();
        expect($anchor->getAttribute('href'))->toMatch('#^(https://|http://|mailto:)#');
    }
})->with(KnowledgeHostileCorpus::cases());

it('shows the raw script tag as visible text', function (): void {
    $tag = '<'.'scr'.'ipt>';
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml('Before '.$tag.'window.exampleMarker()</scr'.'ipt> after'));

    expect($dom->body->textContent)->toContain($tag)->toContain('window.exampleMarker()');
});

it('shows html entities typed in the body as text', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml('Example &lt;b&gt; and &amp; and &#60;i&#62; text'));

    expect($dom->querySelectorAll('b, i'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain('<b>')->toContain('<i>')->toContain('&');
});

it('turns every unsafe link into its plain link text', function (string $label): void {
    $markdown = KnowledgeHostileCorpus::cases()[$label];
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml($markdown));

    expect($dom->querySelectorAll('a'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain($label === 'autolink with the script scheme' ? 'exampleMarker()' : 'Example link');
})->with([
    'link with the script scheme in mixed case',
    'link with the data scheme',
    'link with the vbscript scheme',
    'relative link',
    'protocol-relative link',
    'reference-style script link',
    'autolink with the script scheme',
]);

it('keeps an https link, a mailto link and an autolink with the forced rel and no target', function (): void {
    $mail = 'jane@'.implode('.', ['example', 'com']);
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml(
        "[Example link](https://example.com/page)\n\n[Example mail](mailto:{$mail})\n\nSee https://example.com/auto now",
    ));
    $anchors = $dom->querySelectorAll('a');

    expect($anchors)->toHaveCount(3)
        ->and($anchors->item(0)->getAttribute('href'))->toBe('https://example.com/page')
        ->and($anchors->item(0)->getAttribute('rel'))->toBe('noopener noreferrer nofollow')
        ->and($anchors->item(1)->getAttribute('href'))->toBe('mailto:'.$mail)
        ->and($anchors->item(2)->getAttribute('href'))->toBe('https://example.com/auto');

    foreach ($anchors as $anchor) {
        expect($anchor->hasAttribute('target'))->toBeFalse()
            ->and($anchor->getAttribute('rel'))->toBe('noopener noreferrer nofollow');
    }
});

it('turns every image into its alt text and never emits an img element', function (string $label): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml(KnowledgeHostileCorpus::cases()[$label]));

    expect($dom->querySelectorAll('img'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain('Example alt');
})->with([
    'image with an https source',
    'image with a data source',
]);

it('keeps the link around an image that carries an https link, as its alt text', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml('[![Example alt](https://example.com/a.png)](https://example.com/page)'));
    $anchor = $dom->querySelector('a');

    expect($dom->querySelectorAll('img'))->toHaveCount(0)
        ->and($anchor?->getAttribute('href'))->toBe('https://example.com/page')
        ->and($anchor?->textContent)->toBe('Example alt');
});

it('returns an empty string for a blank body', function (string $markdown): void {
    expect(knowledgeMarkdownHtml($markdown))->toBe('');
})->with(['', "  \n ", "\n\n\t"]);

it('renders the same body to the same string twice', function (): void {
    $markdown = "# Example heading\n\nExample **bold** text and `code`.\n\n- one\n- two\n";

    expect(knowledgeMarkdownHtml($markdown))->toBe(knowledgeMarkdownHtml($markdown))
        ->and(knowledgeMarkdownHtml($markdown))->toContain('<h1>Example heading</h1>')->toContain('<strong>bold</strong>');
});

it('renders a GFM table with header and body cells', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml("| Example head | Other head |\n| --- | --- |\n| Example cell | Other cell |\n"));

    expect($dom->querySelectorAll('table'))->toHaveCount(1)
        ->and($dom->querySelectorAll('th'))->toHaveCount(2)
        ->and($dom->querySelectorAll('td'))->toHaveCount(2)
        ->and($dom->querySelector('td')?->textContent)->toBe('Example cell');
});

it('renders a task item as a disabled aria-hidden checkbox followed by a hidden done or open prefix', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml("- [x] Example done\n- [ ] Example open\n"));
    $boxes = $dom->querySelectorAll('input[type="checkbox"]');

    expect($boxes)->toHaveCount(2);

    $done = $boxes->item(0);
    $open = $boxes->item(1);

    expect($done->hasAttribute('checked'))->toBeTrue()
        ->and($open->hasAttribute('checked'))->toBeFalse();

    foreach ([[$done, 'task.done', 'Example done'], [$open, 'task.open', 'Example open']] as [$box, $prefix, $text]) {
        $label = $box->nextElementSibling;

        expect($box->hasAttribute('disabled'))->toBeTrue()
            ->and($box->getAttribute('aria-hidden'))->toBe('true')
            ->and($label?->getAttribute('class'))->toBe('kokpit-sr-only')
            ->and(trim((string) $label?->textContent))->toBe($prefix)
            ->and(preg_replace('/\s+/', ' ', trim((string) $box->parentNode?->textContent)))->toBe($prefix.' '.$text);
    }
});

it('renders strikethrough and autolinks of https urls', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml('~~Example gone~~ and https://example.com/auto here'));

    expect($dom->querySelector('del')?->textContent)->toBe('Example gone')
        ->and($dom->querySelector('a')?->getAttribute('href'))->toBe('https://example.com/auto');
});

it('does not truncate a long paragraph at the default sanitiser limit', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml(KnowledgeHostileCorpus::longParagraph(30000)));

    expect(mb_strlen($dom->body->textContent))->toBeGreaterThanOrEqual(30000);
});

it('renders an adversarial flood without error within the memory and time budget', function (string $method): void {
    $markdown = KnowledgeHostileCorpus::{$method}(100000);
    $renderer = knowledgeMarkdownRenderer();

    $before = memory_get_usage();
    memory_reset_peak_usage();
    $started = microtime(true);
    $html = (string) $renderer->render($markdown);
    $seconds = microtime(true) - $started;
    $extra = memory_get_peak_usage() - $before;

    expect($html)->not->toBe('')
        ->and($extra)->toBeLessThanOrEqual(256 * 1024 * 1024)
        ->and($seconds)->toBeLessThan(5.0);
})->with(['bracketFlood', 'emphasisFlood', 'backtickFlood']);

it('renders a body above the size cap as escaped plain text without markdown processing', function (): void {
    $body = '**Example** '.str_repeat('a', KnowledgeMarkdown::MAX_INPUT_LENGTH - 11).' <b>x</b>';

    expect(mb_strlen($body))->toBeGreaterThan(KnowledgeMarkdown::MAX_INPUT_LENGTH);

    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml($body));
    $plain = $dom->querySelector('p.kokpit-prose__plain');

    expect($plain)->not->toBeNull()
        ->and($plain->textContent)->toBe($body)
        ->and($dom->querySelectorAll('strong, b'))->toHaveCount(0);
});

it('renders a body of MAX_INPUT_LENGTH + 1 characters as plain text and of exactly the cap as markdown', function (): void {
    $over = '**Example** '.str_repeat('a', KnowledgeMarkdown::MAX_INPUT_LENGTH + 1 - 12);
    $exact = '**Example** '.str_repeat('a', KnowledgeMarkdown::MAX_INPUT_LENGTH - 12);

    expect(mb_strlen($over))->toBe(KnowledgeMarkdown::MAX_INPUT_LENGTH + 1)
        ->and(mb_strlen($exact))->toBe(KnowledgeMarkdown::MAX_INPUT_LENGTH)
        ->and(knowledgeMarkdownDom(knowledgeMarkdownHtml($over))->querySelectorAll('strong'))->toHaveCount(0)
        ->and(knowledgeMarkdownHtml($over))->toContain('**Example**')
        ->and(knowledgeMarkdownDom(knowledgeMarkdownHtml($exact))->querySelectorAll('strong'))->toHaveCount(1);
});

/**
 * A fictional uuid, distinct per number.
 */
function knowledgeMarkdownImageId(int $number = 1): string
{
    return '0190f3a0-0000-7000-8000-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
}

/**
 * The context that lists the given image numbers, each on its own internal route path and with a 10 by 5 size.
 */
function knowledgeMarkdownImages(int ...$numbers): ImageContext
{
    $images = [];

    foreach ($numbers as $number) {
        $id = knowledgeMarkdownImageId($number);
        $images[$id] = ['src' => '/admin/knowledge/attachments/'.$id, 'width' => 10, 'height' => 5];
    }

    return ImageContext::of($images);
}

function knowledgeMarkdownHtmlWithImages(string $markdown, ?ImageContext $images, ?KnowledgeMarkdown $renderer = null): string
{
    return (string) ($renderer ?? knowledgeMarkdownRenderer())->render($markdown, $images);
}

it('renders a listed internal reference as an img on the authorised route with its size, lazy and async', function (): void {
    $id = knowledgeMarkdownImageId();
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages('![Example alt](kokpit-attachment:'.$id.')', knowledgeMarkdownImages(1)));
    $images = $dom->querySelectorAll('img');

    expect($images)->toHaveCount(1);

    $image = $images->item(0);

    expect($image->getAttribute('src'))->toBe('/admin/knowledge/attachments/'.$id)
        ->and($image->getAttribute('alt'))->toBe('Example alt')
        ->and($image->getAttribute('width'))->toBe('10')
        ->and($image->getAttribute('height'))->toBe('5')
        ->and($image->getAttribute('loading'))->toBe('lazy')
        ->and($image->getAttribute('decoding'))->toBe('async');
});

it('resolves a reference written with an uppercase uuid the same way', function (): void {
    $id = knowledgeMarkdownImageId();
    $image = knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages('![Example alt](kokpit-attachment:'.strtoupper($id).')', knowledgeMarkdownImages(1)))->querySelector('img');

    expect($image?->getAttribute('src'))->toBe('/admin/knowledge/attachments/'.$id);
});

it('does not leak the image context of one render into the next render of the same renderer', function (): void {
    $renderer = knowledgeMarkdownRenderer();
    $markdown = '![Example alt](kokpit-attachment:'.knowledgeMarkdownImageId().')';

    expect(knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages($markdown, knowledgeMarkdownImages(1), $renderer))->querySelectorAll('img'))->toHaveCount(1)
        ->and(knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages($markdown, null, $renderer))->querySelectorAll('img'))->toHaveCount(0)
        ->and(knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages($markdown, ImageContext::none(), $renderer))->querySelectorAll('img'))->toHaveCount(0);
});

it('does not leak the context when the conversion throws', function (): void {
    $renderer = new KnowledgeMarkdown(static function (string $key): string {
        throw new RuntimeException('translator failure');
    });
    $markdown = "```php\necho 1;\n```\n\n![Example alt](kokpit-attachment:".knowledgeMarkdownImageId().')';

    expect(fn () => $renderer->render($markdown, knowledgeMarkdownImages(1)))->toThrow(RuntimeException::class);

    $policy = (new ReflectionProperty($renderer, 'policy'))->getValue($renderer);
    $images = (new ReflectionProperty($policy, 'images'))->getValue($policy);

    expect($images->ids())->toBe([]);
});

it('renders no img for a uuid that is not in the context', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages('![Example alt](kokpit-attachment:'.knowledgeMarkdownImageId(2).')', knowledgeMarkdownImages(1)));

    expect($dom->querySelectorAll('img'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain('Example alt');
});

it('keeps the link and the img of an internal image inside an https link', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages(
        '[![Example alt](kokpit-attachment:'.knowledgeMarkdownImageId().')](https://example.com/page)',
        knowledgeMarkdownImages(1),
    ));

    expect($dom->querySelector('a')?->getAttribute('href'))->toBe('https://example.com/page')
        ->and($dom->querySelectorAll('a img'))->toHaveCount(1);
});

it('keeps an internal reference inside a fenced code block as code text', function (): void {
    $reference = '![Example alt](kokpit-attachment:'.knowledgeMarkdownImageId().')';
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages("```\n".$reference."\n```", knowledgeMarkdownImages(1)));

    expect($dom->querySelectorAll('img'))->toHaveCount(0)
        ->and($dom->querySelector('pre')?->textContent)->toContain($reference);
});

it('keeps raw img html with the internal path as escaped text', function (): void {
    $tag = '<img src="/admin/knowledge/attachments/'.knowledgeMarkdownImageId().'" alt="Example alt">';
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages('Before '.$tag.' after', knowledgeMarkdownImages(1)));

    expect($dom->querySelectorAll('img'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain($tag);
});

it('keeps an external image as plain alt text even when a context is passed', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtmlWithImages('![Example alt](https://example.com/tracker.png)', knowledgeMarkdownImages(1)));

    expect($dom->querySelectorAll('img'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain('Example alt');
});

it('refuses to build a context with a foreign src, an uppercase uuid in the src or a zero size', function (array $image): void {
    expect(fn () => ImageContext::of([knowledgeMarkdownImageId() => $image]))->toThrow(InvalidArgumentException::class);
})->with([
    'absolute src' => [['src' => 'https://example.com/a.png', 'width' => 10, 'height' => 5]],
    'uppercase uuid in the src' => [['src' => '/admin/knowledge/attachments/0190F3A0-0000-7000-8000-000000000001', 'width' => 10, 'height' => 5]],
    'zero width' => [['src' => '/admin/knowledge/attachments/0190f3a0-0000-7000-8000-000000000001', 'width' => 0, 'height' => 5]],
    'zero height' => [['src' => '/admin/knowledge/attachments/0190f3a0-0000-7000-8000-000000000001', 'width' => 10, 'height' => 0]],
]);

it('lowercases the keys of a context and finds an entry by any case', function (): void {
    $id = knowledgeMarkdownImageId();
    $context = ImageContext::of([strtoupper($id) => ['src' => '/admin/knowledge/attachments/'.$id, 'width' => 10, 'height' => 5]]);

    expect($context->ids())->toBe([$id])
        ->and($context->find(strtoupper($id)))->toBe(['src' => '/admin/knowledge/attachments/'.$id, 'width' => 10, 'height' => 5])
        ->and($context->find(knowledgeMarkdownImageId(2)))->toBeNull()
        ->and(ImageContext::none()->ids())->toBe([]);
});

it('keeps only the internal image route as an img src in the sanitiser rule', function (string $value, bool $kept): void {
    $rule = new InternalImageSrcSanitizer;

    expect($rule->getSupportedElements())->toBe(['img'])
        ->and($rule->getSupportedAttributes())->toBe(['src'])
        ->and($rule->sanitizeAttribute('img', 'src', $value, new HtmlSanitizerConfig))->toBe($kept ? $value : null);
})->with([
    'the internal route' => ['/admin/knowledge/attachments/0190f3a0-0000-7000-8000-000000000001', true],
    'an absolute url' => ['https://example.com/x.png', false],
    'a protocol-relative url' => ['//example.com/a.png', false],
    'the route with a query' => ['/admin/knowledge/attachments/0190f3a0-0000-7000-8000-000000000001?x=1', false],
    'the route with a trailing slash' => ['/admin/knowledge/attachments/0190f3a0-0000-7000-8000-000000000001/', false],
    'the route with a trailing newline' => ["/admin/knowledge/attachments/0190f3a0-0000-7000-8000-000000000001\n", false],
    'another file route' => ['/admin/files/documents/0190f3a0-0000-7000-8000-000000000001', false],
    'a data source' => ['data:image/png;base64,AAAA', false],
]);

it('keeps the img src through the sanitiser only on the internal route even when a body is crafted', function (): void {
    $id = knowledgeMarkdownImageId();

    // A listed reference is the only way an img src is produced; the sanitiser output holds exactly one img.
    $html = knowledgeMarkdownHtmlWithImages('![Example alt](kokpit-attachment:'.$id.')', knowledgeMarkdownImages(1));

    expect($html)->toContain('src="/admin/knowledge/attachments/'.$id.'"')
        ->and($html)->not->toContain('https://')
        ->and(substr_count($html, '<img'))->toBe(1);
});
