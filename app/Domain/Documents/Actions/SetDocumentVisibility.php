<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\TaskComment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shows or hides a file for the client (D-02, D-03, D-20).
 *
 * The actor needs the `update` ability (the Admin; a Partner has no grant). Hiding is always allowed. Showing
 * is refused under 'visible_to_client' for a file that can never be shared: a client-owned file, an unattached
 * file and the file of an internal comment (and, with the finance plans, a transaction receipt). The UI offers
 * the switch only where this check passes, and the database CHECK `documents_visible_owner_check` is the
 * backstop for any other writer. The flag is logged through the model's activity allowlist.
 */
final class SetDocumentVisibility
{
    public function __construct(private readonly PartnerContext $context) {}

    /**
     * @throws ValidationException when the file is to be shown but cannot be shared
     */
    public function handle(User $actor, Document $document, bool $visible): Document
    {
        Gate::forUser($actor)->authorize('update', $document);

        return DB::transaction(function () use ($document, $visible): Document {
            $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($visible && ! $this->isShareable($locked)) {
                throw ValidationException::withMessages(['visible_to_client' => __('documents.errors.not_shareable')]);
            }

            // The flag is not fillable: it changes only here.
            $locked->forceFill(['visible_to_client' => $visible])->save();

            return $locked;
        });
    }

    /**
     * Whether the file can ever be shared with the client: it hangs on a project, a task or a comment that is
     * not internal. A comment that is already loaded on the document is used as it is, so a list that loads its
     * owners (the client tab) costs no query per row.
     */
    public function isShareable(Document $document): bool
    {
        $type = DocumentOwnerType::tryFrom((string) $document->owner_type);

        if ($type === null || ! $type->isShareable()) {
            return false;
        }

        if ($type !== DocumentOwnerType::TaskComment) {
            return true;
        }

        $loaded = $document->relationLoaded('owner') ? $document->getRelation('owner') : null;

        if ($loaded instanceof TaskComment) {
            return ! $loaded->is_internal;
        }

        return $this->context->runAsSystem(static fn (): bool => TaskComment::query()
            ->whereKey($document->owner_id)
            ->where('is_internal', false)
            ->exists());
    }
}
