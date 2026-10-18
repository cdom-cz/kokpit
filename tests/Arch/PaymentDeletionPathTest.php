<?php

declare(strict_types=1);

/*
 * Only DeletePayment sets the transaction-local flag that lets a payment and its income be deleted (plan 10-41,
 * ruling R-41-5; plan 10-06 R-06-1). The literal may appear in no other application file and in no migration other
 * than the two guard migrations; tests may use it to prove the guards.
 */

const PAYMENT_DELETION_FLAG = 'kokpit.payment_deletion';

/**
 * The files below a directory (PHP files only) whose text contains the flag literal, as paths relative to the root.
 *
 * @return list<string>
 */
function paymentDeletionFilesNaming(string $root, string $directory): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), PAYMENT_DELETION_FLAG)) {
            $found[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
        }
    }

    sort($found);

    return $found;
}

it('names the deletion flag in no application file other than DeletePayment', function (): void {
    expect(paymentDeletionFilesNaming(dirname(__DIR__, 2), 'app'))->toBe(['app/Domain/Invoicing/Actions/DeletePayment.php']);
});

it('names the deletion flag in no migration other than the two guard migrations of plan 10-06', function (): void {
    expect(paymentDeletionFilesNaming(dirname(__DIR__, 2), 'database/migrations'))->toBe([
        'database/migrations/2026_10_17_000300_create_invoice_admin_tables.php',
        'database/migrations/2026_10_17_000400_link_transactions_to_invoices.php',
    ]);
});

it('reports a file that names the flag, so the check can fail', function (): void {
    $root = sys_get_temp_dir().'/'.implode('-', ['kokpit', 'flag', bin2hex(random_bytes(4))]);
    mkdir($root.'/app/Elsewhere', 0777, true);
    file_put_contents($root.'/app/Elsewhere/Sneaky.php', "<?php\n// set_config('".PAYMENT_DELETION_FLAG."', 'x', true)\n");
    file_put_contents($root.'/app/Elsewhere/Innocent.php', "<?php\n// nothing to see\n");

    try {
        expect(paymentDeletionFilesNaming($root, 'app'))->toBe(['app/Elsewhere/Sneaky.php']);
    } finally {
        unlink($root.'/app/Elsewhere/Sneaky.php');
        unlink($root.'/app/Elsewhere/Innocent.php');
        rmdir($root.'/app/Elsewhere');
        rmdir($root.'/app');
        rmdir($root);
    }
});
