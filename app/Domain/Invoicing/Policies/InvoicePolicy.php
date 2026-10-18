<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\KokpitPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * A Partner may list documents and view an issued document of the own client; every write ability keeps the base
 * denial. The Admin is admitted by KokpitPolicy::before(). The query scope already hides everything else, so
 * `view` is the defence in depth for a single record that reached the policy some other way (IN-09).
 */
final class InvoicePolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof Invoice
            && $user->client_id !== null
            && $record->client_id === $user->client_id
            && $record->state === InvoiceState::Issued;
    }
}
