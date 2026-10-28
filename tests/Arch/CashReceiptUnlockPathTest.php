<?php

declare(strict_types=1);

/*
 * Two confinements of the lock of a cash receipt (Phase 15, plan 15-13; D-10; threat T-15-30), the twin of
 * InvoiceUnlockPathTest.
 *
 * 1. Only UnlockCashReceipt sets the transaction-local setting that lets the database move a cash receipt from locked
 *    to open. The literal may appear in no other application file and in no migration other than the one that creates
 *    the row guard; tests may use it to prove the guard.
 *
 * 2. The locked state is written by the successful send of the payment confirmation (SendPaymentConfirmation) and by
 *    the Admin's manual lock (LockCashReceipt, Phase 16 D-02, the owner-approved second writer), and named by the model
 *    that casts it (CashReceipt). A new reader asks `CashReceipt::isLocked()`, a new writer is a decision of its own
 *    and has to extend the allowlist below with a reason.
 */

const CASH_RECEIPT_UNLOCK_FLAG = 'kokpit.cash_receipt_unlock';

const CASH_RECEIPT_LOCKED_TOKEN = 'CashReceiptLockState::Locked';

/**
 * The files below a directory (PHP files only) whose text contains the needle, as paths relative to the root.
 *
 * @return list<string>
 */
function cashReceiptLockFilesNaming(string $root, string $directory, string $needle): array
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

it('names the cash receipt unlock setting in no application file other than UnlockCashReceipt', function (): void {
    expect(cashReceiptLockFilesNaming(dirname(__DIR__, 2), 'app', CASH_RECEIPT_UNLOCK_FLAG))->toBe(['app/Domain/Invoicing/Actions/UnlockCashReceipt.php']);
});

it('names the cash receipt unlock setting in no migration other than the one that creates the row guard', function (): void {
    expect(cashReceiptLockFilesNaming(dirname(__DIR__, 2), 'database/migrations', CASH_RECEIPT_UNLOCK_FLAG))->toBe([
        'database/migrations/2026_10_27_000200_create_cash_receipts_table.php',
    ]);
});

it('writes the locked state of a cash receipt only in the confirmation send job, the manual lock and the model that casts it', function (): void {
    expect(cashReceiptLockFilesNaming(dirname(__DIR__, 2), 'app', CASH_RECEIPT_LOCKED_TOKEN))->toBe([
        'app/Domain/Invoicing/Actions/LockCashReceipt.php',
        'app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php',
        'app/Domain/Invoicing/Models/CashReceipt.php',
    ]);
});

it('reports a file that names a cash receipt needle, so the scans can fail', function (): void {
    $root = sys_get_temp_dir().'/'.implode('-', ['kokpit', 'cash', 'lock', bin2hex(random_bytes(4))]);
    mkdir($root.'/app/Elsewhere', 0777, true);
    file_put_contents($root.'/app/Elsewhere/Sneaky.php', "<?php\n// set_config('".CASH_RECEIPT_UNLOCK_FLAG."', 'x', true)\n// ".CASH_RECEIPT_LOCKED_TOKEN."\n");
    file_put_contents($root.'/app/Elsewhere/Innocent.php', "<?php\n// nothing to see\n");

    try {
        expect(cashReceiptLockFilesNaming($root, 'app', CASH_RECEIPT_UNLOCK_FLAG))->toBe(['app/Elsewhere/Sneaky.php'])
            ->and(cashReceiptLockFilesNaming($root, 'app', CASH_RECEIPT_LOCKED_TOKEN))->toBe(['app/Elsewhere/Sneaky.php']);
    } finally {
        unlink($root.'/app/Elsewhere/Sneaky.php');
        unlink($root.'/app/Elsewhere/Innocent.php');
        rmdir($root.'/app/Elsewhere');
        rmdir($root.'/app');
        rmdir($root);
    }
});
