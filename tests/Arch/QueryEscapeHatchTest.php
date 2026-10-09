<?php

declare(strict_types=1);

use Tests\Support\EscapeHatchScanner;

/*
 * Reading around the fail-closed scopes (a raw DB::table() query, a bare
 * withoutGlobalScopes() call or a call that removes the Partner scope by name) is allowed only in the files listed here, each
 * with the reason it is safe. The list is empty on purpose: add a line only
 * after the code was reviewed for a Partner leak.
 */
const ESCAPE_HATCH_ALLOWLIST = [
    // 'app/Example/File.php' => 'why this read cannot reach a Partner',
];

it('finds no raw table query, no bare withoutGlobalScopes call and no removal of the Partner scope in app outside the allowlist', function (): void {
    $found = [];
    $base = str_replace(DIRECTORY_SEPARATOR, '/', base_path()).'/';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    $scanned = 0;

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;
        $relative = str_replace($base, '', str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()));

        if (array_key_exists($relative, ESCAPE_HATCH_ALLOWLIST)) {
            continue;
        }

        foreach (EscapeHatchScanner::findings((string) file_get_contents($file->getPathname()), $relative) as $finding) {
            $found[] = "{$finding['file']}:{$finding['line']} {$finding['hatch']}";
        }
    }

    expect($scanned)->toBeGreaterThan(20)
        ->and($found)->toBe([]);
});

it('reports a raw table query with its file and line', function (): void {
    $source = "<?php\n\nuse Illuminate\\Support\\Facades\\DB;\n\n\$rows = DB::table('clients')->get();\n";

    expect(EscapeHatchScanner::findings($source, 'app/Example.php'))
        ->toBe([['file' => 'app/Example.php', 'line' => 5, 'hatch' => 'DB::table(']]);
});

it('reports the fully qualified form of a raw table query', function (): void {
    $source = "<?php\n\$rows = \\Illuminate\\Support\\Facades\\DB::table('clients')->get();\n";

    expect(EscapeHatchScanner::findings($source, 'app/Example.php'))->toHaveCount(1);
});

it('reports a withoutGlobalScopes call without arguments', function (): void {
    $source = "<?php\n\n\$all = Client::query()\n    ->withoutGlobalScopes()\n    ->get();\n";

    expect(EscapeHatchScanner::findings($source, 'app/Example.php'))
        ->toBe([['file' => 'app/Example.php', 'line' => 4, 'hatch' => 'withoutGlobalScopes()']]);
});

it('does not report withoutGlobalScopes with an argument, comments or strings', function (): void {
    $source = <<<'PHP'
        <?php

        // DB::table('x') and withoutGlobalScopes() in a comment
        /** withoutGlobalScopes() in a docblock */
        $a = Client::query()->withoutGlobalScopes([SoftDeletingScope::class])->get();
        $b = 'DB::table(';
        $c = DB::select('select 1');
        PHP;

    expect(EscapeHatchScanner::findings($source, 'app/Example.php'))->toBe([]);
});

it('reports withoutGlobalScope naming PartnerScope, short and fully qualified, with file and line', function (): void {
    $short = "<?php\n\n\$all = Project::query()\n    ->withoutGlobalScope(PartnerScope::class)\n    ->get();\n";
    $qualified = "<?php\n\$all = Project::query()->withoutGlobalScope(\\App\\Domain\\Shared\\Auth\\PartnerScope::class)->get();\n";

    expect(EscapeHatchScanner::findings($short, 'app/Example.php'))
        ->toBe([['file' => 'app/Example.php', 'line' => 4, 'hatch' => 'withoutGlobalScope(PartnerScope)']])
        ->and(EscapeHatchScanner::findings($qualified, 'app/Example.php'))
        ->toBe([['file' => 'app/Example.php', 'line' => 2, 'hatch' => 'withoutGlobalScope(PartnerScope)']]);
});

it('reports withoutGlobalScopes naming PartnerScope in an array, alone or next to the soft-delete scope', function (): void {
    $alone = "<?php\n\$all = Project::query()->withoutGlobalScopes([PartnerScope::class])->get();\n";
    $mixed = "<?php\n\$all = Project::query()->withoutGlobalScopes([\n    SoftDeletingScope::class,\n    \\App\\Domain\\Shared\\Auth\\PartnerScope::class,\n])->get();\n";

    expect(EscapeHatchScanner::findings($alone, 'app/Example.php'))
        ->toBe([['file' => 'app/Example.php', 'line' => 2, 'hatch' => 'withoutGlobalScope(PartnerScope)']])
        ->and(EscapeHatchScanner::findings($mixed, 'app/Example.php'))
        ->toBe([['file' => 'app/Example.php', 'line' => 2, 'hatch' => 'withoutGlobalScope(PartnerScope)']]);
});

it('does not report removing only the soft-delete scope, nor a PartnerScope mention in a comment or a string', function (): void {
    $source = <<<'PHP'
        <?php

        // ->withoutGlobalScope(PartnerScope::class) in a comment
        /** ->withoutGlobalScopes([PartnerScope::class]) in a docblock */
        $a = Project::query()->withoutGlobalScopes([SoftDeletingScope::class])->get();
        $b = Project::query()->withoutGlobalScope(SoftDeletingScope::class)->get();
        $c = 'withoutGlobalScope(PartnerScope::class)';
        $d = Project::query()->withoutGlobalScope(SoftDeletingScope::class)->where('x', PartnerScope::class)->get();
        $e = Project::query()->withGlobalScope('partner', new PartnerScope);
        PHP;

    expect(EscapeHatchScanner::findings($source, 'app/Example.php'))->toBe([]);
});
