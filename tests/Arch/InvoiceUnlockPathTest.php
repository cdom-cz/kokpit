<?php

declare(strict_types=1);

/*
 * Two confinements of the lock of an issued document (plan 10.1-02; D-02, D-03, D-12; threat T-10.1-06).
 *
 * 1. Only UnlockInvoice sets the transaction-local setting that lets the database move an invoice from locked to open.
 *    The literal may appear in no other application file and in no migration other than the ones that create or
 *    re-create the row guard; tests may use it to prove the guard.
 *
 * 2. The locked state is written by the successful send of a document e-mail (SendInvoiceEmail), by the issue of a
 *    credit note (DocumentIssuer) and by the Admin's manual lock (LockInvoice, Phase 16 D-02: the owner-approved second
 *    way into the locked state, which writes no sent mark), and named by the model that casts it (Invoice). A new
 *    reader asks `Invoice::isLocked()`, a new writer is a decision of its own and has to extend the allowlist below
 *    with a reason.
 */

const INVOICE_UNLOCK_FLAG = 'kokpit.invoice_unlock';

const INVOICE_LOCKED_TOKEN = 'InvoiceLockState::Locked';

/**
 * The files below a directory (PHP files only) whose text contains the needle, as paths relative to the root.
 *
 * @return list<string>
 */
function invoiceLockFilesNaming(string $root, string $directory, string $needle): array
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

it('names the unlock setting in no application file other than UnlockInvoice', function (): void {
    expect(invoiceLockFilesNaming(dirname(__DIR__, 2), 'app', INVOICE_UNLOCK_FLAG))->toBe(['app/Domain/Invoicing/Actions/UnlockInvoice.php']);
});

it('names the unlock setting in no migration other than the ones that create or re-create the row guard', function (): void {
    // Phase 14 re-creates the same row-guard function (CREATE OR REPLACE) to open `payment_method`, so its migration
    // carries the unchanged unlock branch of that function; it sets nothing.
    expect(invoiceLockFilesNaming(dirname(__DIR__, 2), 'database/migrations', INVOICE_UNLOCK_FLAG))->toBe([
        'database/migrations/2026_10_22_000100_add_lock_state_to_invoices.php',
        'database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php',
    ]);
});

it('writes the locked state only in the manual lock, the send job, the issue of a credit note and the model that casts it', function (): void {
    expect(invoiceLockFilesNaming(dirname(__DIR__, 2), 'app', INVOICE_LOCKED_TOKEN))->toBe([
        'app/Domain/Invoicing/Actions/LockInvoice.php',
        'app/Domain/Invoicing/Issuing/DocumentIssuer.php',
        'app/Domain/Invoicing/Jobs/SendInvoiceEmail.php',
        'app/Domain/Invoicing/Models/Invoice.php',
    ]);
});

it('reports a file that names a needle, so the scans can fail', function (): void {
    $root = sys_get_temp_dir().'/'.implode('-', ['kokpit', 'lock', bin2hex(random_bytes(4))]);
    mkdir($root.'/app/Elsewhere', 0777, true);
    file_put_contents($root.'/app/Elsewhere/Sneaky.php', "<?php\n// set_config('".INVOICE_UNLOCK_FLAG."', 'x', true)\n// ".INVOICE_LOCKED_TOKEN."\n");
    file_put_contents($root.'/app/Elsewhere/Innocent.php', "<?php\n// nothing to see\n");

    try {
        expect(invoiceLockFilesNaming($root, 'app', INVOICE_UNLOCK_FLAG))->toBe(['app/Elsewhere/Sneaky.php'])
            ->and(invoiceLockFilesNaming($root, 'app', INVOICE_LOCKED_TOKEN))->toBe(['app/Elsewhere/Sneaky.php']);
    } finally {
        unlink($root.'/app/Elsewhere/Sneaky.php');
        unlink($root.'/app/Elsewhere/Innocent.php');
        rmdir($root.'/app/Elsewhere');
        rmdir($root.'/app');
        rmdir($root);
    }
});
