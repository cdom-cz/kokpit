<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Shared\Auth\KokpitPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * A Partner may list cash receipts and view a receipt of the own client; every write ability keeps the base denial,
 * so no Partner updates, deletes or creates a receipt. The Admin is admitted by KokpitPolicy::before(). The query scope
 * already hides everything else (draft documents, archived clients, other clients), so `view` is the defence in depth
 * for a single record that reached the policy some other way (D-13).
 */
final class CashReceiptPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof CashReceipt
            && $user->client_id !== null
            && $record->client_id === $user->client_id;
    }
}
