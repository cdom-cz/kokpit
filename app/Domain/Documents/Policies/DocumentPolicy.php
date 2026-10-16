<?php

declare(strict_types=1);

namespace App\Domain\Documents\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\KokpitPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Files are managed by the Admin, who passes through KokpitPolicy::before(). A Partner may list documents
 * (the scoped query decides which rows exist for them) and is denied every other ability until an explicit
 * grant arrives: reading a shared file (plan 09-08) and the own upload and delete rights (plan 09-10).
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

    public function attach(User $user, ?Model $owner = null): bool
    {
        return false;
    }
}
