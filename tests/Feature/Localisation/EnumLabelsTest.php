<?php

declare(strict_types=1);

use Filament\Support\Contracts\HasLabel;
use Tests\Support\Fixtures\TranslatedFixtureEnum;
use Tests\Support\Fixtures\UntranslatedFixtureEnum;
use Tests\Support\Localisation\EnumLabelChecker;

/**
 * Every enum in app/ that implements HasLabel, found by scanning the PSR-4 tree.
 *
 * @return list<class-string>
 */
function appLabelEnums(): array
{
    $enums = [];
    $root = app_path();

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
        $class = 'App\\'.str_replace('/', '\\', $relative);

        if (enum_exists($class) && is_subclass_of($class, HasLabel::class)) {
            $enums[] = $class;
        }
    }

    sort($enums);

    return $enums;
}

it('reports a fixture enum without translations, so the check cannot pass vacuously', function (): void {
    $problems = EnumLabelChecker::problems([UntranslatedFixtureEnum::class]);

    expect($problems)->toHaveCount(2)
        ->and($problems[0])->toContain('UntranslatedFixtureEnum::Alpha')
        ->and($problems[1])->toContain('UntranslatedFixtureEnum::Beta');
});

it('reports a class that is not a labelled enum', function (): void {
    expect(EnumLabelChecker::problems([stdClass::class]))->toHaveCount(1);
});

it('finds no problem in an enum whose labels are translated', function (): void {
    expect(EnumLabelChecker::problems([TranslatedFixtureEnum::class]))->toBe([]);
});

it('has a translated Czech label for every case of every app enum implementing HasLabel', function (): void {
    // An empty list is allowed until the first app enum arrives in plan 02-09.
    expect(EnumLabelChecker::problems(appLabelEnums()))->toBe([]);
});
