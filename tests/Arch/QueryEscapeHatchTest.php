<?php

declare(strict_types=1);

use Tests\Support\EscapeHatchScanner;

/*
 * Reading around the fail-closed scopes (a raw DB::table() query or a bare
 * withoutGlobalScopes() call) is allowed only in the files listed here, each
 * with the reason it is safe. The list is empty on purpose: add a line only
 * after the code was reviewed for a Partner leak.
 */
const ESCAPE_HATCH_ALLOWLIST = [
    // 'app/Example/File.php' => 'why this read cannot reach a Partner',
];

it('finds no raw table query and no bare withoutGlobalScopes call in app outside the allowlist', function (): void {
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
