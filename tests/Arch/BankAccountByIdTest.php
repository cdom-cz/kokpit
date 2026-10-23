<?php

declare(strict_types=1);

use App\Domain\Invoicing\Support\InvoiceSnapshot;
use App\Domain\Settings\Settings\BankAccountSettings;

/*
 * The confinement of the bank account lookup by currency (Phase 14; D-04, D-05; the "promote" decision of the
 * assumption delta of plan 14-01; threat T-14-35).
 *
 * A document points at its bank account by id. The currency only suggests an account while the Admin prepares a
 * document, through the one preselection DocumentPayment::preselect(), so no later change of the settings or of the
 * currency can quietly re-couple a document to "the account of its currency". Three guards keep it so:
 *
 * 1. The old lookup BankAccountSettings::forCurrency() is deleted, and no application file calls it again. Its call
 *    text is built from two fragments below, so this file is not a match target of a plain text search for it. The
 *    scan is case-sensitive, so the needle never matches the preselection lookup firstForCurrency( (capital F).
 * 2. The files under app/ that call firstForCurrency( are exactly its owner and DocumentPayment, which wraps it in the
 *    preselection and in the fallback for a document that stores no account id. A new caller is a decision of its own
 *    and has to extend the allowlist below with a reason.
 * 3. The deleted methods do not exist: BankAccountSettings::forCurrency() and the currency-based snapshot
 *    InvoiceSnapshot::bank().
 *
 * A self-check proves that the scanner finds a needle that is there, so the scans cannot pass vacuously.
 */

/**
 * The files below a directory (PHP files only) whose text contains the needle, as paths relative to the root.
 *
 * @return list<string>
 */
function accountLookupFilesNaming(string $root, string $directory, string $needle): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
            $found[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
        }
    }

    sort($found);

    return $found;
}

it('finds firstForCurrency( in its owner, so the scans cannot pass vacuously', function (): void {
    expect(accountLookupFilesNaming(dirname(__DIR__, 2), 'app', 'firstForCurrency('))->toContain('app/Domain/Settings/Settings/BankAccountSettings.php');
});

it('reports a file that names a needle, so the scans can fail', function (): void {
    $root = sys_get_temp_dir().'/'.implode('-', ['kokpit', 'account', bin2hex(random_bytes(4))]);
    mkdir($root.'/app/Elsewhere', 0777, true);
    file_put_contents($root.'/app/Elsewhere/Sneaky.php', "<?php\n// \$settings->".'for'."Currency('CZK')\n");
    file_put_contents($root.'/app/Elsewhere/Innocent.php', "<?php\n// \$settings->firstForCurrency('CZK')\n");

    try {
        expect(accountLookupFilesNaming($root, 'app', 'for'.'Currency('))->toBe(['app/Elsewhere/Sneaky.php']);
    } finally {
        unlink($root.'/app/Elsewhere/Sneaky.php');
        unlink($root.'/app/Elsewhere/Innocent.php');
        rmdir($root.'/app/Elsewhere');
        rmdir($root.'/app');
        rmdir($root);
    }
});

it('calls the deleted lookup BankAccountSettings::forCurrency() in no application file', function (): void {
    expect(accountLookupFilesNaming(dirname(__DIR__, 2), 'app', 'for'.'Currency('))->toBe([]);
});

it('calls firstForCurrency( only in the settings class and in DocumentPayment, the one preselection', function (): void {
    expect(accountLookupFilesNaming(dirname(__DIR__, 2), 'app', 'firstForCurrency('))->toBe([
        'app/Domain/Invoicing/Support/DocumentPayment.php',
        'app/Domain/Settings/Settings/BankAccountSettings.php',
    ]);
});

it('has neither the deleted lookup of the settings nor the currency-based bank snapshot', function (): void {
    expect(method_exists(BankAccountSettings::class, 'for'.'Currency'))->toBeFalse()
        ->and(method_exists(InvoiceSnapshot::class, 'bank'))->toBeFalse();
});
