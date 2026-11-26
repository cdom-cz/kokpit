<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\DiffRow;
use App\Domain\Knowledge\Support\VersionDiff;
use App\Domain\Knowledge\Support\VersionDiffResult;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Tests\Support\KnowledgeHostileCorpus;
use Tests\Support\SecretFixtures;

/*
 * The diff component prints a VersionDiffResult as escaped, numbered, accessible rows (VER-04, D-09). Every expected
 * string is computed through __() here, so the tests hold before and after the copy keys land and do not pin the
 * wording (KnowledgeLangTest does). Every text is fictional; secret samples come from SecretFixtures only.
 */

/**
 * @param  list<DiffRow>  $rows
 */
function versionDiffViewResult(array $rows, bool $truncated = false): VersionDiffResult
{
    return new VersionDiffResult($rows, 1, 1, false, $truncated, false);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function versionDiffViewRender(VersionDiffResult $result, array $overrides = []): string
{
    return view('filament.knowledge.version-diff', [
        'result' => $result,
        'fromNo' => 2,
        'toNo' => 5,
        'fromTitle' => 'Example title',
        'toTitle' => 'Example title',
        'caption' => 'Example caption',
        'maxHeight' => null,
        ...$overrides,
    ])->render();
}

/**
 * The rule headers of a stylesheet with comments removed.
 *
 * @return list<string>
 */
function versionDiffViewSelectors(string $relativePath): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path($relativePath)));
    preg_match_all('/([^{}]+)\{/', $css, $matches);

    $selectors = [];

    foreach ($matches[1] as $header) {
        foreach (preg_split('/,(?![^(]*\))/', trim($header)) ?: [] as $selector) {
            $selectors[] = trim($selector);
        }
    }

    return $selectors;
}

it('renders a changed line as a table with caption, numbers, markers and hidden labels', function (): void {
    $html = versionDiffViewRender(versionDiffViewResult([
        DiffRow::context(1, 1, 'unchanged line'),
        DiffRow::removed(2, 'old line'),
        DiffRow::added(2, 'new line'),
    ]));

    expect($html)->toContain('<table class="kokpit-diff"')
        ->toContain('<caption')
        ->toContain('Example caption')
        ->toContain('unchanged line')
        ->toContain('old line')
        ->toContain('new line')
        ->toContain('<span aria-hidden="true">+</span>')
        ->toContain('<span aria-hidden="true">-</span>')
        ->toContain('kokpit-diff__row--added')
        ->toContain('kokpit-diff__row--removed')
        ->toContain('<span class="kokpit-diff__sr">'.e(__('knowledge.diff.added')).'</span>new line')
        ->toContain('<span class="kokpit-diff__sr">'.e(__('knowledge.diff.removed')).'</span>old line')
        ->toContain(e(__('knowledge.diff.summary', ['added' => 1, 'removed' => 1])))
        ->toContain(e(__('knowledge.diff.column_old', ['from' => 2])))
        ->toContain(e(__('knowledge.diff.column_new', ['to' => 5])))
        ->toContain(e(__('knowledge.diff.column_marker')))
        ->toContain(e(__('knowledge.diff.column_text')));
});

it('renders a collapsed row as one cell that spans the four columns', function (): void {
    $html = versionDiffViewRender(versionDiffViewResult([
        DiffRow::collapsed(17),
        DiffRow::context(18, 18, 'unchanged line'),
        DiffRow::removed(19, 'old line'),
    ]));

    expect($html)->toContain('colspan="4"')
        ->toContain(e(__('knowledge.diff.collapsed', ['count' => 17])));
});

it('shows two marked lines when the titles differ and a muted line when they do not', function (): void {
    $result = versionDiffViewResult([DiffRow::added(1, 'new line')]);

    $changed = versionDiffViewRender($result, ['fromTitle' => 'Old example title', 'toTitle' => 'New example title']);
    $same = versionDiffViewRender($result, ['fromTitle' => 'Same example title', 'toTitle' => 'Same example title']);

    expect($changed)->toContain('kokpit-diff__line--removed')
        ->toContain('kokpit-diff__line--added')
        ->toContain('Old example title')
        ->toContain('New example title')
        ->not->toContain(e(__('knowledge.diff.title_unchanged', ['title' => 'Old example title'])))
        ->and($same)->toContain(e(__('knowledge.diff.title_unchanged', ['title' => 'Same example title'])))
        ->not->toContain('kokpit-diff__line--removed');
});

it('shows the identical state and no table for equal texts', function (): void {
    $html = versionDiffViewRender(VersionDiffResult::identical());

    expect($html)->toContain(e(__('knowledge.diff.identical')))
        ->not->toContain('<table')
        ->not->toContain(e(__('knowledge.diff.summary', ['added' => 0, 'removed' => 0])));
});

it('shows the too-large state and no table', function (): void {
    $html = versionDiffViewRender(VersionDiffResult::tooLarge());

    expect($html)->toContain(e(__('knowledge.diff.too_large')))
        ->not->toContain('<table');
});

it('shows the truncation notice after the table with the row cap spaced in thousands', function (): void {
    $html = versionDiffViewRender(versionDiffViewResult([DiffRow::added(1, 'new line')], truncated: true));
    $notice = e(__('knowledge.diff.truncated', ['max' => '1 500']));

    expect($html)->toContain($notice)
        ->and(strpos($html, $notice))->toBeGreaterThan((int) strpos($html, '</table>'));

    expect(versionDiffViewRender(versionDiffViewResult([DiffRow::added(1, 'new line')])))
        ->not->toContain($notice);
});

it('bounds the scroll area only when a max height is given', function (): void {
    $result = versionDiffViewResult([DiffRow::added(1, 'new line')]);

    expect(versionDiffViewRender($result, ['maxHeight' => '60vh']))->toContain('max-height: 60vh;')
        ->and(versionDiffViewRender($result))->not->toContain('max-height');
});

it('prints every hostile text and a secret-shaped line only as escaped text', function (): void {
    $lines = [SecretFixtures::githubToken(), SecretFixtures::passwordAssignment()];

    foreach (KnowledgeHostileCorpus::cases() as $text) {
        array_push($lines, ...VersionDiff::lines($text));
    }

    $rows = [];

    foreach ($lines as $index => $line) {
        $rows[] = DiffRow::added($index + 1, $line);
    }

    $html = versionDiffViewRender(
        versionDiffViewResult($rows),
        ['fromTitle' => '<b>Old</b> example', 'toTitle' => '<i>New</i> example'],
    );

    foreach ($lines as $line) {
        expect($html)->toContain(e($line));
    }

    expect($html)->toContain('&lt;scr'.'ipt&gt;')
        ->not->toContain('<scr'.'ipt')
        ->not->toContain('<ifr'.'ame')
        ->not->toContain('<im'.'g ')
        ->not->toContain('<sty'.'le')
        ->not->toContain('href=')
        ->not->toContain('<b>Old')
        ->not->toContain('<i>New')
        ->toContain('&lt;b&gt;Old&lt;/b&gt; example');
});

it('registers the diff stylesheet as a Filament asset of the app package', function (): void {
    $style = null;

    foreach (FilamentAsset::getStyles(['app']) as $asset) {
        if ($asset instanceof Asset && $asset->getId() === 'kokpit-diff') {
            $style = $asset;
        }
    }

    expect($style)->toBeInstanceOf(Css::class)
        ->and($style->getPackage())->toBe('app')
        ->and($style->getPath())->toBe(resource_path('css/kokpit-diff.css'))
        ->and($style->getRelativePublicPath())->toBe('css/app/kokpit-diff.css')
        ->and(resource_path('css/kokpit-diff.css'))->toBeFile();
});

it('scopes every selector of the diff stylesheet under the diff or tree root', function (): void {
    $selectors = versionDiffViewSelectors('css/kokpit-diff.css');

    expect($selectors)->not->toBeEmpty();

    foreach ($selectors as $selector) {
        expect(preg_match('/^(\.dark )?\.kokpit-(diff|tree)/', $selector))->toBe(1, "unscoped selector: {$selector}");
    }
});

it('keeps the diff stylesheet on the agreed type scale and wraps long lines', function (): void {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kokpit-diff.css')));

    preg_match_all('/font-size:\s*([^;}]+)/', $css, $sizes);
    preg_match_all('/font-weight:\s*([^;}]+)/', $css, $weights);

    foreach ($sizes[1] as $size) {
        expect(trim($size))->toBeIn(['14px', '16px']);
    }

    foreach ($weights[1] as $weight) {
        expect(trim($weight))->toBeIn(['400', '600']);
    }

    expect($css)->toContain('overflow-wrap: anywhere')
        ->toContain('white-space: pre-wrap');
});
