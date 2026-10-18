<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Shared\Auth\KokpitPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * A Partner may list lines and view a line of an issued document of the own client; every other ability keeps
 * the base denial. The Admin is admitted by KokpitPolicy::before(). The document is read through the
 * Partner-scoped relation, so a draft or a document of another client is null here (defence in depth next to the
 * query scope, IN-09).
 */
final class InvoiceItemPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof InvoiceItem || $user->client_id === null) {
            return false;
        }

        $invoice = $record->invoice;

        return $invoice !== null
            && $invoice->client_id === $user->client_id
            && $invoice->state === InvoiceState::Issued;
    }
}
