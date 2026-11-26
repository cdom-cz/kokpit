<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\SaveArticle;
use App\Filament\Resources\KnowledgeArticleResource;

/*
 * The seams of the knowledge center (D-06, VER-01, SEC-03): one renderer, one writer, no stray sanitiser, no global
 * search and no way to skip the stale check. The scans work on PHP tokens, like tests/Support/EscapeHatchScanner.php,
 * so a comment or a string never counts. Each rule has a self-check on an inline fixture that must be reported, so no
 * rule passes vacuously, and the scan of the real tree must find what it guards where it lives.
 */

/**
 * The only directory that may render Markdown or build an HTML sanitiser config for knowledge.
 */
const KNOWLEDGE_SEAM_SUPPORT_DIRECTORY = 'app/Domain/Knowledge/Support/';

/**
 * The renderer and its sanitiser config live here, and the converter is built here.
 */
const KNOWLEDGE_SEAM_RENDERER_FILE = 'app/Domain/Knowledge/Support/KnowledgeMarkdown.php';

/**
 * The only file that writes the body and the version number of an article.
 */
const KNOWLEDGE_SEAM_WRITER_FILE = 'app/Domain/Knowledge/Actions/SaveArticle.php';

/**
 * Files outside the knowledge module that build an HtmlSanitizerConfig of their own, each with the reason it is no
 * knowledge seam. Add a line only after the file was reviewed: it must never sanitise or render a knowledge text.
 */
const KNOWLEDGE_SEAM_SANITISER_ALLOWLIST = [
    'app/Domain/Shared/Text/RichText.php' => 'the shared rich-text sanitiser of other texts; it predates the knowledge center and renders no article',
];

/**
 * The properties each class of the Guard namespace may have (R-18-04-2): a finding holds the rule and the line, and
 * nothing else can hold matched text. A class that is not listed has none.
 */
const KNOWLEDGE_SEAM_GUARD_PROPERTIES = [
    'App\Domain\Knowledge\Guard\SecretFinding' => ['rule', 'line'],
];

/**
 * Every PHP file under app/, by path relative to the repository root, with its source.
 *
 * @return array<string, string>
 */
function knowledgeSeamAppFiles(): array
{
    /** @var array<string, string>|null $files */
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $files = [];
    $base = str_replace(DIRECTORY_SEPARATOR, '/', base_path()).'/';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace($base, '', str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()));
        $files[$relative] = (string) file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}

/**
 * The code tokens of a PHP source: whitespace and comments dropped, strings kept whole as one token.
 *
 * @return list<array{text: string, line: int}>
 */
function knowledgeSeamTokens(string $source): array
{
    $tokens = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $tokens[] = is_array($token)
            ? ['text' => $token[1], 'line' => $token[2]]
            : ['text' => $token, 'line' => $tokens === [] ? 1 : $tokens[array_key_last($tokens)]['line']];
    }

    return $tokens;
}

/**
 * The last segment of a possibly qualified name: `\League\CommonMark\MarkdownConverter` is `MarkdownConverter`.
 */
function knowledgeSeamLastSegment(string $name): string
{
    $position = strrpos($name, '\\');

    return $position === false ? $name : substr($name, $position + 1);
}

/**
 * The places of a PHP source that render Markdown: a `Str::markdown(` or `Str::inlineMarkdown(` call, a `->markdown(`
 * method call, or the creation of a CommonMark converter.
 *
 * @return list<string> one `what (line N)` entry per place
 */
function knowledgeSeamRenderers(string $source): array
{
    $tokens = knowledgeSeamTokens($source);
    $found = [];

    foreach ($tokens as $i => $token) {
        $text = $token['text'];
        $next = $tokens[$i + 1]['text'] ?? '';
        $afterNext = $tokens[$i + 2]['text'] ?? '';

        if (knowledgeSeamLastSegment($text) === 'Str' && $next === '::' && in_array(strtolower($afterNext), ['markdown', 'inlinemarkdown'], true) && ($tokens[$i + 3]['text'] ?? '') === '(') {
            $found[] = 'Str::'.$afterNext.'( (line '.$token['line'].')';
        }

        if (in_array($text, ['->', '?->'], true) && strtolower($next) === 'markdown' && $afterNext === '(') {
            $found[] = '->markdown( (line '.$token['line'].')';
        }

        if (strtolower($text) === 'new' && in_array(knowledgeSeamLastSegment($next), ['CommonMarkConverter', 'GithubFlavoredMarkdownConverter', 'MarkdownConverter'], true)) {
            $found[] = 'new '.knowledgeSeamLastSegment($next).' (line '.$token['line'].')';
        }
    }

    return $found;
}

/**
 * The lines of a PHP source that name an HtmlSanitizerConfig, a `use` line included.
 *
 * @return list<int>
 */
function knowledgeSeamSanitiserReferences(string $source): array
{
    $lines = [];

    foreach (knowledgeSeamTokens($source) as $token) {
        if (knowledgeSeamLastSegment($token['text']) === 'HtmlSanitizerConfig') {
            $lines[] = $token['line'];
        }
    }

    return $lines;
}

/**
 * The lines of a Blade source with a raw echo. Blade comments are blanked first, so a comment that talks about the
 * raw echo is no finding.
 *
 * @return list<int>
 */
function knowledgeSeamRawEchoes(string $blade): array
{
    $withoutComments = (string) preg_replace_callback(
        '/\{\{--.*?--\}\}/s',
        static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n")),
        $blade,
    );
    $lines = [];

    foreach (explode("\n", $withoutComments) as $index => $line) {
        if (str_contains($line, '{!!')) {
            $lines[] = $index + 1;
        }
    }

    return $lines;
}

/**
 * The writes of the body or the version number in a PHP source: an array key `'body_md' =>` or `'version_no' =>` (a
 * cast declaration `'version_no' => 'integer'` is no write), an assignment to the property, or an increment of it.
 *
 * @return list<string> one `what (line N)` entry per write
 */
function knowledgeSeamWrites(string $source): array
{
    $tokens = knowledgeSeamTokens($source);
    $columns = ['body_md', 'version_no'];
    $found = [];

    foreach ($tokens as $i => $token) {
        $text = $token['text'];
        $next = $tokens[$i + 1]['text'] ?? '';
        $afterNext = $tokens[$i + 2]['text'] ?? '';
        $key = trim($text, '\'"');

        if (in_array($key, $columns, true) && in_array($text[0], ['\'', '"'], true) && $next === '=>' && $afterNext !== "'integer'") {
            $found[] = "'".$key."' => (line ".$token['line'].')';
        }

        if (in_array($text, ['->', '?->'], true) && in_array($next, $columns, true) && in_array($afterNext, ['=', '+=', '-=', '.=', '++', '--'], true)) {
            $found[] = '->'.$next.' '.$afterNext.' (line '.$token['line'].')';
        }
    }

    return $found;
}

/**
 * Whether a PHP source declares the `$isGloballySearchable` property.
 */
function knowledgeSeamDeclaresSearchFlag(string $source): bool
{
    foreach (knowledgeSeamTokens($source) as $token) {
        if ($token['text'] === '$isGloballySearchable') {
            return true;
        }
    }

    return false;
}

/**
 * Whether the named parameter of a function or method has a default value.
 */
function knowledgeSeamHasDefault(ReflectionFunctionAbstract $function, string $parameter): bool
{
    foreach ($function->getParameters() as $candidate) {
        if ($candidate->getName() === $parameter) {
            return $candidate->isDefaultValueAvailable();
        }
    }

    throw new InvalidArgumentException('No parameter named '.$parameter.'.');
}

/**
 * The properties of the given classes that their list does not allow, as `Class::property`.
 *
 * @param  array<class-string, list<string>>  $found  class => its property names
 * @param  array<class-string, list<string>>  $allowed  class => the property names it may have
 * @return list<string>
 */
function knowledgeSeamUnexpectedProperties(array $found, array $allowed): array
{
    $unexpected = [];

    foreach ($found as $class => $properties) {
        foreach (array_diff($properties, $allowed[$class] ?? []) as $property) {
            $unexpected[] = $class.'::'.$property;
        }
    }

    return $unexpected;
}

/**
 * The declared properties of every class, interface and enum of the Guard namespace, by class name. The `name` and
 * `value` of an enum are not declared properties and are left out.
 *
 * @return array<class-string, list<string>>
 */
function knowledgeSeamGuardProperties(): array
{
    $found = [];

    foreach (glob(app_path('Domain/Knowledge/Guard/*.php')) ?: [] as $path) {
        /** @var class-string $class */
        $class = 'App\\Domain\\Knowledge\\Guard\\'.basename($path, '.php');
        $reflection = new ReflectionClass($class);
        $names = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class || ($reflection->isEnum() && in_array($property->getName(), ['name', 'value'], true))) {
                continue;
            }

            $names[] = $property->getName();
        }

        $found[$class] = $names;
    }

    return $found;
}

it('renders Markdown only inside app/Domain/Knowledge/Support', function (): void {
    $files = knowledgeSeamAppFiles();
    $found = [];

    foreach ($files as $path => $source) {
        if (str_starts_with($path, KNOWLEDGE_SEAM_SUPPORT_DIRECTORY)) {
            continue;
        }

        foreach (knowledgeSeamRenderers($source) as $place) {
            $found[] = $path.': '.$place;
        }
    }

    expect(count($files))->toBeGreaterThan(20)
        ->and($found)->toBe([])
        // The scan sees the one converter where it lives.
        ->and(implode("\n", knowledgeSeamRenderers($files[KNOWLEDGE_SEAM_RENDERER_FILE] ?? '')))->toContain('new MarkdownConverter');
});

it('reports a Markdown call, a method call and a converter outside the renderer, and ignores comments and strings', function (): void {
    $violations = <<<'PHP'
        <?php

        $a = Str::markdown($text);
        $b = \Illuminate\Support\Str::inlineMarkdown($text);
        $c = $string->markdown();
        $d = new CommonMarkConverter;
        $e = new \League\CommonMark\GithubFlavoredMarkdownConverter($config);
        $f = new MarkdownConverter($environment);
        PHP;
    $clean = <<<'PHP'
        <?php

        // Str::markdown($text) and new MarkdownConverter in a comment
        /** ->markdown() in a docblock */
        $a = 'Str::markdown(';
        $b = $mail->markdown_view();
        PHP;

    expect(knowledgeSeamRenderers($violations))->toHaveCount(6)
        ->and(knowledgeSeamRenderers($clean))->toBe([]);
});

it('builds an HTML sanitiser config only in the renderer, apart from the reviewed allowlist', function (): void {
    $files = knowledgeSeamAppFiles();
    $found = [];

    foreach ($files as $path => $source) {
        if ($path === KNOWLEDGE_SEAM_RENDERER_FILE || array_key_exists($path, KNOWLEDGE_SEAM_SANITISER_ALLOWLIST)) {
            continue;
        }

        foreach (knowledgeSeamSanitiserReferences($source) as $line) {
            $found[] = $path.':'.$line;
        }
    }

    expect($found)->toBe([])
        // The scan sees the config where it lives, and every allowlist entry is still needed.
        ->and(knowledgeSeamSanitiserReferences($files[KNOWLEDGE_SEAM_RENDERER_FILE] ?? ''))->not->toBe([]);

    foreach (array_keys(KNOWLEDGE_SEAM_SANITISER_ALLOWLIST) as $path) {
        expect(knowledgeSeamSanitiserReferences($files[$path] ?? ''))->not->toBe([], $path.' no longer names a sanitiser config: remove it from the allowlist');
    }
});

it('reports a sanitiser config in any other file, qualified or not, and ignores comments', function (): void {
    $violation = "<?php\n\nuse Symfony\\Component\\HtmlSanitizer\\HtmlSanitizerConfig;\n\n\$config = new HtmlSanitizerConfig;\n";
    $comment = "<?php\n\n// HtmlSanitizerConfig in a comment\n";

    expect(knowledgeSeamSanitiserReferences($violation))->toBe([3, 5])
        ->and(knowledgeSeamSanitiserReferences($comment))->toBe([]);
});

it('uses no raw Blade echo in a knowledge view', function (): void {
    $views = glob(resource_path('views/filament/knowledge/*.blade.php')) ?: [];
    $found = [];

    foreach ($views as $view) {
        foreach (knowledgeSeamRawEchoes((string) file_get_contents($view)) as $line) {
            $found[] = basename($view).':'.$line;
        }
    }

    expect(count($views))->toBeGreaterThanOrEqual(3)
        ->and($found)->toBe([]);
});

it('reports a raw Blade echo and ignores a Blade comment that names it', function (): void {
    $violation = "<div>\n    {!! \$html !!}\n</div>\n";
    $comment = "{{-- a raw {!! \$html !!} echo is not allowed --}}\n<div>{{ \$html }}</div>\n";

    expect(knowledgeSeamRawEchoes($violation))->toBe([2])
        ->and(knowledgeSeamRawEchoes($comment))->toBe([]);
});

it('writes the body and the version number of an article only in SaveArticle', function (): void {
    $files = knowledgeSeamAppFiles();
    $found = [];

    foreach ($files as $path => $source) {
        if ($path === KNOWLEDGE_SEAM_WRITER_FILE) {
            continue;
        }

        foreach (knowledgeSeamWrites($source) as $place) {
            $found[] = $path.': '.$place;
        }
    }

    $writer = implode("\n", knowledgeSeamWrites($files[KNOWLEDGE_SEAM_WRITER_FILE] ?? ''));

    expect($found)->toBe([])
        // The scan sees both keys where they are written.
        ->and($writer)->toContain("'body_md' =>")
        ->and($writer)->toContain("'version_no' =>");
});

it('reports a write of the body or the version number in any other file, and ignores a cast', function (): void {
    $violations = <<<'PHP'
        <?php

        $article->forceFill(['body_md' => $text]);
        $article->forceFill(["version_no" => 3]);
        $article->version_no = 3;
        $article->version_no++;
        $article->body_md .= 'x';
        PHP;
    $clean = <<<'PHP'
        <?php

        // 'body_md' => $text
        $casts = ['version_no' => 'integer'];
        $copy = $version->body_md;
        $query->where('version_no', 3);
        if ($article->version_no === 3) {}
        PHP;

    expect(knowledgeSeamWrites($violations))->toHaveCount(5)
        ->and(knowledgeSeamWrites($clean))->toBe([]);
});

it('is not globally searchable, and the resource does not declare its own search flag', function (): void {
    $source = (string) file_get_contents(app_path('Filament/Resources/KnowledgeArticleResource.php'));
    $declaring = (new ReflectionProperty(KnowledgeArticleResource::class, 'isGloballySearchable'))->getDeclaringClass()->getName();

    expect(knowledgeSeamDeclaresSearchFlag($source))->toBeFalse()
        ->and($declaring)->not->toBe(KnowledgeArticleResource::class)
        ->and(KnowledgeArticleResource::canGloballySearch())->toBeFalse();
});

it('reports a resource that declares its own search flag, and ignores a comment', function (): void {
    $violation = "<?php\n\nfinal class Example\n{\n    protected static bool \$isGloballySearchable = true;\n}\n";
    $comment = "<?php\n\n// protected static bool \$isGloballySearchable = true;\nfinal class Example {}\n";

    expect(knowledgeSeamDeclaresSearchFlag($violation))->toBeTrue()
        ->and(knowledgeSeamDeclaresSearchFlag($comment))->toBeFalse();
});

it('gives the expected version of SaveArticle::update() no default', function (): void {
    $update = new ReflectionMethod(SaveArticle::class, 'update');

    expect(knowledgeSeamHasDefault($update, 'expectedVersion'))->toBeFalse();
});

it('gives the expected redaction number of SaveArticle::update() no default', function (): void {
    $update = new ReflectionMethod(SaveArticle::class, 'update');

    expect(knowledgeSeamHasDefault($update, 'expectedRedactionNo'))->toBeFalse();
});

it('reports a default on a parameter, and a parameter that does not exist', function (): void {
    $defaulted = new ReflectionFunction(static fn (int $expectedVersion = 1): int => $expectedVersion);
    $required = new ReflectionFunction(static fn (int $expectedVersion): int => $expectedVersion);

    expect(knowledgeSeamHasDefault($defaulted, 'expectedVersion'))->toBeTrue()
        ->and(knowledgeSeamHasDefault($required, 'expectedVersion'))->toBeFalse()
        ->and(fn () => knowledgeSeamHasDefault($required, 'missing'))->toThrow(InvalidArgumentException::class);
});

it('gives no class of the secret guard a property that could carry matched text', function (): void {
    $found = knowledgeSeamGuardProperties();

    expect(count($found))->toBeGreaterThanOrEqual(4)
        // The scan sees the finding and its two properties.
        ->and($found['App\Domain\Knowledge\Guard\SecretFinding'] ?? null)->toBe(['rule', 'line'])
        ->and(knowledgeSeamUnexpectedProperties($found, KNOWLEDGE_SEAM_GUARD_PROPERTIES))->toBe([]);
});

it('reports a guard class with an extra property', function (): void {
    $found = [
        'App\Domain\Knowledge\Guard\SecretFinding' => ['rule', 'line', 'matched'],
        'App\Domain\Knowledge\Guard\SecretScanner' => ['lastText'],
    ];

    expect(knowledgeSeamUnexpectedProperties($found, KNOWLEDGE_SEAM_GUARD_PROPERTIES))->toBe([
        'App\Domain\Knowledge\Guard\SecretFinding::matched',
        'App\Domain\Knowledge\Guard\SecretScanner::lastText',
    ]);
});
