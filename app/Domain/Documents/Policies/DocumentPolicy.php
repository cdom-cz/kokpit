<?php

declare(strict_types=1);

namespace App\Domain\Documents\Policies;

use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\KokpitPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Files are managed by the Admin, who passes through KokpitPolicy::before(). A Partner may list documents
 * (the scoped query decides which rows exist for them) and read a shared file (D-03, D-04); every other
 * ability is denied until an explicit grant arrives (the own upload and delete rights, plan 09-10).
 *
 * `attach` is declared and denies on purpose: the Gate skips `before()` for an ability the policy has no
 * method for, so without it the Admin would be refused too. Its second argument is the owner the file
 * would hang on (null for an unattached file).
 */
final class DocumentPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * D-03 and D-04: the Document scope (`constrainForPartner`) is the rule, this method repeats it for a record
     * that reached the policy some other way. A Partner reads a file that is flagged visible, hangs on a project,
     * task or non-internal comment, and that the Partner-scoped query still returns: that last read is what
     * drops another client's file, an internal-comment file and a file of an archived owner (D-21).
     */
    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof Document || ! $record->visible_to_client || $user->client_id === null) {
            return false;
        }

        $owner = $record->owner_type === null ? null : DocumentOwnerType::tryFrom($record->owner_type);

        if ($owner === null || ! $owner->isShareable()) {
            return false;
        }

        return Document::query()->whereKey($record->getKey())->exists();
    }

    public function attach(User $user, ?Model $owner = null): bool
    {
        return false;
    }
}
